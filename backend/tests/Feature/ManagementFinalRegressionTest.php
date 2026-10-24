<?php

namespace Tests\Feature;

use App\Domain\Access\TrustedActor;
use App\Domain\Ba\BaAnswerService;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaFixtureService;
use App\Domain\Ba\BaReviewService;
use App\Domain\Identity\LocalTokenAuthenticator;
use App\Domain\Management\ManagementContract;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\ManagementTestCase;

class ManagementFinalRegressionTest extends ManagementTestCase
{
    private array $crmTokens = [];

    private mixed $crmPdo;

    public array $crmEvidence = [];

    public function test_six_named_roles_use_authenticated_kernel_and_preserve_underlying_dependencies(): void
    {
        $this->runCrm();
    }

    private function tokenFor(TrustedActor $actor): string
    {
        return app(TenantContext::class)->run($this->tenant, function () use ($actor) {
            $subject = (string) Str::uuid();
            $id = (string) Str::uuid();
            $secret = bin2hex(random_bytes(32));
            DB::table('auth_subjects')->insert(['id' => $subject, 'tenant_id' => $this->tenant, 'user_id' => $actor->id, 'issuer' => 'local-development', 'subject' => $actor->id]);
            DB::table('auth_tokens')->insert(['id' => $id, 'tenant_id' => $this->tenant, 'auth_subject_id' => $subject, 'secret_hash' => hash('sha256', $secret), 'expires_at' => now()->addHour()]);

            return $this->tenant.'.'.$id.'.'.$secret;
        });
    }

    private function employeeFor(TrustedActor $actor): string
    {
        return app(TenantContext::class)->run($this->tenant, fn () => DB::table('users')->where('id', $actor->id)->value('employee_id'));
    }

