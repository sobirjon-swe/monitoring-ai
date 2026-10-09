<?php

namespace App\Domain\Ba;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class BaReadService
{
    public function queue(TrustedActor $actor, string $project, array $query = []): array
    {
        return $this->read($actor, $project, null, $query);
    }

    public function history(TrustedActor $actor, string $project, string $clarification, array $query = []): array
    {
        if (! Str::isUuid($clarification)) {
            throw new InvalidArgumentException;
        }

        return $this->read($actor, $project, $clarification, $query);
    }

    private function read(TrustedActor $actor, string $project, ?string $root, array $query): array
    {
        if (! Str::isUuid($project)) {
            throw new InvalidArgumentException;
        }
        [$limit, $department, $cursor] = $this->query($query);
        $purposes = ['ba.read', 'ba.source.read'];
        if ($department !== null) {
            $purposes[] = 'ba.department.read';
        }
        // Even an empty page requires current project and source-purpose authority.
        $state = app(BaCoordination::class)->run($actor, $project, $purposes, false, fn () => DB::table('lifecycle_project_state')->where('project_id', $project)->first(), department: $department);
        if ($root === null && $state?->is_closed) {
            return $this->page([], null, 'unavailable', ['project_closed']);
        }
        $scope = ['tenant' => $actor->tenantId, 'actor' => $actor->id, 'project' => $project, 'resource' => $root === null ? 'queue' : 'history:'.$root, 'department' => $department, 'limit' => $limit];
        $position = $cursor === null ? [] : app(BaPagination::class)->decode($cursor, $scope);
        if ($position && (($position['generation'] ?? null) !== $state?->generation || ($root !== null && ! is_int($position['revision'] ?? null)))) {
            throw new ConflictHttpException;
        }
        $originalUnavailable = $this->observeOriginals($actor, $project, $root, $department, $limit, $purposes);
        $selection = app(TenantContext::class)->run($actor->tenantId, function () use ($project, $root, $department, $limit, $position) {
            $visible = $this->visibleRoots($project, $department);
            $visible->whereRaw($this->mandatoryAuthoritySql());
            if ($root !== null) {
                $visible->where('c.id', $root);
            }
            if ($root === null) {
                $this->continueRoots($visible, $position);
                $rows = $visible->orderBy('c.object_id')->orderBy('c.id')->limit($limit)->get(['c.id', 'c.object_id', 'c.revision', 'c.current_request_id']);
                $events = [];
            } else {
                $rows = $visible->limit(1)->get(['c.id', 'c.object_id', 'c.revision', 'c.current_request_id']);
                $events = $rows->isEmpty() ? [] : $this->visibleEvents($project, $root, $department)->where('e.revision', '>', $position['revision'] ?? 0)->orderBy('e.revision')->limit($limit)->get(['e.*'])->all();
            }
            $withheld = $root !== null && $rows->isNotEmpty() && DB::table('ba_events')->where('project_id', $project)->where('clarification_id', $root)->where('revision', '>', $position['revision'] ?? 0)->whereNotIn('id', $this->visibleEvents($project, $root, $department)->select('e.id'))->exists();

            return ['roots' => $rows, 'events' => $events, 'withheld' => $withheld];
        });
        $refs = ['clarification' => $selection['roots']->pluck('id')->all()];
        foreach ($selection['events'] as $event) {
            $refs['event'][] = $event->id;
            foreach (['request' => $event->request_version_id, 'answer' => $event->subject_answer_version_id ?? $event->answer_version_id, 'review' => $event->review_id] as $kind => $id) {
                if ($id !== null) {
                    $refs[$kind][] = $id;
                }
            }
            if ($event->answer_version_id !== null) {
                $refs['answer'][] = $event->answer_version_id;
            }
        }
        foreach ($refs as &$ids) {
            $ids = array_values(array_unique($ids));
        }
        unset($ids);
        $historyLinks = $root === null ? [] : app(TenantContext::class)->run($actor->tenantId, fn () => DB::table('ba_request_links')->where('project_id', $project)->whereIn('request_version_id', $refs['request'] ?? [])->whereNotIn('request_version_id', $selection['roots']->pluck('current_request_id')->all())->orderBy('id')->limit(1001)->get()->map(fn ($row) => (array) $row)->all());
        $inputs = array_map(fn ($link) => array_intersect_key($link, array_flip(['task_version_id', 'criterion_id', 'criterion_version_id', 'relation'])), $historyLinks);
        $graph = app(BaPins::class)->collect($actor, $project, $refs, linkInputs: $inputs, prospectiveRequest: false);
        if ($root !== null && $graph['coverage'] === 'complete') {
            $graph = $this->historyResponsibilityGraph($actor, $project, $graph, $historyLinks, $selection['events']);
        }
        $names = $this->names($actor, $project, $graph);
        $subjects = array_values(array_unique(array_merge([], ...array_values($names))));

        return app(BaCoordination::class)->run($actor, $project, $purposes, false, function ($proofs) use ($project, $root, $department, $limit, $scope, $position, $selection, $graph, $names, $originalUnavailable) {
            $deliveryState = DB::table('lifecycle_project_state')->where('project_id', $project)->first();
            if ($root === null && $deliveryState?->is_closed) {
                return $this->page([], null, 'unavailable', ['project_closed']);
            }
            if ($position && $position['generation'] !== $deliveryState?->generation) {
                throw new ConflictHttpException;
            }
            if ($graph['coverage'] !== 'complete' || in_array('coverage_limit', array_column($proofs, 'reason_code'), true)) {
                return $this->page([], null, 'unknown', ['coverage_limit']);
            }
            $loaded = app(BaPins::class)->loadLocked($project, $graph);
            $graph['rows'] = $loaded['rows'];
            $graph['terminal'] = app(BaTerminal::class)->overlayLocked($project, $graph);
            $currentIds = $this->visibleRoots($project, $department)->whereRaw($this->mandatoryAuthoritySql())->whereIn('c.id', $selection['roots']->pluck('id')->all())->pluck('c.id')->all();
            if (array_diff($selection['roots']->pluck('id')->all(), $currentIds)) {
                throw new ConflictHttpException;
            }
            $evaluation = app(BaEligibility::class)->evaluate($graph, $proofs);
            $keys = [];
            $items = [];
            $unknown = $selection['withheld'];
            $now = DB::scalar('select clock_timestamp()');
            foreach ($selection['roots'] as $selected) {
                $record = $graph['rows']['clarification'][$selected->id];
                $facts = ['request:'.$record->current_request_id];
                foreach (['answer' => $record->current_answer_id, 'review' => $record->current_review_id] as $kind => $id) {
                    if ($id !== null) {
                        $facts[] = $kind.':'.$id;
                    }
                }
                if (! $this->safeFacts($project, $graph, $proofs, $facts, $keys, true)) {
                    $unknown = true;

                    continue;
                }
                $status = $evaluation[$record->id];
                foreach ($status['required_proof_keys'] as $key) {
                    if (! ($proofs[$key]['valid'] ?? false)) {
                        $unknown = true;

                        continue 2;
                    }
                    $keys[] = $key;
                }
                // Scope provenance never clears the obligation. Withhold unsafe origin identity.
                $originSafe = true;
                foreach ($status['origin_proof_keys'] as $key) {
                    if ($proofs[$key]['valid'] ?? false) {
                        $keys[] = $key;
                    } else {
                        $originSafe = false;
                    }
                }
                if ($root === null) {
                    $request = $graph['rows']['request'][$record->current_request_id];
                    $answer = $graph['rows']['answer'][$record->current_answer_id] ?? null;
                    $age = app(BaResponsibility::class)->age(['requested_at' => $request->requested_at, 'response_at' => $answer?->response_at], $now);
                    $deliveryStatus = array_diff_key($status, array_flip(['required_proof_keys', 'origin_proof_keys', 'source_heads']));
                    $responsibilities = $this->responsibilities($graph['responsibility_slots'], $proofs, $keys, $request->id);
                    $items[] = ['clarification_id' => $record->id, 'revision' => $record->revision, 'question' => $request->question, 'requester_id' => $request->requester_id, 'coordinator_id' => $request->coordinator_id, 'decision_owner_id' => $request->decision_owner_id, 'decision_owner_party_version_id' => $request->decision_owner_party_version_id, 'requested_at' => $request->requested_at, 'original_requested_at' => $record->original_requested_at, 'request_hash' => $request->request_hash, 'links_hash' => $request->links_hash, 'answer_hash' => $answer?->answer_hash, 'answer' => $answer?->answer, 'response_at' => $answer?->response_at, 'request_source' => $this->sourceDto($graph, $request->receipt_id), 'answer_source' => $answer === null ? null : $this->sourceDto($graph, $answer->receipt_id), 'elapsed_age' => $age['elapsed_seconds'], 'waiting_on' => $this->waiting($status, $request), 'scope_origin_coverage' => $originSafe ? 'complete' : 'unknown', 'links' => $this->linksDto($graph, $request->id), 'responsibilities' => $responsibilities] + $deliveryStatus + $age + $this->labels();
                } else {
                    foreach ($selection['events'] as $event) {
                        $facts = ['event:'.$event->id, 'request:'.$event->request_version_id];
                        $subject = $event->subject_answer_version_id ?? $event->answer_version_id;
                        if ($subject !== null) {
                            $facts[] = 'answer:'.$subject;
                        }
                        if ($event->answer_version_id !== null) {
                            $facts[] = 'answer:'.$event->answer_version_id;
                        }
                        if ($event->review_id !== null) {
                            $facts[] = 'review:'.$event->review_id;
                        }
                        $eventKeys = [];
                        if (! $this->safeFacts($project, $graph, $proofs, $facts, $eventKeys, false)) {
                            $unknown = true;

                            continue;
                        }
                        $keys = [...$keys, ...$eventKeys];
                        $items[] = ['clarification_id' => $record->id, 'id' => $event->id, 'revision' => $event->revision, 'kind' => $event->kind, 'state' => $event->state, 'request_version_id' => $event->request_version_id, 'answer_version_id' => $event->answer_version_id, 'subject_answer_version_id' => $subject, 'review_id' => $event->review_id, 'context' => $event->context, 'reason' => $event->reason, 'request' => $this->factDto($graph['rows']['request'][$event->request_version_id], 'request'), 'answer' => $subject === null ? null : $this->factDto($graph['rows']['answer'][$subject], 'answer'), 'links' => $this->linksDto($graph, $event->request_version_id), 'responsibilities' => $this->responsibilities($graph['history_responsibility_slots'][$event->id] ?? [], $proofs, $keys, $event->request_version_id)] + $this->labels();
                    }
                }
            }
            app(BaAuthority::class)->requireDeliveryLocked(array_values(array_unique($keys)));
            $items = app(BaDepartmentView::class)->filterLocked($items, $proofs, $names);
            $next = null;
            // A full visible page carries a continuation independent of hidden rows.
            // The next authorized request may return a complete empty page.
            if (! $unknown && count($items) === $limit) {
                $last = $root === null ? $selection['roots']->last() : end($selection['events']);
                if ($root === null) {
                    $after = ['object_id' => $last->object_id, 'id' => $last->id];
                } else {
                    $after = ['revision' => $last->revision];
                }
                $after['generation'] = DB::table('lifecycle_project_state')->where('project_id', $project)->value('generation');
                $next = app(BaPagination::class)->encode($scope, $after);
            }

            $unknown = $unknown || (! $items && $originalUnavailable);

            return $this->page($items, $next, $unknown ? 'unknown' : 'complete', $unknown ? ['current_evaluation_unavailable'] : []);
        }, $graph['requests'], $department, $subjects);
    }

