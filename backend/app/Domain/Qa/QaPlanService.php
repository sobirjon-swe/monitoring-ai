<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class QaPlanService
{
    public function createEntry(TrustedActor $actor, string $project, array $entry, string $key): array
    {
        return app(QaInvalidation::class)->guardRequest($actor, $project, 'plan.entry', $key, fn () => $this->createEntryRequest($actor, $project, $entry, $key));
    }

    private function createEntryRequest(TrustedActor $actor, string $project, array $entry, string $key): array
    {
        $entry = app(QaContract::class)->validate('plan.entry', $entry);
        if (($entry['disposition'] === 'included' && $entry['reason'] !== null) || ($entry['disposition'] === 'excluded' && ($entry['reason'] === null || $entry['tests']))) {
            throw new InvalidArgumentException('Invalid QA entry disposition.');
        }
        if ($entry['disposition'] === 'excluded') {
            app(QaContract::class)->reason($entry['reason']);
        }
        $ids = array_column($entry['tests'], 'definition_version_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Duplicate QA definition.');
        }
        usort($entry['tests'], fn ($a, $b) => strcmp($a['definition_version_id'], $b['definition_version_id']));
        $requestHash = hash('sha256', QaContract::canonical($entry));
        $refs = ['baseline' => [$entry['baseline_id']], 'definition' => $ids];
        $graph = $this->collectPins($actor, $project, $refs, array_column($entry['tests'], 'source_instance_id'), ['operation' => 'plan.entry', 'key' => $key]);

        return app(QaCoordination::class)->run($actor, $project, ['qa.plan.manage', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $entry, $key, $requestHash, $graph) {
            $rows = $this->loadGraphLocked($project, $graph);
            $this->assertOriginalsLocked($graph, $proofs);
            $this->assertCurrentLocked($project, $rows);
            $writer = app(QaReceiptWriter::class);
            $old = $writer->requestLocked($actor, $project, 'plan.entry', $key, $requestHash);
            if ($old) {
                $row = $rows['entry'][$old['entry_id']] ?? throw new ConflictHttpException;

                app(QaInvalidation::class)->assertReplayCurrentLocked($project, ['entry_id' => $row->id]);

                return $this->entryDtoLocked($project, $row, $rows) + ['replayed' => true];
            }
            $item = DB::table('baseline_items')->where('project_id', $project)->where('id', $entry['baseline_item_id'])->where('baseline_id', $entry['baseline_id'])->where('criterion_version_id', $entry['criterion_version_id'])->first();
            if (! $item) {
                throw new ConflictHttpException;
            }
            $this->assertCriteriaCurrentLocked($project, [$item->criterion_version_id]);
            $tests = [];
            foreach ($entry['tests'] as $test) {
                $definition = $rows['definition'][$test['definition_version_id']];
                if ($definition->criterion_version_id !== $item->criterion_version_id) {
                    throw new ConflictHttpException;
                }
                $review = $rows['review'][$definition->current_review_id] ?? throw new ConflictHttpException;
                $tests[] = $this->testContent($definition, $review, $test['source_instance_id'], $test['required']);
            }
            $content = ['baseline_id' => $item->baseline_id, 'policy_id' => $item->policy_id, 'baseline_item_id' => $item->id, 'criterion_id' => $item->stable_criterion_id, 'criterion_version_id' => $item->criterion_version_id, 'included' => $entry['disposition'] === 'included', 'exclusion_reason' => $entry['reason'], 'tests' => $tests];
            $hash = hash('sha256', QaContract::canonical($content));
            $writer->admit($project);
            $id = (string) Str::uuid();
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
            $record = $content;
            unset($record['tests']);
            DB::table('qa_plan_entry_snapshots')->insert(['id' => $id, 'content_hash' => $hash, 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proofs['current']['proof'])] + $scope + $record);
            foreach ($tests as $test) {
                DB::table('qa_plan_entry_definitions')->insert(['id' => (string) Str::uuid(), 'entry_id' => $id, 'criterion_id' => $item->stable_criterion_id, 'criterion_version_id' => $item->criterion_version_id, 'definition_id' => $test['definition_version_id'], 'definition_review_id' => $test['definition_review_id'], 'source_instance_id' => $test['source_instance_id'], 'required' => $test['required']] + $scope);
            }
            $writer->bindRequestLocked($actor, $project, 'plan.entry', $key, $requestHash, ['entry_id' => $id]);
            app(QaChangeWriter::class)->appendLocked($actor, $project, 'entry_created', ['entry_id' => $id], $hash, $proofs['current']['proof']);

            return ['id' => $id, 'input_hash' => $hash, 'synthetic' => true, 'replayed' => false] + $content;
        }, $graph['requests']);
    }

    public function compose(TrustedActor $actor, string $project, array $compactPlan, string $key): array
    {
        return app(QaInvalidation::class)->guardRequest($actor, $project, 'plan.compose', $key, fn () => $this->composeRequest($actor, $project, $compactPlan, $key));
    }

    private function composeRequest(TrustedActor $actor, string $project, array $compactPlan, string $key): array
    {
        $compactPlan = app(QaContract::class)->validate('plan.compose', $compactPlan);
        sort($compactPlan['entry_snapshot_ids'], SORT_STRING);
        $requestHash = hash('sha256', QaContract::canonical($compactPlan));
        $graph = $this->collectPins($actor, $project, ['baseline' => [$compactPlan['baseline_id']], 'entry' => $compactPlan['entry_snapshot_ids']], [], ['operation' => 'plan.compose', 'key' => $key]);

        return app(QaCoordination::class)->run($actor, $project, ['qa.plan.manage', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $compactPlan, $key, $requestHash, $graph) {
            $rows = $this->loadGraphLocked($project, $graph);
            $this->assertOriginalsLocked($graph, $proofs);
            $this->assertCurrentLocked($project, $rows);
            $writer = app(QaReceiptWriter::class);
            $old = $writer->requestLocked($actor, $project, 'plan.compose', $key, $requestHash);
            if ($old) {
                $row = $rows['plan'][$old['plan_id']] ?? throw new ConflictHttpException;

                app(QaInvalidation::class)->assertReplayCurrentLocked($project, ['plan_id' => $row->id]);

                return $this->planDtoLocked($project, $row, $rows) + ['replayed' => true];
            }
            $baseline = $rows['baseline'][$compactPlan['baseline_id']];
            $entries = array_map(fn ($id) => $this->entryDtoLocked($project, $rows['entry'][$id], $rows), $compactPlan['entry_snapshot_ids']);
            $this->assertPartitionLocked($project, $baseline->id, $entries);
            $content = $this->planContent($baseline, $entries);
            $hash = hash('sha256', QaContract::canonical($content));
            $writer->admit($project);
            $id = (string) Str::uuid();
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
            DB::table('qa_plan_versions')->insert(['id' => $id, 'baseline_id' => $baseline->id, 'policy_id' => $baseline->policy_id, 'logical_key' => $id, 'revision' => 1, 'content_hash' => $hash, 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proofs['current']['proof'])] + $scope);
            $links = array_map(fn ($entry) => ['id' => (string) Str::uuid(), 'plan_id' => $id, 'baseline_id' => $baseline->id, 'criterion_version_id' => $entry['criterion_version_id'], 'entry_id' => $entry['id']] + $scope, $entries);
            if ($links) {
                DB::table('qa_plan_entries')->insert($links);
            }
            $writer->bindRequestLocked($actor, $project, 'plan.compose', $key, $requestHash, ['plan_id' => $id]);
            app(QaChangeWriter::class)->appendLocked($actor, $project, 'plan_created', ['plan_id' => $id], $hash, $proofs['current']['proof']);

            return $this->planResult($id, $hash, $content, $entries) + ['replayed' => false];
        }, $graph['requests']);
    }

    public function review(TrustedActor $actor, string $project, string $plan, array $decision): array
    {
        $decision = app(QaContract::class)->validate('plan.review', $decision);
        $graph = $this->collectPins($actor, $project, ['plan' => [$plan]], freshPlanReviewSubject: $plan);

        return app(QaCoordination::class)->run($actor, $project, ['qa.plan.manage', 'qa.read', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $plan, $decision, $graph) {
            $rows = $this->loadGraphLocked($project, $graph);
            $this->assertOriginalsLocked($graph, $proofs);
            $this->assertCurrentLocked($project, $rows);
            $row = $rows['plan'][$plan];
            $this->planDtoLocked($project, $row, $rows);
            if ($row->review_revision !== $decision['expected_revision']) {
                throw new ConflictHttpException;
            }
            app(QaReceiptWriter::class)->admit($project);
            $id = (string) Str::uuid();
            $revision = $row->review_revision + 1;
            DB::table('qa_reviews')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'plan_id' => $plan, 'revision' => $revision, 'decision' => $decision['decision'], 'subject_hash' => $row->content_hash, 'actor_id' => $actor->id, 'authority_proof' => QaContract::canonical($proofs['current']['proof']), 'reason' => $decision['reason']]);
            DB::table('qa_plan_versions')->where('id', $plan)->update(['review_revision' => $revision, 'current_review_id' => $id, 'review_state' => $decision['decision']]);
            app(QaChangeWriter::class)->appendLocked($actor, $project, 'plan_reviewed', ['review_id' => $id], hash('sha256', QaContract::canonical(['plan_id' => $plan, 'subject_hash' => $row->content_hash] + $decision)), $proofs['current']['proof']);

            return ['id' => $id, 'revision' => $revision, 'state' => $decision['decision'], 'synthetic' => true];
        }, $graph['requests']);
    }

    /** Trusted scoped relationship hints only; no content is delivered before coordination. */
    public function collectPins(TrustedActor $actor, string $project, array $refs, array $sourceIds = [], ?array $requestIdentity = null, bool $includeCurrentReviews = true, ?string $freshPlanReviewSubject = null): array
    {
        foreach ($refs as $kind => $ids) {
            if (! isset(QaPins::TABLES[$kind]) || ! is_array($ids) || ! array_is_list($ids)) {
                throw new InvalidArgumentException('Invalid QA graph.');
            }
            foreach ($ids as $id) {
                if (! is_string($id) || ! Str::isUuid($id)) {
                    throw new InvalidArgumentException('Invalid QA graph UUID.');
                }
            }
        }

        if ($freshPlanReviewSubject !== null && ! in_array($freshPlanReviewSubject, $refs['plan'] ?? [], true)) {
            throw new InvalidArgumentException('Invalid QA fresh plan review subject.');
        }

        return app(TenantContext::class)->run($actor->tenantId, function () use ($actor, $project, $refs, $sourceIds, $requestIdentity, $includeCurrentReviews, $freshPlanReviewSubject) {
            if ($requestIdentity !== null) {
                if (array_keys($requestIdentity) !== ['operation', 'key'] || ! in_array($requestIdentity['operation'], ['plan.entry', 'plan.compose'], true)) {
                    throw new InvalidArgumentException('Invalid QA request graph.');
                }
                $refs = $this->withRequestRefs($actor, $project, $requestIdentity['operation'], $requestIdentity['key'], $refs);
            }
            $add = function (string $kind, array $ids) use (&$refs) {
                $refs[$kind] = array_values(array_unique([...($refs[$kind] ?? []), ...array_filter($ids)]));
            };
            $plans = empty($refs['plan']) ? collect() : DB::table('qa_plan_versions')->where('project_id', $project)->whereIn('id', $refs['plan'])->get(['id', 'baseline_id', 'current_review_id']);
            $add('baseline', $plans->pluck('baseline_id')->all());
            if ($includeCurrentReviews) {
                // A fresh subject decision captures its own reviewer; exact child reviews remain original inputs.
                $add('review', $plans->reject(fn ($plan) => $plan->id === $freshPlanReviewSubject)->pluck('current_review_id')->all());
            }
            $links = $plans->isEmpty() ? collect() : DB::table('qa_plan_entries')->where('project_id', $project)->whereIn('plan_id', $plans->pluck('id'))->limit(101)->get(['entry_id']);
            if ($links->count() > 100) {
                throw new ConflictHttpException;
            }
            $add('entry', $links->pluck('entry_id')->all());
            $entries = DB::table('qa_plan_entry_snapshots')->where('project_id', $project)->whereIn('id', $refs['entry'] ?? [])->get(['id', 'baseline_id', 'criterion_version_id']);
            if ($entries->count() > 100) {
                throw new ConflictHttpException;
            }
            $add('baseline', $entries->pluck('baseline_id')->all());
            $add('criterion_version', $entries->pluck('criterion_version_id')->all());
            $tests = DB::table('qa_plan_entry_definitions')->where('project_id', $project)->whereIn('entry_id', $entries->pluck('id'))->limit(2001)->get(['definition_id', 'definition_review_id', 'source_instance_id']);
            if ($tests->count() > 2000) {
                throw new ConflictHttpException;
            }
            $add('definition', $tests->pluck('definition_id')->all());
            $add('review', $tests->pluck('definition_review_id')->all());
            $sourceIds = [...$sourceIds, ...$tests->pluck('source_instance_id')->all()];
            foreach (['build', 'environment', 'definition'] as $kind) {
                if (empty($refs[$kind])) {
                    continue;
                }
                $columns = $kind === 'definition' ? ['id', 'receipt_id', 'current_review_id', 'criterion_version_id'] : ['id', 'receipt_id'];
                $rows = DB::table(QaPins::TABLES[$kind])->where('project_id', $project)->whereIn('id', $refs[$kind] ?? [])->get($columns);
                $add('receipt', $rows->pluck('receipt_id')->all());
                if ($kind === 'definition') {
                    if ($includeCurrentReviews) {
                        $add('review', $rows->pluck('current_review_id')->all());
                    }
                    $add('criterion_version', $rows->pluck('criterion_version_id')->all());
                }
            }
            $receipts = DB::table('qa_receipts')->where('project_id', $project)->whereIn('id', $refs['receipt'] ?? [])->get(['source_instance_id']);
            $sourceIds = array_values(array_unique([...$sourceIds, ...$receipts->pluck('source_instance_id')->all()]));
            foreach ($sourceIds as $source) {
                if (! is_string($source) || ! Str::isUuid($source)) {
                    throw new InvalidArgumentException('Invalid QA source UUID.');
                }
            }
            if (count($sourceIds) > 4002 || count($refs['definition'] ?? []) > 2000) {
                throw new ConflictHttpException;
            }

            return app(QaPins::class)->collect($actor, $project, $refs) + ['source_ids' => $sourceIds];
        });
    }

    public function loadGraphLocked(string $project, array $graph): array
    {
        $this->locked();
        $sources = DB::table('source_instances')->whereIn('id', $graph['source_ids'])->orderBy('id')->sharedLock()->get();
        if ($sources->count() !== count($graph['source_ids'])) {
            throw new NotFoundHttpException;
        }
        if ($sources->contains(fn ($s) => $s->kind !== 'manual')) {
            throw new ConflictHttpException;
        }
        $rows = app(QaPins::class)->loadLocked($project, $graph['refs']);
        $tests = DB::table('qa_plan_entry_definitions')->where('project_id', $project)->whereIn('entry_id', array_keys($rows['entry'] ?? []))->orderBy('definition_id')->limit(2001)->get();
        if ($tests->count() > 2000) {
            throw new ConflictHttpException;
        }
        $rows['entry_definitions_by_entry'] = $tests->groupBy('entry_id')->map(fn ($group) => $group->all())->all();

        return $rows;
    }

    public function assertOriginalsLocked(array $graph, array $proofs): void
    {
        $this->locked();
        foreach ($graph['requests'] as $request) {
            if (! ($proofs[$request['key']]['valid'] ?? false)) {
                throw new ConflictHttpException;
            }
        }
    }

    public function assertCurrentLocked(string $project, array $rows, bool $requireReviewed = true): void
    {
        $this->locked();
        $currentBaseline = DB::table('lifecycle_project_state')->where('project_id', $project)->value('current_baseline_id');
        foreach ($rows['baseline'] ?? [] as $baseline) {
            if ($baseline->id !== $currentBaseline) {
                throw new ConflictHttpException;
            }
        }
        $receiptIds = array_keys($rows['receipt'] ?? []);
        $currentReceipts = DB::table('qa_source_heads')->where('project_id', $project)->whereIn('receipt_id', $receiptIds)->pluck('receipt_id')->all();
        if (array_diff($receiptIds, $currentReceipts)) {
            throw new ConflictHttpException;
        }
        $definitions = $rows['definition'] ?? [];
        $heads = DB::table('qa_definition_identities')->where('project_id', $project)->whereIn('id', array_map(fn ($d) => $d->test_identity_id, $definitions))->get()->keyBy('id');
        foreach ($definitions as $definition) {
            if ($heads->get($definition->test_identity_id)?->current_definition_id !== $definition->id) {
                throw new ConflictHttpException;
            }
            if ($requireReviewed) {
                $review = $rows['review'][$definition->current_review_id] ?? null;
                if ($definition->review_state !== 'reviewed' || ! $review || $review->definition_id !== $definition->id || $review->subject_hash !== $definition->content_hash || $review->decision !== 'reviewed') {
                    throw new ConflictHttpException;
                }
            }
        }
        $this->assertCriteriaCurrentLocked($project, array_keys($rows['criterion_version'] ?? []));
        foreach ($rows['entry_definitions_by_entry'] ?? [] as $tests) {
            if (count($tests) > 20) {
                throw new ConflictHttpException;
            }
            foreach ($tests as $test) {
                $definition = $definitions[$test->definition_id] ?? null;
                if (! $definition || $definition->current_review_id !== $test->definition_review_id || ! isset($rows['review'][$test->definition_review_id])) {
                    throw new ConflictHttpException;
                }
            }
        }
    }

    public function assertCriteriaCurrentLocked(string $project, array $ids): void
    {
        $this->locked();
        if (! $ids) {
            return;
        }
        $current = DB::table('requirement_version_criteria as link')->join('requirement_versions as v', 'v.id', '=', 'link.requirement_version_id')->where('link.project_id', $project)->whereIn('link.criterion_version_id', $ids)->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('requirement_versions as newer')->whereColumn('newer.tenant_id', 'v.tenant_id')->whereColumn('newer.project_id', 'v.project_id')->whereColumn('newer.requirement_id', 'v.requirement_id')->whereColumn('newer.version', '>', 'v.version');
        })->distinct()->pluck('link.criterion_version_id')->all();
        if (array_diff($ids, $current)) {
            throw new ConflictHttpException;
        }
    }

    public function planDtoLocked(string $project, object $plan, array $rows): array
    {
        $this->locked();
        $links = DB::table('qa_plan_entries')->where('project_id', $project)->where('plan_id', $plan->id)->limit(101)->get();
        if ($links->count() > 100) {
            throw new ConflictHttpException;
        }
        $entries = [];
        foreach ($links as $link) {
            $entry = $rows['entry'][$link->entry_id] ?? throw new ConflictHttpException;
            $entries[] = $this->entryDtoLocked($project, $entry, $rows);
        }
        $this->assertPartitionLocked($project, $plan->baseline_id, $entries);
        $baseline = $rows['baseline'][$plan->baseline_id] ?? throw new ConflictHttpException;
        $content = $this->planContent($baseline, $entries);
        $hash = hash('sha256', QaContract::canonical($content));
        if ($hash !== $plan->content_hash) {
            throw new ConflictHttpException;
        }

        return $this->planResult($plan->id, $hash, $content, $entries);
    }

    private function entryDtoLocked(string $project, object $entry, array $rows): array
    {
        $tests = [];
        foreach ($rows['entry_definitions_by_entry'][$entry->id] ?? [] as $test) {
            if (count($tests) === 20) {
                throw new ConflictHttpException;
            }
            $definition = $rows['definition'][$test->definition_id] ?? throw new ConflictHttpException;
            $review = $rows['review'][$test->definition_review_id] ?? throw new ConflictHttpException;
            $tests[] = $this->testContent($definition, $review, $test->source_instance_id, $test->required);
        }
        $content = array_intersect_key((array) $entry, array_flip(['baseline_id', 'policy_id', 'baseline_item_id', 'criterion_id', 'criterion_version_id', 'included', 'exclusion_reason'])) + ['tests' => $tests];
        if (hash('sha256', QaContract::canonical($content)) !== $entry->content_hash) {
            throw new ConflictHttpException;
        }

        return ['id' => $entry->id, 'input_hash' => $entry->content_hash, 'synthetic' => true] + $content;
    }

    private function testContent(object $definition, object $review, string $source, bool $required): array
    {
        return ['definition_version_id' => $definition->id, 'definition_hash' => $definition->content_hash, 'definition_review_id' => $review->id, 'review_hash' => $review->subject_hash, 'source_instance_id' => $source, 'required' => $required, 'release_critical' => $definition->release_critical];
    }

    private function assertPartitionLocked(string $project, string $baseline, array $entries): void
    {
        $items = DB::table('baseline_items')->where('project_id', $project)->where('baseline_id', $baseline)->limit(101)->get()->keyBy('id');
        if ($items->count() > 100 || $items->count() !== count($entries) || count(array_unique(array_column($entries, 'criterion_id'))) !== count($entries)) {
            throw new ConflictHttpException;
        }
        foreach ($entries as $entry) {
            $item = $items->get($entry['baseline_item_id']);
            if (! $item || $entry['baseline_id'] !== $baseline || $entry['policy_id'] !== $item->policy_id || $entry['criterion_id'] !== $item->stable_criterion_id || $entry['criterion_version_id'] !== $item->criterion_version_id) {
                throw new ConflictHttpException;
            }
        }
    }

    private function planContent(object $baseline, array $entries): array
    {
        $content = array_map(function ($entry) {
            $value = ['entry_snapshot_id' => $entry['id']] + $entry;
            unset($value['id'], $value['input_hash'], $value['synthetic'], $value['replayed']);

            return $value;
        }, $entries);
        usort($content, fn ($a, $b) => strcmp($a['criterion_id'], $b['criterion_id']));

        return ['baseline_id' => $baseline->id, 'baseline_hash' => $baseline->content_hash, 'policy_id' => $baseline->policy_id, 'entries' => $content];
    }

    private function planResult(string $id, string $hash, array $content, array $entries): array
    {
        return ['id' => $id, 'input_hash' => $hash, 'baseline_id' => $content['baseline_id'], 'policy_id' => $content['policy_id'], 'entries' => $content['entries'], 'included_criterion_count' => count(array_filter($entries, fn ($e) => $e['included'])), 'selected_definition_count' => array_sum(array_map(fn ($e) => count($e['tests']), $entries)), 'synthetic' => true];
    }

    private function withRequestRefs(TrustedActor $actor, string $project, string $operation, string $key, array $refs): array
    {
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $key)) {
            throw new InvalidArgumentException('Invalid QA request key.');
        }
        $row = DB::table('qa_request_keys')->where('project_id', $project)->where('requester_id', $actor->id)->where('operation', $operation)->where('key', $key)->first(['entry_id', 'plan_id']);
        foreach (['entry', 'plan'] as $kind) {
            if ($id = $row?->{$kind.'_id'}) {
                $refs[$kind] = array_values(array_unique([...($refs[$kind] ?? []), $id]));
            }
        }

        return $refs;
    }

    private function locked(): void
    {
        if (DB::transactionLevel() === 0 || DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== '1') {
            throw new LogicException('QA graph requires closed authority coordination.');
        }
    }
}
