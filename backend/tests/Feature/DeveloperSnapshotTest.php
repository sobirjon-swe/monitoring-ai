<?php

namespace Tests\Feature;

use App\Domain\Access\AccessGateway;
use App\Domain\DeveloperAnalysis\DeveloperAuthority;
use App\Domain\DeveloperAnalysis\DeveloperContract;
use App\Domain\DeveloperAnalysis\DeveloperCoordination;
use App\Domain\DeveloperAnalysis\SecretGate;
use App\Domain\DeveloperAnalysis\SyntheticMrRegistry;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\DeveloperAnalysisTestCase;

class DeveloperSnapshotTest extends DeveloperAnalysisTestCase
{
    public function test_canonical_json_sorts_objects_but_retains_list_order(): void
    {
        $this->assertSame('{"a":[2,1],"z":"o‘zbek"}', DeveloperContract::canonical(['z' => 'o‘zbek', 'a' => [2, 1]]));
    }

    public function test_canonical_unicode_line_separators_match_utf8_worker_bytes(): void
    {
        $this->assertSame("{\"a\":[\"/\",\"\u{2028}\u{2029}\"],\"z\":\"o‘zbek\"}", DeveloperContract::canonical(['z' => 'o‘zbek', 'a' => ['/', "\u{2028}\u{2029}"]]));
    }