    private function safeFacts(string $project, array $graph, array $proofs, array $facts, array &$keys, bool $observe): bool
    {
        $safe = true;
        foreach ($facts as $ref) {
            [$kind, $id] = explode(':', $ref, 2);
            $row = $graph['rows'][$kind][$id] ?? null;
            $key = $graph['proof_keys'][$ref] ?? null;
            if (! $row || ! ($proofs[$key]['valid'] ?? false)) {
                $reason = $proofs[$key]['reason_code'] ?? null;
                if ($observe && $row && in_array($reason, ['access_revoked', 'source_revoked', 'role_revoked'], true)) {
                    app(BaTerminal::class)->observeLocked($project, $kind, $id, hash('sha256', $row->authority_proof), $reason);
                }
                $safe = false;

                continue;
            }
            foreach ($graph['terminal'] as $terminal) {
                if ($terminal['subject_id'] === $id || $terminal['subject_id'] === ($row->receipt_id ?? null)) {
                    $safe = false;
                }
            }
            $keys[] = $key;
        }

        return $safe;
    }

    private function observeOriginals(TrustedActor $actor, string $project, ?string $root, ?string $department, int $limit, array $purposes): bool
    {
        // This is a separate admitted graph, never merged into the delivery page.
        $ids = app(TenantContext::class)->run($actor->tenantId, function () use ($project, $root, $department, $limit) {
            $query = $this->visibleRoots($project, $department)->whereRaw('NOT ('.$this->mandatoryAuthoritySql().')');
            if ($root !== null) {
                $query->where('c.id', $root);
            }

            return $query->orderBy('c.object_id')->orderBy('c.id')->limit($limit)->select('c.id')->selectRaw($this->supportedMandatorySql().' AS authority_supported')->get()->all();
        });
        if (! $ids) {
            return false;
        }
        $ids = array_column(array_filter($ids, fn ($row) => $row->authority_supported), 'id');
        if (! $ids) {
            return true;
        }
        $graph = app(BaPins::class)->collect($actor, $project, $ids, prospectiveRequest: false);
        $names = $this->names($actor, $project, $graph);
        $subjects = array_values(array_unique(array_merge([], ...array_values($names))));

        return app(BaCoordination::class)->run($actor, $project, $purposes, false, function ($proofs) use ($project, $graph, $ids, $department) {
            if ($graph['coverage'] !== 'complete' || in_array('coverage_limit', array_column($proofs, 'reason_code'), true)) {
                return true;
            }
            $graph['rows'] = app(BaPins::class)->loadLocked($project, $graph)['rows'];
            $graph['terminal'] = app(BaTerminal::class)->overlayLocked($project, $graph);
            $visible = $this->visibleRoots($project, $department)->whereIn('c.id', $ids)->pluck('c.id')->all();
            if (array_diff($ids, $visible)) {
                throw new ConflictHttpException;
            }
            $keys = [];
            foreach ($graph['rows']['clarification'] ?? [] as $record) {
                $facts = ['request:'.$record->current_request_id];
                foreach (['answer' => $record->current_answer_id, 'review' => $record->current_review_id] as $kind => $id) {
                    if ($id !== null) {
                        $facts[] = $kind.':'.$id;
                    }
                }
                $this->safeFacts($project, $graph, $proofs, $facts, $keys, true);
            }
            app(BaAuthority::class)->requireDeliveryLocked(array_values(array_unique($keys)));

            return true;
        }, $graph['requests'], $department, $subjects);
    }

