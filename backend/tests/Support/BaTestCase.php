<?php

namespace Tests\Support;

use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaAnswerService;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaContract;
use App\Domain\Ba\BaCoordination;
use App\Domain\Ba\BaEligibility;
use App\Domain\Ba\BaFixtureService;
use App\Domain\Ba\BaPins;
use App\Domain\Ba\BaReceiptWriter;
use App\Domain\Ba\BaTerminal;
use App\Domain\Lifecycle\TaskService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

abstract class BaTestCase extends BaselineTestCase
{
    protected function requestInput(?array $links = null): array
    {
        $party = $this->party();
        $task = $this->baTask();
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');

        return ['question' => 'Synthetic CRM duplicate rule?', 'requester_id' => $this->employee, 'coordinator_id' => $this->employee, 'decision_party' => 'customer', 'decision_owner_id' => null, 'decision_owner_party_version_id' => $party['party_version_id'], 'requested_at' => $at, 'links' => $links ?? [['task_version_id' => $task['version_id'], 'criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id'], 'relation' => 'blocks']]];
    }

    protected function openRequest(?array $body = null, string $object = 'question'): array
    {
        $body ??= $this->requestInput();

        return app(BaClarificationService::class)->open($this->actor(), $this->p, $this->baEnvelope('request.open', $body, $object), $object, 0) + ['body' => $body];
    }

    protected function answerInput(array $request, int $revision = 1): array
    {
        $row = $this->baRun(fn () => DB::table('ba_request_versions')->where('id', $request['request_version_id'])->first());

        return ['clarification_id' => $request['clarification_id'], 'expected_revision' => $revision, 'request_version_id' => $row->id, 'request_hash' => $row->request_hash, 'links_hash' => $row->links_hash, 'author_party_version_id' => $row->decision_owner_party_version_id, 'response_at' => CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z'), 'answer' => 'Synthetic merge duplicates', 'predecessor_answer_version_id' => null];
    }

    protected function recordAnswer(array $request, int $revision = 1, string $object = 'response'): array
    {
        $body = $this->answerInput($request, $revision);

        return app(BaAnswerService::class)->record($this->actor(), $this->p, $request['clarification_id'], $this->baEnvelope('answer.record', $body, $object), $object, 0);
    }

    protected function pmActor(?string $employee = null): TrustedActor
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($employee) {
            if ($employee === null) {
                $employee = (string) Str::uuid();
                DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic PM']);
                DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            }
            $user = (string) Str::uuid();
            DB::table('users')->insert(['id' => $user, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'display_name' => 'Synthetic PM']);
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'role' => 'pm', 'valid_from' => now()->subDay()]);
            foreach (['ba.decision.confirm', 'ba.source.read', 'ba.read'] as $purpose) {
                DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $user, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => 'project', 'valid_from' => now()->subDay()]);
            }

            return new TrustedActor($user, $this->tenant);
        });
    }

    protected function otherBaActor(): TrustedActor
    {
        $actor = $this->pmActor();
        app(TenantContext::class)->run($this->tenant, function () use ($actor) {
            $employee = DB::table('users')->where('id', $actor->id)->value('employee_id');
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'role' => 'ba', 'valid_from' => now()->subDay()]);
            foreach (['ba.request.manage', 'ba.answer.record', 'ba.fixture.manage'] as $purpose) {
                DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'user_id' => $actor->id, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => 'project', 'valid_from' => now()->subDay()]);
            }
        });

        return $actor;
    }

    protected function reviewInput(array $request, array $answer, int $revision = 2, string $disposition = 'no_scope_change'): array
    {
        $pins = $this->answerInput($request, $revision);
        $hash = $this->baRun(fn () => DB::table('ba_answer_versions')->where('id', $answer['answer_version_id'])->value('answer_hash'));

        return array_intersect_key($pins, array_flip(['clarification_id', 'expected_revision', 'request_version_id', 'request_hash', 'links_hash'])) + ['kind' => 'review.confirm', 'answer_version_id' => $answer['answer_version_id'], 'answer_hash' => $hash, 'reason' => 'Synthetic exact review', 'disposition' => $disposition];
    }

    protected function evaluateBa(array $request): array
    {
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, [$request['clarification_id']]);

        return $this->baRun(function ($proofs) use ($graph, $request) {
            $loaded = app(BaPins::class)->loadLocked($this->p, $graph);
            $graph['rows'] = $loaded['rows'];
            $graph['terminal'] = app(BaTerminal::class)->overlayLocked($this->p, $graph);

            return app(BaEligibility::class)->evaluate($graph, $proofs)[$request['clarification_id']];
        }, $graph['requests']);
    }

    protected function baCounts(): array
    {
        return $this->baRun(function () {
            $counts = [];
            foreach (['ba_receipts', 'ba_source_heads', 'ba_request_bindings', 'ba_clarifications', 'ba_request_versions', 'ba_request_links', 'ba_events', 'ba_answer_versions', 'ba_reviews', 'ba_scope_obligations', 'audit_events'] as $table) {
                $counts[$table] = DB::table($table)->where('project_id', $this->p)->count();
            }

            return $counts;
        });
    }

    protected function e03Counts(): array
    {
        return $this->baRun(function () {
            $counts = ['generation' => DB::table('lifecycle_project_state')->where('project_id', $this->p)->value('generation')];
            foreach (['requirement_versions', 'criterion_versions', 'task_versions', 'baselines', 'baseline_items', 'human_acceptance_decisions', 'qa_observations', 'evidence_link_proposals'] as $table) {
                $counts[$table] = DB::table($table)->where('project_id', $this->p)->count();
            }
            $counts['weights'] = DB::table('baseline_items')->where('project_id', $this->p)->orderBy('id')->pluck('weight', 'id')->all();

            return $counts;
        });
    }

    protected function assertBaConflict(Closure $work): void
    {
        try {
            $work();
            $this->fail('Expected BA conflict.');
        } catch (ConflictHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    protected function contendBa(string $mode, array $pins, ?TrustedActor $actor = null): void
    {
        $actor ??= $this->actor();
        DB::commit();
        DB::beginTransaction();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('projects')->where('id', $this->p)->lockForUpdate()->first());
        $children = [];
        $pids = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $input = new InputStream;
                $child = new Process([PHP_BINARY, base_path('tests/Support/ba-cas-child.php'), $this->tenant, $actor->id, $this->p, $mode, json_encode($pins, JSON_THROW_ON_ERROR), 'race-'.$i], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'monitoring_ai_test', 'DB_URL' => ''], null, 20);
                $child->setInput($input);
                $child->start();
                $children[] = [$child, $input];
            }
            foreach ($children as [$child, $input]) {
                $deadline = microtime(true) + 8;
                while (! str_contains($child->getOutput(), 'ready') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ready', $child->getOutput(), $child->getErrorOutput());
                preg_match('/ready pid=([0-9]+)/', $child->getOutput(), $match);
                $pids[] = (int) $match[1];
                $input->write("go\n");
                $input->close();
            }
            $this->assertCount(2, array_unique($pids));
            $deadline = microtime(true) + 5;
            do {
                foreach ($children as [$child]) {
                    $child->isRunning();
                }
                DB::select('select pg_stat_clear_snapshot()');
                $blocked = (int) DB::scalar("select count(*) from pg_stat_activity where datname=current_database() and usename=current_user and pid in (?,?) and wait_event_type='Lock'", $pids);
                if ($blocked === 2) {
                    break;
                } usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both fixed child backends must overlap on the project lock.');
            DB::commit();
            $outcomes = [];
            foreach ($children as [$child]) {
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $outcomes[] = str_contains($child->getOutput(), 'status=201') ? 201 : (str_contains($child->getOutput(), 'status=409') ? 409 : 0);
            }
            sort($outcomes);
            $this->assertSame([201, 409], $outcomes);
            file_put_contents(base_path('../.superpowers/sdd/2026-10-07-e05-ba-clarification-plan/task-2-retained-fixtures.jsonl'), json_encode(['tenant_id' => $this->tenant, 'project_id' => $this->p, 'mode' => $mode, 'pg_pids' => $pids, 'outcomes' => $outcomes], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } finally {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($children as [$child, $input]) {
                $input->close();
                if ($child->isRunning()) {
                    $child->stop(0);
                }
            }
            DB::beginTransaction();
        }
    }

    protected function baInputs(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        foreach (['ba.fixture.manage', 'ba.request.manage', 'ba.answer.record', 'ba.decision.confirm', 'ba.read', 'ba.source.read'] as $purpose) {
            $this->grant($purpose, $this->p);
        }
    }

    protected function baRole(string $role = 'ba', bool $replacement = false): string
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($role, $replacement) {
            $id = (string) Str::uuid();
            DB::table('role_assignments')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $this->employee, 'role' => $role, 'valid_from' => $replacement ? now() : now()->subDay()]);

            return $id;
        });
    }

    protected function baEnvelope(string $operation = 'party.register', array $body = [], string $object = 'customer-1', int $revision = 1): array
    {
        $source = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ingestion_connectors')->where('id', $this->connector)->value('source_instance_id'));

        return ['schema_version' => 'ba-evidence-v1', 'operation' => $operation, 'source_instance_id' => $source, 'object_id' => $object, 'source_revision' => $revision, 'occurred_at' => '2026-10-06T00:00:00.000000Z', 'source_updated_at' => '2026-10-06T00:00:00.000000Z', 'body' => $body ?: ['party_reference' => 'synthetic-customer', 'party_kind' => 'customer', 'label' => 'Synthetic customer', 'predecessor_party_version_id' => null]];
    }

    protected function party(array $envelope = [], string $key = 'party', int $expected = 0): array
    {
        return app(BaFixtureService::class)->registerParty($this->actor(), $this->p, $envelope ?: $this->baEnvelope(), $key, $expected);
    }

    protected function baTask(int $index = 0, ?string $assignee = null): array
    {
        $task = app(TaskService::class)->create($this->actor(), $this->p, ['title' => 'Synthetic task', 'assignee_id' => $assignee ?? $this->employee, 'estimate_basis' => 'Synthetic only', 'remaining_effort' => null, 'expected_test_keys' => ['test-a'], 'status' => 'unstarted', 'criterion_links' => [['criterion_id' => $this->criteria[$index]['id'], 'criterion_version_id' => $this->criteria[$index]['version_id']]]], $this->generation);
        $this->generation = $task['generation'];

        return $task;
    }

    protected function seedBaRequest(string $object = 'request-1', ?array $task = null, ?array $party = null): array
    {
        $task ??= $this->baTask();
        $party ??= $this->party();
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $body = ['question' => 'Synthetic import clarification', 'requester_id' => $this->employee, 'coordinator_id' => $this->employee, 'decision_party' => 'customer', 'decision_owner_id' => null, 'decision_owner_party_version_id' => $party['party_version_id'], 'requested_at' => $at, 'links' => [['task_version_id' => $task['version_id'], 'criterion_id' => $this->criteria[0]['id'], 'criterion_version_id' => $this->criteria[0]['version_id'], 'relation' => 'blocks']]];
        $envelope = $this->baEnvelope('request.open', $body, $object);
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, ['party' => [$party['party_version_id']]], [$envelope['source_instance_id']], $body['links']);

        return app(BaCoordination::class)->run($this->actor(), $this->p, ['ba.request.manage', 'ba.source.read'], false, function ($proofs) use ($body, $envelope, $graph, $party, $task) {
            $writer = app(BaReceiptWriter::class);
            $proof = $proofs['current']['proof'];
            $proof['sources'] = $proofs['sources']['proof']['sources'];
            $ticket = $writer->beginSourceLocked($this->actor(), $this->p, $envelope, $envelope['object_id'], 0, $proof);
            $root = (string) Str::uuid();
            $request = (string) Str::uuid();
            $event = (string) Str::uuid();
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $requestHash = hash('sha256', BaContract::canonical($body));
            $linksHash = hash('sha256', BaContract::canonical($body['links']));
            DB::table('ba_clarifications')->insert(['id' => $root, 'source_instance_id' => $envelope['source_instance_id'], 'object_id' => $envelope['object_id'], 'original_requested_at' => $body['requested_at']] + $scope);
            $requestBody = $body;
            unset($requestBody['links']);
            DB::table('ba_request_versions')->insert(['id' => $request, 'clarification_id' => $root, 'revision' => 1, 'episode' => 1, 'receipt_id' => $ticket['receipt_id'], 'request_hash' => $requestHash, 'links_hash' => $linksHash, 'actor_id' => $this->user, 'authority_proof' => BaContract::canonical($proof)] + $scope + $requestBody);
            $link = array_intersect_key($graph['links'][0], array_flip(['task_version_id', 'criterion_id', 'criterion_version_id', 'relation', 'requirement_version_id', 'requirement_hash']));
            DB::table('ba_request_links')->insert(['id' => (string) Str::uuid(), 'clarification_id' => $root, 'request_version_id' => $request] + $scope + $link);
            DB::table('ba_events')->insert(['id' => $event, 'clarification_id' => $root, 'revision' => 1, 'kind' => 'request.open', 'state' => 'open', 'request_version_id' => $request, 'receipt_id' => $ticket['receipt_id'], 'actor_id' => $this->user, 'authority_proof' => BaContract::canonical($proof)] + $scope);
            DB::table('ba_clarifications')->where('id', $root)->update(['revision' => 1, 'current_event_id' => $event, 'current_request_id' => $request]);
            $output = ['receipt_id' => $ticket['receipt_id'], 'clarification_id' => $root, 'request_version_id' => $request, 'event_id' => $event];
            $writer->bindOutputLocked($ticket, $output);

            return $output + ['task' => $task, 'party' => $party, 'body' => $body, 'proof' => $proof, 'request_hash' => $requestHash, 'links_hash' => $linksHash];
        }, $graph['requests']);
    }

    protected function baRun(Closure $work, array $requests = [], array $purposes = ['ba.read']): mixed
    {
        return app(BaCoordination::class)->run($this->actor(), $this->p, $purposes, false, $work, $requests);
    }

    protected function seedBaAnswer(array $fixture): array
    {
        $body = ['clarification_id' => $fixture['clarification_id'], 'expected_revision' => 1, 'request_version_id' => $fixture['request_version_id'], 'request_hash' => $fixture['request_hash'], 'links_hash' => $fixture['links_hash'], 'author_party_version_id' => $fixture['party']['party_version_id'], 'answer' => 'Synthetic decision', 'response_at' => $fixture['body']['requested_at'], 'predecessor_answer_version_id' => null];
        $envelope = $this->baEnvelope('answer.record', $body, 'answer-'.$fixture['clarification_id']);
        $graph = app(BaPins::class)->collect($this->actor(), $this->p, [$fixture['clarification_id']], [$envelope['source_instance_id']]);

        return app(BaCoordination::class)->run($this->actor(), $this->p, ['ba.answer.record', 'ba.source.read'], false, function ($proofs) use ($fixture, $body, $envelope) {
            $proof = $proofs['current']['proof'];
            $proof['sources'] = $proofs['sources']['proof']['sources'];
            $writer = app(BaReceiptWriter::class);
            $ticket = $writer->beginSourceLocked($this->actor(), $this->p, $envelope, $envelope['object_id'], 0, $proof);
            $id = (string) Str::uuid();
            $event = (string) Str::uuid();
            $hash = hash('sha256', BaContract::canonical($body));
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            unset($body['expected_revision']);
            DB::table('ba_answer_versions')->insert(['id' => $id, 'receipt_id' => $ticket['receipt_id'], 'source_instance_id' => $envelope['source_instance_id'], 'object_id' => $envelope['object_id'], 'source_revision' => 1, 'operation' => 'answer.record', 'answer_hash' => $hash, 'actor_id' => $this->user, 'authority_proof' => BaContract::canonical($proof)] + $scope + $body);
            DB::table('ba_events')->insert(['id' => $event, 'clarification_id' => $fixture['clarification_id'], 'revision' => 2, 'kind' => 'answer.record', 'state' => 'answered', 'request_version_id' => $fixture['request_version_id'], 'answer_version_id' => $id, 'receipt_id' => $ticket['receipt_id'], 'actor_id' => $this->user, 'authority_proof' => BaContract::canonical($proof)] + $scope);
            DB::table('ba_clarifications')->where('id', $fixture['clarification_id'])->update(['revision' => 2, 'current_event_id' => $event, 'current_answer_id' => $id, 'state' => 'answered']);
            $output = ['receipt_id' => $ticket['receipt_id'], 'clarification_id' => $fixture['clarification_id'], 'answer_version_id' => $id, 'event_id' => $event];
            $writer->bindOutputLocked($ticket, $output);

            return $output + ['answer_hash' => $hash];
        }, $graph['requests']);
    }

    protected function seedBaReview(array $request, array $answer): array
    {
        $reviewer = app(TenantContext::class)->run($this->tenant, function () {
            $employee = (string) Str::uuid();
            $user = (string) Str::uuid();
            DB::table('employees')->insert(['id' => $employee, 'tenant_id' => $this->tenant, 'display_name' => 'Synthetic PM']);
            DB::table('users')->insert(['id' => $user, 'tenant_id' => $this->tenant, 'employee_id' => $employee, 'display_name' => 'Synthetic PM']);
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'valid_from' => now()->subDay()]);
            DB::table('role_assignments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $this->p, 'employee_id' => $employee, 'role' => 'pm', 'valid_from' => now()->subDay()]);
            foreach (['ba.read', 'ba.decision.confirm', 'ba.source.read'] as $purpose) {
                DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'user_id' => $user, 'policy_version_id' => $this->policy, 'purpose' => $purpose, 'scope_type' => 'project', 'project_id' => $this->p, 'valid_from' => now()->subDay()]);
            }

            return new TrustedActor($user, $this->tenant);
        });
        $body = ['clarification_id' => $request['clarification_id'], 'expected_revision' => 2, 'kind' => 'review.confirm', 'request_version_id' => $request['request_version_id'], 'request_hash' => $request['request_hash'], 'links_hash' => $request['links_hash'], 'answer_version_id' => $answer['answer_version_id'], 'answer_hash' => $answer['answer_hash'], 'reason' => 'Synthetic PM review', 'disposition' => 'scope_change_required'];

        return app(BaCoordination::class)->run($reviewer, $this->p, ['ba.decision.confirm'], false, function ($proofs) use ($reviewer, $body) {
            $writer = app(BaReceiptWriter::class);
            $ticket = $writer->beginHumanLocked($reviewer, $this->p, 'review.confirm', $body, 'confirm');
            $review = (string) Str::uuid();
            $event = (string) Str::uuid();
            $scope = ['tenant_id' => $this->tenant, 'project_id' => $this->p];
            $row = $body;
            unset($row['expected_revision']);
            $proof = BaContract::canonical($proofs['current']['proof']);
            DB::table('ba_reviews')->insert(['id' => $review, 'receipt_id' => $ticket['receipt_id'], 'actor_id' => $reviewer->id, 'authority_proof' => $proof] + $scope + $row);
            DB::table('ba_events')->insert(['id' => $event, 'clarification_id' => $body['clarification_id'], 'revision' => 3, 'kind' => 'review.confirm', 'state' => 'resolved', 'request_version_id' => $body['request_version_id'], 'answer_version_id' => $body['answer_version_id'], 'review_id' => $review, 'receipt_id' => $ticket['receipt_id'], 'actor_id' => $reviewer->id, 'authority_proof' => $proof] + $scope);
            DB::table('ba_clarifications')->where('id', $body['clarification_id'])->update(['revision' => 3, 'current_event_id' => $event, 'current_review_id' => $review, 'state' => 'resolved']);
            $output = ['receipt_id' => $ticket['receipt_id'], 'clarification_id' => $body['clarification_id'], 'review_id' => $review, 'event_id' => $event];
            $writer->bindOutputLocked($ticket, $output);

            return $output + ['reviewer' => $reviewer];
        });
    }

    protected function assertBaDenied(Closure $work): void
    {
        try {
            $work();
            $this->fail('Expected BA authorization denial.');
        } catch (AccessDeniedHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    protected function assertBaSqlState(string $state, Closure $work): void
    {
        try {
            DB::transaction($work);
            $this->fail('Expected BA database rejection.');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