    private function http(string $persona, string $method, string $path, ?array $body = null, array $headers = [], int $status = 200): array
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->crmTokens[$persona], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }
        $request = Request::create('http://127.0.0.1/api/v1/projects/'.$this->p.'/management'.$path, $method, [], [], [], $server, $body === null ? null : ManagementContract::canonical($body));
        $response = app(Kernel::class)->handle($request);
        $this->assertSame($status, $response->getStatusCode(), $persona.' '.$method.' '.$path);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame($this->crmPdo, DB::connection()->getPdo());
        if ($method === 'HEAD') {
            $this->assertSame('', $response->getContent());

            return [];
        }

        return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
    }

    private function create(string $persona, string $path, array $body, string $key, bool $source = false, int $sourceRevision = 0): array
    {
        return $this->http($persona, 'POST', $path, $body, ['Idempotency-Key' => $key] + ($source ? ['Expected-Source-Revision' => (string) $sourceRevision] : []), 201);
    }

    private function fixtureAction(array $envelope, string $key): array
    {
        $token = tempnam(sys_get_temp_dir(), 'management-crm-token-');
        $fixture = tempnam(sys_get_temp_dir(), 'management-crm-fixture-');
        try {
            file_put_contents($token, $this->crmTokens['John']);
            file_put_contents($fixture, ManagementContract::canonical($envelope));
            $args = ['project' => $this->p, 'fixture' => $fixture, '--token-file' => $token, '--expected-source-revision' => '0', '--idempotency-key' => $key];
            $this->assertSame(0, Artisan::call('app:register-local-management-fixture', $args));
            $this->assertSame(0, Artisan::call('app:register-local-management-fixture', $args));
            $this->assertStringContainsString('replayed=1', Artisan::output());
            $this->assertStringNotContainsString($this->crmTokens['John'], Artisan::output());
            $this->assertSame($this->crmPdo, DB::connection()->getPdo());
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            unlink($token);
            unlink($fixture);
        }
        $this->assertFileDoesNotExist($token);
        $this->assertFileDoesNotExist($fixture);

        return $this->http('John', 'POST', '/actions', $envelope, ['Idempotency-Key' => $key, 'Expected-Source-Revision' => '0']);
    }

    private function sources(TrustedActor $andrew, TrustedActor $ivan): array
    {
        $this->managementGrantActor($andrew, ['qa.handoff.manage', 'requirements.read', 'progress.read']);
        $originalUser = $this->user;
        $originalEmployee = $this->employee;
        try {
            // Provision source evidence as Andrew's existing QA persona, before the read-only snapshot.
            $this->user = $andrew->id;
            $this->employee = $this->employeeFor($andrew);
            $qaFailed = $this->managementQaExecutionFixture();
        } finally {
            $this->user = $originalUser;
            $this->employee = $originalEmployee;
        }
        $qa = $this->managementQaHandoff('crm-handoff', $andrew, false);
        $qaEvent = app(TenantContext::class)->run($this->tenant, fn () => DB::table('qa_handoffs')->where('id', $qa['id'])->value('current_event_id'));
        $this->managementGrantActor($ivan, ['ba.fixture.manage', 'ba.request.manage', 'ba.answer.record', 'ba.read', 'ba.source.read', 'requirements.read', 'progress.read']);
        $party = app(BaFixtureService::class)->registerParty($ivan, $this->p, $this->managementSourceEnvelope('ba-evidence-v1', 'party.register', ['party_reference' => 'crm-customer', 'party_kind' => 'customer', 'label' => 'Synthetic customer', 'predecessor_party_version_id' => null], 'crm-party'), 'crm-party', 0);
        $at = CarbonImmutable::parse(DB::scalar('select clock_timestamp()'))->utc()->format('Y-m-d\TH:i:s.u\Z');
        $link = $this->lifecycleReference();
        unset($link['kind']);
        $requestBody = ['question' => 'Synthetic scope decision', 'requester_id' => $this->employeeFor($ivan), 'coordinator_id' => $this->employeeFor($ivan), 'decision_party' => 'customer', 'decision_owner_id' => null, 'decision_owner_party_version_id' => $party['party_version_id'], 'requested_at' => $at, 'links' => [$link]];
        $request = app(BaClarificationService::class)->open($ivan, $this->p, $this->managementSourceEnvelope('ba-evidence-v1', 'request.open', $requestBody, 'crm-request'), 'crm-request', 0);
        $stored = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_request_versions')->where('id', $request['request_version_id'])->first());
        $answerBody = ['clarification_id' => $request['clarification_id'], 'expected_revision' => 1, 'request_version_id' => $stored->id, 'request_hash' => $stored->request_hash, 'links_hash' => $stored->links_hash, 'author_party_version_id' => $party['party_version_id'], 'response_at' => $at, 'answer' => 'Synthetic scope change', 'predecessor_answer_version_id' => null];
        $answer = app(BaAnswerService::class)->record($ivan, $this->p, $request['clarification_id'], $this->managementSourceEnvelope('ba-evidence-v1', 'answer.record', $answerBody, 'crm-answer'), 'crm-answer', 0);
        $this->managementGrantActor($this->actor(), ['ba.decision.confirm', 'ba.read', 'ba.source.read']);
        $answerStored = app(TenantContext::class)->run($this->tenant, fn () => DB::table('ba_answer_versions')->where('id', $answer['answer_version_id'])->first());
        $review = app(BaReviewService::class)->review($this->actor(), $this->p, $request['clarification_id'], ['kind' => 'review.confirm', 'clarification_id' => $request['clarification_id'], 'expected_revision' => 2, 'request_version_id' => $stored->id, 'request_hash' => $stored->request_hash, 'links_hash' => $stored->links_hash, 'answer_version_id' => $answerStored->id, 'answer_hash' => $answerStored->answer_hash, 'disposition' => 'scope_change_required', 'reason' => 'Synthetic scope review'], 'crm-ba-review');

        return [array_replace($this->lifecycleReference(), ['kind' => 'qa_execution_version', 'qa_execution_version_id' => $qaFailed['execution']['record_id']]), array_replace($this->lifecycleReference(), ['kind' => 'ba_review', 'ba_review_id' => $review['review_id']]), array_replace($this->lifecycleReference(), ['kind' => 'qa_handoff_event', 'qa_handoff_event_id' => $qaEvent])];
    }

    private function sourceSnapshot(): array
    {
        return app(TenantContext::class)->run($this->tenant, function () {
            $snapshot = [];
            foreach (['lifecycle_project_state', 'lifecycle_tasks', 'task_versions', 'task_criterion_links', 'requirement_versions', 'criterion_versions', 'baselines', 'baseline_items', 'human_acceptance_decisions', 'qa_project_state', 'qa_source_heads', 'qa_handoffs', 'qa_handoff_events', 'qa_execution_versions', 'qa_execution_attempts', 'qa_execution_series', 'qa_defects', 'qa_defect_events', 'qa_receipts', 'qa_changes', 'qa_applicability', 'qa_invalidations', 'ba_clarifications', 'ba_source_heads', 'ba_scope_obligations', 'ba_receipts', 'ba_terminal_applicability'] as $table) {
                $rows = DB::table($table)->where('project_id', $this->p)->get()->map(fn ($row) => (array) $row)->all();
                usort($rows, fn ($a, $b) => strcmp(ManagementContract::canonical($a), ManagementContract::canonical($b)));
                $snapshot[$table] = ['count' => count($rows), 'sha256' => hash('sha256', ManagementContract::canonical($rows))];
            }

            return $snapshot;
        });
    }

    public function runCrm(): void
    {
        $this->crmPdo = DB::connection()->getPdo();
        $this->managementInputs();
        $personas = ['John' => $this->actor()];
        foreach (require __DIR__.'/../Fixtures/management-crm.php' as $name => $role) {
            $personas[$name] ??= $this->managementActor($role);
        }
        foreach ($personas as $name => $actor) {
            $this->crmTokens[$name] = $name === 'John' ? $this->token : $this->tokenFor($actor);
        }
        [$qaRef, $baRef, $handoffRef] = $this->sources($personas['Andrew'], $personas['Ivan']);
        $base = ['management.read', 'management.source.read', 'requirements.read', 'progress.read'];
        foreach ($personas as $name => $actor) {
            $purposes = $name === 'John' ? ['management.outcome.review', 'qa.read', 'qa.source.read'] : $base;
            if (in_array($name, ['Andrew', 'Steve', 'Adam'], true)) {
                $purposes = [...$purposes, 'qa.read', 'qa.source.read'];
            }
            if (in_array($name, ['Ivan', 'Victoria'], true)) {
                $purposes = [...$purposes, 'ba.read', 'ba.source.read'];
            }
            // Existing source setup grants are reused rather than duplicated.
            $existing = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $actor->id)->where('project_id', $this->p)->pluck('purpose')->all());
            $this->managementGrantActor($actor, array_values(array_diff(array_unique($purposes), $existing)));
        }
        foreach (['Steve', 'Ivan'] as $name) {
            $this->managementGrantActor($personas[$name], ['management.support.record']);
        }
        $this->grant('management.fixture.manage', $this->p);
        $departments = [];
        foreach (['technical' => ['Adam', 'Andrew', 'Steve', 'John'], 'business' => ['Victoria', 'Ivan', 'John']] as $label => $names) {
            $department = $departments[$label] = (string) Str::uuid();
            app(TenantContext::class)->run($this->tenant, function () use ($personas, $names, $department, $label) {
                DB::table('departments')->insert(['id' => $department, 'tenant_id' => $this->tenant, 'name' => 'Synthetic '.$label]);
                foreach ($names as $name) {
                    DB::table('department_memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'employee_id' => $this->employeeFor($personas[$name]), 'valid_from' => now()->subDay()]);
                }
            });
            $this->managementGrantActor($personas[$names[0]], ['management.support.assign', 'management.department.manage', 'management.department.read'], $department);
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'department_id' => $department, 'user_id' => $personas[$names[0]]->id, 'policy_version_id' => $this->policy, 'purpose' => $label === 'technical' ? 'qa.department.read' : 'ba.department.read', 'scope_type' => 'department', 'valid_from' => now()->subDay()]));
        }
        $before = $this->sourceSnapshot();
        $actions = [];
        foreach (['technical' => ['Adam', 'Andrew', 'Steve', $qaRef], 'business' => ['Victoria', 'John', 'Ivan', $baRef]] as $label => [$head, $owner, $helper, $reference]) {
            $references = [$this->lifecycleReference(), $reference];
            if ($label === 'technical') {
                $references[] = $handoffRef;
            }
            $body = ['owner_id' => $this->employeeFor($personas[$owner]), 'reason' => 'Synthetic '.$label.' constraint', 'references' => $references];
            $envelope = $this->managementEnvelope('action.open', $body, 'crm-'.$label);
            $open = $label === 'technical' ? $this->fixtureAction($envelope, 'crm-'.$label) : $this->create('John', '/actions', $envelope, 'crm-'.$label, true);
            $id = $open['action_id'];
            $this->http($helper, 'POST', '/actions/'.$id.'/events', ['operation' => 'assignment.set', 'expected_revision' => 1, 'owner_id' => $body['owner_id'], 'helper_id' => $this->employeeFor($personas[$helper]), 'department_id' => $departments[$label], 'reason' => 'Synthetic unauthorized assignment'], ['Idempotency-Key' => 'deny-'.$label], 403);
            $assignment = $this->create($head, '/actions/'.$id.'/events', ['operation' => 'assignment.set', 'expected_revision' => 1, 'owner_id' => $body['owner_id'], 'helper_id' => $this->employeeFor($personas[$helper]), 'department_id' => $departments[$label], 'reason' => 'Synthetic assignment'], 'crm-assign-'.$label);
            $supportBody = $this->workflowSupportBody(compact('open', 'assignment'));
            $supportBody['references'] = $references;
            $supportEnvelope = $this->managementEnvelope('support.record', $supportBody, 'crm-support-'.$label);
            $support = $this->create($helper, '/actions/'.$id.'/support', $supportEnvelope, 'crm-support-'.$label, true);
            $reviewBody = $this->workflowReviewBody($support);
            if ($label === 'technical') {
                $this->http($helper, 'POST', '/actions/'.$id.'/reviews', $reviewBody, ['Idempotency-Key' => 'deny-review-'.$label], 403);
            }
            $review = $this->create('John', '/actions/'.$id.'/reviews', $reviewBody, 'crm-review-'.$label);
            $this->assertSame('completed', $review['current_outcome']);
            $actions[$label] = compact('open', 'assignment', 'support', 'review', 'supportBody', 'helper', 'head');
        }
        foreach (array_keys($personas) as $name) {
            $page = $this->http($name, 'GET', '/queue');
            $this->assertTrue($page['synthetic']);
            $this->assertSame('unavailable', $page['ai_interpretation']);
            $this->http($name, 'HEAD', '/queue');
            $this->assertNotEmpty($page['items'], $name.' has no authorized view');
            if ($name !== 'John') {
                $other = in_array($name, ['Andrew', 'Steve', 'Adam'], true) ? 'business' : 'technical';
                $this->assertNotContains($actions[$other]['open']['action_id'], array_column($page['items'], 'action_id'));
                $hidden = $this->http($name, 'GET', '/history/'.$actions[$other]['open']['action_id']);
                $this->assertSame([], $hidden['items']);
            }
            foreach ($page['items'] as $item) {
                $this->assertNull($item['business_age']);
            }
        }
        $page = $this->http('John', 'GET', '/queue');
        $this->assertCount(2, $page['items']);
        $dependencies = array_merge(...array_column($page['items'], 'underlying_dependency_status'));
        $this->assertContains('scope_change_required', $dependencies);
        $this->assertContains('failed', $dependencies);
        foreach (['technical' => 'Adam', 'business' => 'Victoria'] as $label => $name) {
            $scoped = $this->http($name, 'GET', '/queue?department_id='.$departments[$label]);
            $this->assertCount(1, $scoped['items']);
            $this->assertSame($actions[$label]['open']['action_id'], $scoped['items'][0]['action_id']);
        }
        $this->http('Adam', 'GET', '/queue?department_id='.$departments['business'], status: 403);
        $f = $actions['technical'];
        $id = $f['open']['action_id'];
        $correction = array_replace($f['supportBody'], ['expected_revision' => 4, 'predecessor_support_id' => $f['support']['support_id'], 'outcome' => 'unresolved', 'text' => 'Synthetic correction remains unresolved']);
        $changed = $this->create('Steve', '/actions/'.$id.'/support', $this->managementEnvelope('support.correct', $correction, 'crm-support-technical', 2), 'crm-correct', true, 1);
        $this->assertNull($changed['current_outcome']);
        $history = $this->http('John', 'GET', '/history/'.$id);
        $this->assertNotEmpty($history['items']);
        $this->assertSame($before, $this->sourceSnapshot());
        $grant = app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('user_id', $personas['Steve']->id)->where('purpose', 'management.read')->value('id'));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $this->http('Steve', 'GET', '/queue', status: 403);
        $this->http('Steve', 'HEAD', '/queue', status: 403);
        $this->crmEvidence = ['personas' => require __DIR__.'/../Fixtures/management-crm.php', 'source_snapshot' => $before, 'dependencies' => $dependencies, 'correction_clears_current_outcome' => true, 'revocation_denies_get_and_head' => true, 'pdo_unchanged' => true];
    }

    public function diagnostic($application): array
    {
        $this->app = $application;
        foreach (['tenant', 'user', 'employee', 'policy'] as $field) {
            $this->$field = (string) Str::uuid();
        }
        $assertions = Assert::getCount();
        $failure = null;
        DB::beginTransaction();
        try {
            $this->runCrm();
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($this->crmPdo, DB::connection()->getPdo());
        $tables = DB::select("select table_name from information_schema.columns where table_schema='public' and column_name='tenant_id' order by table_name");
        $absence = app(TenantContext::class)->run($this->tenant, function () use ($tables) {
            $counts = ['tenants' => DB::table('tenants')->where('id', $this->tenant)->count()];
            foreach ($tables as $table) {
                $counts[$table->table_name] = DB::table($table->table_name)->where('tenant_id', $this->tenant)->count();
                $this->assertSame(0, $counts[$table->table_name], $table->table_name);
            }

            return $counts;
        });
        foreach ($this->crmTokens as $token) {
            $this->assertNull(app(LocalTokenAuthenticator::class)->authenticate($token));
        }
        $this->assertSame(0, array_sum($absence));
        $this->assertSame(0, DB::transactionLevel());
        if ($failure) {
            throw $failure;
        }

        return $this->crmEvidence + ['assertions' => Assert::getCount() - $assertions, 'absence_counts' => $absence, 'all_tokens_unauthenticated' => true, 'transaction_level' => 0, 'inventory_limit' => 'public tenant_id columns and exact tenants row; nonpublic/no-tenant tables excluded'];
    }
}