    private function mandatoryAuthoritySql(): string
    {
        $proofs = 'SELECT r.authority_proof AS proof UNION ALL SELECT a.authority_proof FROM ba_answer_versions a WHERE a.id=c.current_answer_id UNION ALL SELECT v.authority_proof FROM ba_reviews v WHERE v.id=c.current_review_id';
        $valid = $this->proofValiditySql('p.proof');
        $subjects = 'SELECT r.id AS id UNION SELECT r.receipt_id UNION SELECT a.id FROM ba_answer_versions a WHERE a.id=c.current_answer_id UNION SELECT a.receipt_id FROM ba_answer_versions a WHERE a.id=c.current_answer_id UNION SELECT v.id FROM ba_reviews v WHERE v.id=c.current_review_id UNION SELECT v.receipt_id FROM ba_reviews v WHERE v.id=c.current_review_id';

        return "NOT EXISTS (SELECT 1 FROM ($proofs) p WHERE NOT ($valid)) AND NOT EXISTS (SELECT 1 FROM ba_terminal_applicability t WHERE t.project_id=c.project_id AND t.subject_id IN ($subjects))";
    }

    private function proofValiditySql(string $proof): string
    {
        $member = $this->pinSql('m', "$proof->'membership'");
        $grant = $this->pinSql('g', 'pin.value', ['policy_version']);
        $role = $this->pinSql('v', 'pin.value');
        $subject = $this->pinSql('m', "pin.value->'membership'");
        $coordinatorRole = $this->pinSql('v', 'role_pin.value');

        // Equality uses immutable IDs/revisions and exact interval endpoints. A newer
        // active replacement is never evidence for the original captured authority.
        $supported = $this->supportedProofSql($proof);
        $actor = $this->typedSql("$proof->>'actor_id'", 'uuid');
        $employee = $this->typedSql("$proof->>'employee_id'", 'uuid');
        $pinEmployee = $this->typedSql("pin.value->>'employee_id'", 'uuid');
        $policyVersion = $this->typedSql("pin.value->>'policy_version'", 'integer');
        $source = $this->typedSql("pin.value->>'id'", 'uuid');
        $connector = $this->typedSql("pin.value->>'connector_id'", 'uuid');
        $connectorRevision = $this->typedSql("pin.value->>'connector_revision'", 'integer');

        return "CASE WHEN ($supported) THEN EXISTS (SELECT 1 FROM users u WHERE u.id=$actor AND u.employee_id=$employee AND u.status='active')
        AND EXISTS (SELECT 1 FROM project_memberships m WHERE m.project_id=c.project_id AND m.employee_id=$employee AND $member)
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(coalesce($proof->'grants','[]'::jsonb)) pin(value) WHERE NOT EXISTS (SELECT 1 FROM access_grants g JOIN policy_versions policy ON policy.id=g.policy_version_id WHERE g.user_id=$actor AND g.project_id=c.project_id AND g.scope_type='project' AND g.revoked_at IS NULL AND policy.status='active' AND policy.version=$policyVersion AND $grant))
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(coalesce($proof->'roles','[]'::jsonb)) pin(value) WHERE NOT EXISTS (SELECT 1 FROM role_assignments v WHERE v.project_id=c.project_id AND v.employee_id=$employee AND $role))
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(coalesce($proof->'request_subjects','[]'::jsonb)) pin(value) WHERE NOT EXISTS (SELECT 1 FROM project_memberships m WHERE m.project_id=c.project_id AND m.employee_id=$pinEmployee AND $subject))
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(coalesce($proof->'coordinators','[]'::jsonb)) pin(value) WHERE NOT EXISTS (SELECT 1 FROM project_memberships m WHERE m.project_id=c.project_id AND m.employee_id=$pinEmployee AND $subject) OR EXISTS (SELECT 1 FROM jsonb_array_elements(pin.value->'roles') role_pin(value) WHERE NOT EXISTS (SELECT 1 FROM role_assignments v WHERE v.project_id=c.project_id AND v.employee_id=$pinEmployee AND $coordinatorRole)))
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(coalesce($proof->'sources','[]'::jsonb)) pin(value) WHERE NOT EXISTS (SELECT 1 FROM source_instances s JOIN ingestion_connectors k ON k.source_instance_id=s.id AND k.project_id=c.project_id WHERE s.id=$source AND s.kind=pin.value->>'kind' AND s.kind='manual' AND k.id=$connector AND k.revision=$connectorRevision AND k.adapter=pin.value->>'adapter' AND k.adapter='synthetic-v1' AND k.status=pin.value->>'status' AND k.status='active')) ELSE false END";
    }

    private function supportedMandatorySql(): string
    {
        $proofs = 'SELECT r.authority_proof AS proof UNION ALL SELECT a.authority_proof FROM ba_answer_versions a WHERE a.id=c.current_answer_id UNION ALL SELECT v.authority_proof FROM ba_reviews v WHERE v.id=c.current_review_id';

        return 'NOT EXISTS (SELECT 1 FROM ('.$proofs.') p WHERE NOT ('.$this->supportedProofSql('p.proof').'))';
    }

    private function supportedProofSql(string $proof): string
    {
        $member = $this->pinShapeSql("$proof->'membership'");
        $pin = $this->pinShapeSql('pin.value');
        $subject = $this->pinShapeSql("pin.value->'membership'");
        $role = $this->pinShapeSql('role_pin.value');

        // Unsupported legacy metadata is withheld as unknown; it never becomes a
        // cast error or a fabricated observation of revoked authority.
        return "CASE WHEN jsonb_typeof($proof)='object' AND $proof->>'version'='ba-authority-v1'
        AND pg_input_is_valid($proof->>'actor_id','uuid') AND pg_input_is_valid($proof->>'employee_id','uuid')
        AND jsonb_typeof($proof->'roles')='array' AND jsonb_typeof($proof->'grants')='array' AND jsonb_typeof($proof->'sources')='array'
        AND jsonb_typeof(coalesce($proof->'coordinators','[]'::jsonb))='array' AND jsonb_typeof(coalesce($proof->'request_subjects','[]'::jsonb))='array'
        THEN coalesce(($member),false)
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements($proof->'roles') pin(value) WHERE NOT coalesce(($pin),false))
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements($proof->'grants') pin(value) WHERE NOT coalesce(($pin) AND pg_input_is_valid(pin.value->>'policy_version','integer'),false))
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(coalesce($proof->'request_subjects','[]'::jsonb)) pin(value) WHERE NOT coalesce(($subject) AND pg_input_is_valid(pin.value->>'employee_id','uuid'),false))
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(coalesce($proof->'coordinators','[]'::jsonb)) pin(value) WHERE CASE WHEN jsonb_typeof(pin.value->'roles')='array' THEN NOT coalesce(($subject) AND pg_input_is_valid(pin.value->>'employee_id','uuid'),false) OR EXISTS (SELECT 1 FROM jsonb_array_elements(pin.value->'roles') role_pin(value) WHERE NOT coalesce(($role),false)) ELSE true END)
        AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements($proof->'sources') pin(value) WHERE NOT coalesce(pg_input_is_valid(pin.value->>'id','uuid') AND pg_input_is_valid(pin.value->>'connector_id','uuid') AND pg_input_is_valid(pin.value->>'connector_revision','integer'),false))
        ELSE false END";
    }

    private function pinShapeSql(string $pin): string
    {
        return "jsonb_typeof($pin)='object' AND pg_input_is_valid($pin->>'id','uuid') AND pg_input_is_valid($pin->>'revision','integer') AND pg_input_is_valid($pin->>'valid_from','timestamp with time zone') AND jsonb_exists($pin,'valid_to') AND (jsonb_typeof($pin->'valid_to')='null' OR pg_input_is_valid($pin->>'valid_to','timestamp with time zone'))";
    }

    private function pinSql(string $row, string $pin, array $extra = []): string
    {
        $remove = implode('', array_map(fn ($field) => " - '$field'", ['valid_from', 'valid_to', ...$extra]));
        $from = $this->typedSql("$pin->>'valid_from'", 'timestamp with time zone');
        $to = $this->typedSql("$pin->>'valid_to'", 'timestamp with time zone');

        return "to_jsonb($row) @> (($pin)$remove) AND $row.valid_from=$from AND $row.valid_to IS NOT DISTINCT FROM $to AND $row.valid_from<=clock_timestamp() AND ($row.valid_to IS NULL OR $row.valid_to>clock_timestamp())";
    }

    private function typedSql(string $value, string $type): string
    {
        // Cast the CASE result, not a branch literal: PostgreSQL may fold constants
        // before evaluating an enclosing CASE guard during query planning.
        return "(CASE WHEN pg_input_is_valid($value,'$type') THEN $value ELSE NULL END)::$type";
    }

    private function names(TrustedActor $actor, string $project, array $graph): array
    {
        return app(TenantContext::class)->run($actor->tenantId, function () use ($project, $graph) {
            $result = [];
            foreach (DB::table('ba_request_versions')->where('project_id', $project)->whereIn('id', $graph['refs']['request'] ?? [])->get() as $request) {
                $proof = json_decode($request->authority_proof, true);
                $result[$request->clarification_id] = [...($result[$request->clarification_id] ?? []), ...array_filter([$request->requester_id, $request->coordinator_id, $request->decision_owner_id, $proof['employee_id']])];
            }
            foreach (['answer', 'review', 'event'] as $kind) {
                foreach (DB::table(BaPins::TABLES[$kind])->where('project_id', $project)->whereIn('id', $graph['refs'][$kind] ?? [])->get() as $row) {
                    $result[$row->clarification_id][] = json_decode($row->authority_proof, true)['employee_id'];
                }
            }
            foreach ([...$graph['link_slots'], ...($graph['history_links'] ?? [])] as $slot) {
                foreach ($graph['links'] as $link) {
                    if ($link['task_version_id'] === $slot['task_version_id']) {
                        $result[$slot['clarification_id']][] = $link['assignee_id'];
                    }
                }
            }
            foreach ($result as &$ids) {
                $ids = array_values(array_unique(array_filter($ids)));
            }

            return $result;
        });
    }

    private function historyResponsibilityGraph(TrustedActor $actor, string $project, array $graph, array $oldLinks, array $events): array
    {
        $graph['history_links'] = $oldLinks;
        $allLinks = [...$graph['link_slots'], ...$oldLinks];
        $facts = app(TenantContext::class)->run($actor->tenantId, fn () => ['request' => DB::table('ba_request_versions')->where('project_id', $project)->whereIn('id', $graph['refs']['request'] ?? [])->get()->keyBy('id')->all(), 'answer' => DB::table('ba_answer_versions')->where('project_id', $project)->whereIn('id', $graph['refs']['answer'] ?? [])->get()->keyBy('id')->all()]);
        $extra = [];
        $slots = [];
        $linkCount = 0;
        foreach ($events as $event) {
            $request = $facts['request'][$event->request_version_id];
            $answerId = $event->subject_answer_version_id ?? $event->answer_version_id;
            $answer = $facts['answer'][$answerId] ?? null;
            foreach ($allLinks as $link) {
                if ($link['request_version_id'] !== $request->id) {
                    continue;
                }
                $linkCount++;
                foreach (['request' => [$request, $request->requested_at], 'answer' => [$answer, $answer?->response_at]] as $phase => [$fact, $time]) {
                    if ($fact === null) {
                        continue;
                    }
                    $slot = $link + ['phase' => $phase, 'occurred_at' => null, 'proof_key' => null];
                    if ($time !== null) {
                        $at = CarbonImmutable::parse($time)->utc()->format('Y-m-d\TH:i:s.u\Z');
                        $key = 'responsibility:'.hash('sha256', BaContract::canonical([$phase, $fact->id, $link['task_version_id'], $link['criterion_id'], $link['criterion_version_id']]));
                        $proof = json_decode($fact->authority_proof, true, 32, JSON_THROW_ON_ERROR);
                        $extra[$key] = ['key' => $key, 'actor_id' => $proof['actor_id'], 'purposes' => $proof['purposes'], 'required' => false, 'pinned_proof' => $proof, 'responsibility_refs' => [['task_version_id' => $link['task_version_id'], 'occurred_at' => $at]], 'root_refs' => array_keys($graph['heads']['clarification'])];
                        $slot['occurred_at'] = $at;
                        $slot['proof_key'] = $key;
                    }
                    $slots[$event->id][] = $slot;
                }
            }
        }
        $R = $graph['categories']['roots'];
        $L = max($graph['categories']['links'], $linkCount);
        $P = $graph['categories']['proofs'] + count($extra);
        if ($L > 20 * $R || $L > 1000 || $P > 6 * $R + 2 * $L || $P > 2300) {
            $graph['coverage'] = 'unknown';
            $graph['reason_code'] = 'coverage_limit';
            $graph['requests'] = [];

            return $graph;
        }
        $graph['requests'] = [...$graph['requests'], ...array_values($extra)];
        $graph['history_responsibility_slots'] = $slots;
        $graph['categories']['links'] = $L;
        $graph['categories']['proofs'] = $P;

        return $graph;
    }

    private function responsibilities(array $slots, array $proofs, array &$keys, string $request): array
    {
        $items = [];
        foreach ($slots as $slot) {
            if ($slot['request_version_id'] !== $request) {
                continue;
            }
            $key = $slot['proof_key'];
            if ($key !== null && ($proofs[$key]['valid'] ?? false)) {
                $keys[] = $key;
            }
            $value = $slot['occurred_at'] === null ? ['attribution' => 'unknown', 'employee_id' => null, 'reason_code' => 'time_unknown', 'task_version_id' => $slot['task_version_id'], 'occurred_at' => null] : app(BaResponsibility::class)->captureLocked($slot, $slot['occurred_at'], [$proofs[$key] ?? []]);
            $items[] = array_intersect_key($value, array_flip(['attribution', 'employee_id', 'reason_code', 'task_version_id', 'occurred_at', 'effective_until'])) + ['phase' => $slot['phase']];
        }

        return $items;
    }

    private function linksDto(array $graph, string $request): array
    {
        return array_values(array_map(fn ($link) => array_intersect_key($link, array_flip(['task_version_id', 'criterion_id', 'criterion_version_id', 'relation', 'requirement_version_id', 'requirement_hash'])), array_filter([...$graph['link_slots'], ...($graph['history_links'] ?? [])], fn ($link) => $link['request_version_id'] === $request)));
    }

    private function visibleRoots(string $project, ?string $department): Builder
    {
        $sourceId = $this->typedSql("s->>'id'", 'uuid');
        $vEmployee = $this->typedSql("v.authority_proof->>'employee_id'", 'uuid');
        $aEmployee = $this->typedSql("a.authority_proof->>'employee_id'", 'uuid');
        $rEmployee = $this->typedSql("r.authority_proof->>'employee_id'", 'uuid');
        $query = DB::table('ba_clarifications as c')->join('ba_request_versions as r', 'r.id', '=', 'c.current_request_id')->where('c.project_id', $project);
        // Correlated fixed-shape unions inspect every link, including context, before LIMIT.
        $named = "SELECT r.requester_id AS employee_id UNION SELECT r.coordinator_id UNION SELECT r.decision_owner_id UNION SELECT $rEmployee UNION SELECT $aEmployee FROM ba_answer_versions a WHERE a.id=c.current_answer_id UNION SELECT $vEmployee FROM ba_reviews v WHERE v.id=c.current_review_id UNION SELECT $vEmployee FROM ba_scope_obligations o JOIN ba_reviews v ON v.id=o.origin_review_id WHERE o.clarification_id=c.id UNION SELECT t.assignee_id FROM ba_request_links l JOIN task_versions t ON t.id=l.task_version_id WHERE l.request_version_id=r.id";
        $query->whereRaw("NOT EXISTS (SELECT 1 FROM ($named) n WHERE n.employee_id IS NOT NULL AND (SELECT count(*) FROM project_memberships m WHERE m.project_id=c.project_id AND m.employee_id=n.employee_id AND m.valid_from<=clock_timestamp() AND (m.valid_to IS NULL OR m.valid_to>clock_timestamp()))<>1)");
        if ($department !== null) {
            $query->whereRaw("NOT EXISTS (SELECT 1 FROM ($named) n WHERE n.employee_id IS NOT NULL AND (SELECT count(*) FROM department_memberships m WHERE m.department_id=?::uuid AND m.employee_id=n.employee_id AND m.valid_from<=clock_timestamp() AND (m.valid_to IS NULL OR m.valid_to>clock_timestamp()))<>1)", [$department]);
        }
        $sources = "SELECT c.source_instance_id AS id UNION SELECT a.source_instance_id FROM ba_answer_versions a WHERE a.id=c.current_answer_id UNION SELECT p.source_instance_id FROM ba_source_party_versions p WHERE p.id=r.decision_owner_party_version_id OR p.id=(SELECT a.author_party_version_id FROM ba_answer_versions a WHERE a.id=c.current_answer_id) UNION SELECT $sourceId FROM ba_scope_obligations o JOIN ba_reviews v ON v.id=o.origin_review_id CROSS JOIN LATERAL jsonb_array_elements(v.authority_proof->'sources') s WHERE o.clarification_id=c.id";
        $query->whereRaw("NOT EXISTS (SELECT 1 FROM ($sources) x WHERE NOT EXISTS (SELECT 1 FROM source_instances s JOIN ingestion_connectors k ON k.source_instance_id=s.id AND k.project_id=c.project_id WHERE s.id=x.id AND s.kind='manual' AND k.status='active' AND k.adapter='synthetic-v1'))");

        return $query;
    }

    private function continueRoots(Builder $query, array $position): void
    {
        if ($position) {
            if (! is_string($position['object_id'] ?? null) || ! Str::isUuid($position['id'] ?? '')) {
                throw new InvalidArgumentException;
            }
            $query->where(fn ($q) => $q->where('c.object_id', '>', $position['object_id'])->orWhere(fn ($q) => $q->where('c.object_id', $position['object_id'])->where('c.id', '>', $position['id'])));
        }
    }

    private function visibleEvents(string $project, string $root, ?string $department): Builder
    {
        $sourceId = $this->typedSql("s->>'id'", 'uuid');
        $eEmployee = $this->typedSql("e.authority_proof->>'employee_id'", 'uuid');
        $vEmployee = $this->typedSql("v.authority_proof->>'employee_id'", 'uuid');
        $aEmployee = $this->typedSql("a.authority_proof->>'employee_id'", 'uuid');
        $rEmployee = $this->typedSql("r.authority_proof->>'employee_id'", 'uuid');
        $query = DB::table('ba_events as e')->join('ba_request_versions as r', 'r.id', '=', 'e.request_version_id')->where('e.project_id', $project)->where('e.clarification_id', $root);
        $named = "SELECT r.requester_id AS employee_id UNION SELECT r.coordinator_id UNION SELECT r.decision_owner_id UNION SELECT $rEmployee UNION SELECT $eEmployee UNION SELECT $aEmployee FROM ba_answer_versions a WHERE a.id=e.answer_version_id OR a.id=coalesce(e.subject_answer_version_id,e.answer_version_id) UNION SELECT $vEmployee FROM ba_reviews v WHERE v.id=e.review_id UNION SELECT t.assignee_id FROM ba_request_links l JOIN task_versions t ON t.id=l.task_version_id WHERE l.request_version_id=r.id";
        $query->whereRaw("NOT EXISTS (SELECT 1 FROM ($named) n WHERE n.employee_id IS NOT NULL AND (SELECT count(*) FROM project_memberships m WHERE m.project_id=e.project_id AND m.employee_id=n.employee_id AND m.valid_from<=clock_timestamp() AND (m.valid_to IS NULL OR m.valid_to>clock_timestamp()))<>1)");
        if ($department !== null) {
            $query->whereRaw("NOT EXISTS (SELECT 1 FROM ($named) n WHERE n.employee_id IS NOT NULL AND (SELECT count(*) FROM department_memberships m WHERE m.department_id=?::uuid AND m.employee_id=n.employee_id AND m.valid_from<=clock_timestamp() AND (m.valid_to IS NULL OR m.valid_to>clock_timestamp()))<>1)", [$department]);
        }
        $sources = "SELECT $sourceId AS id FROM jsonb_array_elements(e.authority_proof->'sources') s UNION SELECT a.source_instance_id FROM ba_answer_versions a WHERE a.id=e.answer_version_id OR a.id=coalesce(e.subject_answer_version_id,e.answer_version_id) UNION SELECT $sourceId FROM jsonb_array_elements(r.authority_proof->'sources') s UNION SELECT p.source_instance_id FROM ba_source_party_versions p WHERE p.id=r.decision_owner_party_version_id OR p.id IN (SELECT a.author_party_version_id FROM ba_answer_versions a WHERE a.id=e.answer_version_id OR a.id=coalesce(e.subject_answer_version_id,e.answer_version_id))";
        $query->whereRaw("NOT EXISTS (SELECT 1 FROM ($sources) x WHERE NOT EXISTS (SELECT 1 FROM source_instances s JOIN ingestion_connectors k ON k.source_instance_id=s.id AND k.project_id=e.project_id WHERE s.id=x.id AND s.kind='manual' AND k.status='active' AND k.adapter='synthetic-v1'))");

        return $query;
    }

    private function query(array $query): array
    {
        $limit = $query['limit'] ?? 20;
        $department = $query['department_id'] ?? null;
        $cursor = $query['cursor'] ?? null;
        if (array_diff(array_keys($query), ['limit', 'department_id', 'cursor']) || ! is_int($limit) || $limit < 1 || $limit > 50 || ($department !== null && (! is_string($department) || ! Str::isUuid($department))) || ($cursor !== null && ! is_string($cursor))) {
            throw new InvalidArgumentException;
        }

        return [$limit, $department, $cursor];
    }

    private function waiting(array $status, object $request): ?string
    {
        if ($status['dependency_status'] === 'decision_resolved' || $status['clarification_state'] === 'withdrawn') {
            return null;
        }
        if ($status['scope_status'] !== 'not_requested') {
            return 'scope';
        }

        return $status['answer_review_status'] === 'awaiting_review' ? 'pm' : ($status['answer_review_status'] === 'unanswered' ? ($request->decision_party === 'customer' ? 'customer' : 'ba') : 'ba');
    }

    private function factDto(object $row, string $kind): array
    {
        $fields = $kind === 'request' ? ['id', 'question', 'requester_id', 'coordinator_id', 'decision_owner_id', 'decision_owner_party_version_id', 'requested_at', 'request_hash', 'links_hash'] : ['id', 'answer', 'reason', 'response_at', 'author_party_version_id', 'operation', 'answer_hash', 'source_revision'];

        return array_intersect_key((array) $row, array_flip($fields));
    }

    private function sourceDto(array $graph, string $receipt): ?array
    {
        $row = $graph['rows']['receipt'][$receipt] ?? null;

        return $row === null ? null : array_intersect_key((array) $row, array_flip(['id', 'variant', 'source_instance_id', 'object_id', 'source_revision', 'occurred_at', 'source_updated_at', 'received_at']));
    }

    private function page(array $items, ?string $cursor, string $coverage, array $reasons): array
    {
        return ['items' => $items, 'next_cursor' => $cursor, 'visible_record_count' => $coverage === 'complete' ? count($items) : null, 'coverage_status' => $coverage, 'reason_codes' => $reasons] + $this->labels();
    }

    private function labels(): array
    {
        return ['synthetic' => true, 'ai_interpretation' => 'unavailable', 'customer_authenticity' => 'not_verified'];
    }
}