    public function test_project_only_analysis_purposes_reject_tenant_grants_and_missing_membership(): void
    {
        $this->fixture();
        $p = $this->project();
        foreach (DeveloperAuthority::PURPOSES as $purpose) {
            $this->grant($purpose);
            try {
                app(AccessGateway::class)->authorize($this->actor(), $purpose, ['tenant_id' => $this->tenant, 'project_id' => $p]);
                $this->fail('Tenant grant authorized analysis.');
            } catch (AccessDeniedHttpException) {
                $this->assertTrue(true);
            }
            $this->grant($purpose, $p);
            app(AccessGateway::class)->authorize($this->actor(), $purpose, ['tenant_id' => $this->tenant, 'project_id' => $p]);
        }
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->where('project_id', $p)->update(['valid_to' => now()->subSecond()]));
        $this->expectException(AccessDeniedHttpException::class);
        app(AccessGateway::class)->authorize($this->actor(), 'analysis.read', ['tenant_id' => $this->tenant, 'project_id' => $p]);
    }

    public function test_line_redaction_preserves_prefix_newlines_and_coordinates(): void
    {
        $diff = str_replace('+new', '+password="synthetic-secret-value"', $this->mr()['diff']);
        $safe = app(SecretGate::class)->inspect($diff, 'redact-lines-v1');
        $this->assertTrue($safe['accepted']);
        $this->assertStringNotContainsString('synthetic-secret-value', $safe['text']);
        $this->assertStringContainsString("+[REDACTED]\n", $safe['text']);
        $this->assertSame([['diff_line' => 6, 'prefix' => '+']], $safe['mapping']);
        $this->assertSame(substr_count($diff, "\n"), substr_count($safe['text'], "\n"));
    }

    public function test_secret_header_and_default_secret_policy_block_without_raw_text(): void
    {
        foreach ([['diff' => "+password='synthetic-secret-value'\n", 'policy' => 'block-v1'], ['diff' => "diff --git a/password=synthetic-secret-value b/x\n", 'policy' => 'redact-lines-v1']] as $case) {
            $safe = app(SecretGate::class)->inspect($case['diff'], $case['policy']);
            $this->assertFalse($safe['accepted']);
            $this->assertArrayNotHasKey('text', $safe);
            $this->assertStringNotContainsString('synthetic-secret-value', json_encode($safe));
        }
    }

    public function test_two_revisions_retain_both_source_events_and_late_ordinal_keeps_head(): void
    {
        $p = $this->developerProject();
        $registry = app(SyntheticMrRegistry::class);
        $first = $registry->register($this->actor(), $p, $this->mr(), 0);
        $next = $this->mr();
        $next['revision_ordinal'] = 3;
        $next['head_sha'] = str_repeat('c', 40);
        $latest = $registry->register($this->actor(), $p, $next, 1);
        $next['revision_ordinal'] = 2;
        $next['head_sha'] = str_repeat('d', 40);
        $late = $registry->register($this->actor(), $p, $next, 2);
        $this->assertFalse($late['current']);
        app(TenantContext::class)->run($this->tenant, function () use ($latest): void {
            $this->assertSame(3, DB::table('developer_snapshots')->count());
            $this->assertSame(3, DB::table('developer_source_events')->count());
            $this->assertSame(3, DB::table('developer_outbox')->count());
            $this->assertSame($latest['snapshot_id'], DB::table('developer_source_heads')->value('snapshot_id'));
        });
        $this->assertNotSame($first['snapshot_id'], $latest['snapshot_id']);
    }

    public function test_snapshot_is_invisible_without_tenant_context_and_immutable(): void
    {
        $p = $this->developerProject();
        $row = app(SyntheticMrRegistry::class)->register($this->actor(), $p, $this->mr(), 0);
        $this->assertSame(0, DB::table('developer_snapshots')->count());
        foreach (['update', 'delete'] as $op) {
            try {
                app(TenantContext::class)->run($this->tenant, function () use ($row, $op): void {
                    $q = DB::table('developer_snapshots')->where('id', $row['snapshot_id']);
                    $op === 'update' ? $q->update(['safe_diff' => 'changed']) : $q->delete();
                });
                $this->fail('Immutable snapshot mutation succeeded.');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0]);
            }
        }
    }

    public function test_cross_project_source_event_binding_is_rejected(): void
    {
        $p = $this->developerProject();
        $other = $this->project();
        $row = app(SyntheticMrRegistry::class)->register($this->actor(), $p, $this->mr(), 0);
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('developer_source_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $other,
            'snapshot_id' => $row['snapshot_id'], 'source_instance_id' => 'synthetic-gitlab', 'repository_id' => 'synthetic-repository', 'mr_id' => '42', 'revision_ordinal' => 1,
            'payload_hash' => str_repeat('a', 64), 'reason_code' => 'approved', 'actor_id' => $this->user,
        ]));
    }

    public function test_unknown_keys_malformed_sha_and_unsafe_paths_are_rejected_before_storage(): void
    {
        $p = $this->developerProject();
        foreach ([['unknown' => 'x'], ['base_sha' => 'not-sha'], ['diff' => "diff --git a/../secret b/../secret\n"]] as $change) {
            try {
                app(SyntheticMrRegistry::class)->register($this->actor(), $p, array_replace($this->mr(), $change), 0);
                $this->fail('Unsafe fixture accepted.');
            } catch (InvalidArgumentException) {
                app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(0, DB::table('developer_snapshots')->count()));
            }
        }
    }

    public function test_authority_proof_cannot_use_replacement_grant_and_batch_is_sorted(): void
    {
        $p = $this->developerProject();
        $proof = app(DeveloperCoordination::class)->run($this->actor(), $p, ['analysis.request', 'analysis.source.read'], true, fn ($proofs) => $proofs[$this->user]);
        $grant = $proof['grants'][0]['id'];
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $grant)->update(['revoked_at' => now()]));
        $this->grant($proof['grants'][0]['purpose'], $p);
        $valid = app(TenantContext::class)->run($this->tenant, fn () => app(DeveloperAuthority::class)->valid($this->actor(), $p, $proof));
        $this->assertFalse($valid);
    }

    public function test_input_contract_rejects_unknown_keys_bad_hash_and_forged_redaction_mapping(): void
    {
        $p = $this->developerProject();
        $row = $this->storedRun($p);
        $input = $row['input'];
        $this->assertSame($input, app(DeveloperContract::class)->input($input));
        foreach (['unknown', 'hash', 'mapping'] as $mutation) {
            $bad = $input;
            if ($mutation === 'unknown') {
                $bad['unknown'] = true;
            }
            if ($mutation === 'hash') {
                $bad['input_hash'] = str_repeat('0', 64);
            }
            if ($mutation === 'mapping') {
                $bad['snapshot']['redaction']['mapping'] = [['diff_line' => 6, 'prefix' => '+']];
                unset($bad['input_hash']);
                $bad['input_hash'] = hash('sha256', DeveloperContract::canonical($bad));
            }
            try {
                app(DeveloperContract::class)->input($bad);
                $this->fail('Invalid envelope accepted: '.$mutation);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_blocked_registration_stores_only_reason_and_hash_without_execution_run(): void
    {
        $p = $this->developerProject();
        $mr = $this->mr();
        $mr['diff'] = str_replace('+new', '+password=synthetic-secret-value', $mr['diff']);
        $row = app(SyntheticMrRegistry::class)->register($this->actor(), $p, $mr, 0);
        $this->assertFalse($row['accepted']);
        $this->assertNull($row['snapshot_id']);
        app(TenantContext::class)->run($this->tenant, function (): void {
            $this->assertSame(0, DB::table('developer_snapshots')->count());
            $this->assertSame(0, DB::table('developer_runs')->count());
            $event = DB::table('developer_source_events')->first();
            $this->assertSame('secret_blocked', $event->reason_code);
            $this->assertStringNotContainsString('synthetic-secret-value', json_encode($event));
        });
    }

    public function test_run_input_and_review_history_cannot_be_mutated(): void
    {
        $p = $this->developerProject();
        $run = $this->storedRun($p);
        $finding = (string) Str::uuid();
        $review = (string) Str::uuid();
        app(TenantContext::class)->run($this->tenant, function () use ($p, $run, $finding, $review): void {
            $result = (string) Str::uuid();
            DB::table('developer_results')->insert(['id' => $result, 'tenant_id' => $this->tenant, 'project_id' => $p, 'snapshot_id' => $run['snapshot_id'], 'run_id' => $run['id'], 'input_hash' => $run['input']['input_hash'], 'output_hash' => str_repeat('a', 64), 'output' => '{}', 'processing_authority' => $run['authority'], 'processing_authority_hash' => hash('sha256', $run['authority'])]);
            DB::table('developer_findings')->insert(['id' => $finding, 'tenant_id' => $this->tenant, 'project_id' => $p, 'snapshot_id' => $run['snapshot_id'], 'run_id' => $run['id'], 'result_id' => $result, 'kind' => 'composition_fact', 'facts' => '{}', 'summary' => 'Synthetic counts']);
            DB::table('developer_reviews')->insert(['id' => $review, 'tenant_id' => $this->tenant, 'project_id' => $p, 'finding_id' => $finding, 'revision' => 1, 'decision' => 'rejected', 'disposition' => 'rejected', 'actor_id' => $this->user, 'reason' => 'Synthetic counterevidence']);
            DB::table('developer_findings')->where('id', $finding)->update(['review_revision' => 1, 'current_review_id' => $review, 'disposition' => 'rejected']);
        });
        foreach ([['developer_runs', $run['id'], ['input_hash' => str_repeat('b', 64)]], ['developer_reviews', $review, ['reason' => 'Rewritten history']], ['developer_findings', $finding, ['facts' => '{"changed":true}']]] as [$table, $id, $values]) {
            try {
                app(TenantContext::class)->run($this->tenant, fn () => DB::table($table)->where('id', $id)->update($values));
                $this->fail('History rewrite accepted.');
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[0], ['42501', '23514']);
            }
        }
    }

    public function test_same_actor_requester_and_processor_pin_subset_is_checked_under_union_locks(): void
    {
        $p = $this->developerProject();
        $coordination = app(DeveloperCoordination::class);
        $original = $coordination->run($this->actor(), $p, ['analysis.request', 'analysis.source.read'], true, fn ($proofs) => $proofs[$this->user]);
        $requests = [['actor_id' => $this->user, 'purposes' => $original['purposes'], 'pinned_proof' => $original]];
        $union = $coordination->run($this->actor(), $p, ['analysis.process', 'analysis.source.read'], false, fn ($proofs) => $proofs[$this->user], $requests);
        $this->assertSame(['analysis.process', 'analysis.request', 'analysis.source.read'], $union['purposes']);
        foreach ($original['grants'] as $grant) {
            $this->assertContains($grant, $union['grants']);
        }
        $originalGrant = collect($original['grants'])->firstWhere('purpose', 'analysis.source.read');
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $originalGrant['id'])->update(['revoked_at' => now()]));
        $this->grant('analysis.source.read', $p);
        $this->expectException(AccessDeniedHttpException::class);
        $coordination->run($this->actor(), $p, ['analysis.process', 'analysis.source.read'], false, fn ($proofs) => $proofs, $requests);
    }

    public function test_short_credentials_and_ambiguous_multiline_secrets_are_blocked(): void
    {
        $gate = app(SecretGate::class);
        $this->assertFalse($gate->inspect("+api_key=abc\n", 'block-v1')['accepted']);
        $this->assertFalse($gate->inspect("+password=\"start\n+continued\"\n", 'redact-lines-v1')['accepted']);
        $this->assertFalse($gate->inspect("+Authorization: Basic YWJjOmRlZg==\n", 'block-v1')['accepted']);
        $this->expectException(InvalidArgumentException::class);
        $gate->safeText('api_key=abc');
    }

    public function test_run_cannot_start_without_attempt_increment_or_retain_half_lease(): void
    {
        $p = $this->developerProject();
        $run = $this->storedRun($p);
        foreach ([['status' => 'running', 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinute()], ['lease_token' => (string) Str::uuid()]] as $values) {
            try {
                app(TenantContext::class)->run($this->tenant, fn () => DB::table('developer_runs')->where('id', $run['id'])->update($values));
                $this->fail('Invalid execution lease accepted.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
    }

    public function test_authority_locks_block_direct_revocation_in_a_second_runtime_session(): void
    {
        $p = $this->developerProject();
        $this->assertStringStartsWith('monitoring-ai-local-v1:', DB::scalar("select shobj_description(oid,'pg_database') from pg_database where datname=current_database()"));
        DB::commit(); // Retain a synthetic fixture in the guarded dedicated database; no history cleanup.
        config(['database.connections.developer_contender' => config('database.connections.pgsql')]);
        $other = DB::connection('developer_contender');
        $this->assertSame('monitoring_ai_test', $other->scalar('select current_database()'));
        $this->assertSame('monitoring_ai_app', $other->scalar('select current_user'));
        try {
            $proof = app(DeveloperCoordination::class)->run($this->actor(), $p, ['analysis.request', 'analysis.source.read'], true, function ($proofs) use ($other): array {
                $proof = $proofs[$this->user];
                foreach ([['tenants', $this->tenant, ['status' => 'inactive']], ['users', $this->user, ['status' => 'inactive']], ['project_memberships', $proof['membership']['id'], ['valid_to' => now()]], ['access_grants', $proof['grants'][0]['id'], ['revoked_at' => now()]], ['policy_versions', $this->policy, ['status' => 'retired']]] as [$table, $id, $values]) {
                    $other->beginTransaction();
                    try {
                        $other->select("select set_config('app.tenant_id',?,true)", [$this->tenant]);
                        $other->statement("SET LOCAL lock_timeout='100ms'");
                        $other->table($table)->where('id', $id)->update($values);
                        $this->fail('Direct authority mutation bypassed held shared lock.');
                    } catch (QueryException $e) {
                        $this->assertSame('55P03', $e->errorInfo[0]);
                    } finally {
                        $other->rollBack();
                    }
                }

                return $proof;
            });
            app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $proof['grants'][0]['id'])->update(['revoked_at' => now()]));
            $this->assertFalse(app(TenantContext::class)->run($this->tenant, fn () => app(DeveloperAuthority::class)->valid($this->actor(), $p, $proof)));
        } finally {
            DB::purge('developer_contender');
        }
    }

    public function test_expired_grant_is_rejected_using_database_clock_despite_frozen_application_time(): void
    {
        $p = $this->developerProject();
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'analysis.request')->update(['valid_from' => now()->subDays(2), 'valid_to' => now()->subHour()]));
        $this->travelTo(now()->subDay());
        try {
            $this->expectException(AccessDeniedHttpException::class);
            app(DeveloperCoordination::class)->run($this->actor(), $p, ['analysis.request'], false, fn ($proofs) => $proofs);
        } finally {
            $this->travelBack();
        }
    }

    public function test_output_contract_rejects_arbitrary_prose_unsafe_locators_and_excess_locator_count(): void
    {
        $p = $this->developerProject();
        $input = $this->storedRun($p)['input'];
        $zero = ['added' => 0, 'deleted' => 0];
        $counts = ['added' => 1, 'deleted' => 1];
        $coverage = ['status' => 'complete', 'notices' => []];
        $output = ['version' => 'developer-analysis-output-v1', 'run_id' => $input['run_id'], 'request_id' => $input['request_id'], 'input_hash' => $input['input_hash'], 'pins' => $input['pins'], 'analyzer_version' => 'unified-diff-v1', 'rule_version' => 'developer-taxonomy-v1',
            'snapshot' => array_intersect_key($input['snapshot'], array_flip(['id', 'hash', 'base_sha', 'head_sha'])), 'redaction_version' => 'secret-gate-v1', 'redaction_hash' => $input['snapshot']['hash'],
            'counts' => $counts, 'buckets' => ['generated' => $zero, 'vendor' => $zero, 'application' => $counts, 'tests' => $zero, 'config' => $zero, 'docs' => $zero, 'unknown' => $zero], 'coverage' => $coverage,
            'files' => [['old_path' => 'app/example.php', 'new_path' => 'app/example.php', 'bucket' => 'application', 'counts' => $counts, 'coverage' => $coverage, 'flags' => []]], 'locators' => [], 'omitted_locator_count' => 2, 'patch' => ['status' => 'exact', 'hash' => $input['snapshot']['hash']]];
        $this->assertSame($output, app(DeveloperContract::class)->output($output));
        $locator = ['snapshot_id' => $input['snapshot']['id'], 'snapshot_hash' => $input['snapshot']['hash'], 'base_sha' => $input['snapshot']['base_sha'], 'head_sha' => $input['snapshot']['head_sha'], 'path' => 'app/example.php', 'side' => 'new', 'line' => 1, 'hunk_index' => 0, 'kind' => 'added'];
        foreach (['prose', 'path', 'limit'] as $mutation) {
            $bad = $output;
            if ($mutation === 'prose') {
                $bad['summary'] = 'Unsupported quality claims';
            }
            if ($mutation === 'path') {
                $bad['locators'] = [array_replace($locator, ['path' => '../secret'])];
            }
            if ($mutation === 'limit') {
                $bad['locators'] = array_fill(0, 201, $locator);
            }
            try {
                app(DeveloperContract::class)->output($bad);
                $this->fail('Unsafe parser output accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_cross_tenant_child_write_cannot_reference_snapshot_from_another_tenant(): void
    {
        $p = $this->developerProject();
        $row = app(SyntheticMrRegistry::class)->register($this->actor(), $p, $this->mr(), 0);
        $other = (string) Str::uuid();
        app(TenantContext::class)->run($other, fn () => DB::table('tenants')->insert(['id' => $other, 'name' => 'Synthetic second tenant']));
        $this->expectException(QueryException::class);
        app(TenantContext::class)->run($other, fn () => DB::table('developer_source_heads')->insert(['tenant_id' => $other, 'project_id' => $p, 'source_instance_id' => 'synthetic-gitlab', 'repository_id' => 'synthetic-repository', 'mr_id' => '42', 'revision_ordinal' => 1, 'revision' => 1, 'snapshot_id' => $row['snapshot_id']]));
    }

    public function test_quoted_json_and_php_credential_keys_are_blocked_before_persistence_or_safe_text(): void
    {
        $p = $this->developerProject();
        $gate = app(SecretGate::class);
        foreach (['{"password":"synthetic-value"}', "['api_key' => 'synthetic-value']"] as $text) {
            $safe = $gate->inspect('+'.$text."\n", 'block-v1');
            $this->assertFalse($safe['accepted']);
            $this->assertArrayNotHasKey('text', $safe);
            try {
                $gate->safeText($text);
                $this->fail('Quoted credential free text was returned.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
            $mr = $this->mr();
            $mr['diff'] = str_replace('+new', '+'.$text, $mr['diff']);
            $receipt = app(SyntheticMrRegistry::class)->register($this->actor(), $p, $mr, 0);
            $this->assertFalse($receipt['accepted']);
            $this->assertNull($receipt['snapshot_id']);
        }
        app(TenantContext::class)->run($this->tenant, function (): void {
            $this->assertSame(0, DB::table('developer_snapshots')->count());
            $this->assertSame(0, DB::table('developer_runs')->count());
            $this->assertSame(2, DB::table('developer_source_events')->count());
            $this->assertStringNotContainsString('synthetic-value', json_encode(DB::table('developer_source_events')->get()));
        });
    }

    public function test_yaml_block_credentials_reject_entire_redaction_without_retaining_continuation(): void
    {
        $p = $this->developerProject();
        foreach (['|', '>', '|-', '|+', '>2-', '>+2'] as $indicator) {
            $lines = '+password: '.$indicator."\n+  synthetic-value";
            $safe = app(SecretGate::class)->inspect($lines."\n", 'redact-lines-v1');
            $this->assertFalse($safe['accepted']);
            $this->assertArrayNotHasKey('text', $safe);
            $this->assertStringNotContainsString('synthetic-value', json_encode($safe));
            $mr = $this->mr();
            $mr['redaction_policy'] = 'redact-lines-v1';
            $mr['diff'] = str_replace('+new', $lines, $mr['diff']);
            $receipt = app(SyntheticMrRegistry::class)->register($this->actor(), $p, $mr, 0);
            $this->assertFalse($receipt['accepted']);
            $this->assertNull($receipt['snapshot_id']);
        }
        app(TenantContext::class)->run($this->tenant, function (): void {
            $this->assertSame(0, DB::table('developer_snapshots')->count());
            $this->assertSame(0, DB::table('developer_runs')->count());
            $this->assertSame(6, DB::table('developer_source_events')->count());
            $this->assertStringNotContainsString('synthetic-value', json_encode(DB::table('developer_source_events')->get()));
        });
    }

    public function test_single_line_quoted_credentials_still_redact_without_retaining_value(): void
    {
        foreach (['{"password":"synthetic-value"}', "['api_key' => 'synthetic-value']"] as $text) {
            $safe = app(SecretGate::class)->inspect('+'.$text."\n", 'redact-lines-v1');
            $this->assertTrue($safe['accepted']);
            $this->assertSame("+[REDACTED]\n", $safe['text']);
            $this->assertSame([['diff_line' => 1, 'prefix' => '+']], $safe['mapping']);
        }
    }

    public function test_grant_expiry_during_callback_denies_and_rolls_back_action(): void
    {
        $p = $this->developerProject();
        $entered = false;
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'analysis.request')->update(['valid_to' => DB::raw("clock_timestamp()+interval '300 milliseconds'")]));
        try {
            app(DeveloperCoordination::class)->run($this->actor(), $p, ['analysis.request'], true, function () use (&$entered): void {
                $entered = true;
                DB::table('employees')->where('id', $this->employee)->update(['display_name' => 'Must roll back']);
                DB::select('select pg_sleep(0.5)');
            });
            $this->fail('Authority expired during callback but action committed.');
        } catch (AccessDeniedHttpException) {
            $this->assertTrue($entered, 'The expiry regression must reach the transaction callback.');
            app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame('Synthetic employee', DB::table('employees')->where('id', $this->employee)->value('display_name')));
        }
    }
}
