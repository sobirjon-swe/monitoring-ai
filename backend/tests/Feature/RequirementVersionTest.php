<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\LifecycleChangeWriter;
use App\Domain\Lifecycle\ProjectCoordination;
use App\Domain\Lifecycle\RequirementService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LifecycleTestCase;

class RequirementVersionTest extends LifecycleTestCase
{
    public function test_multilingual_replacement_and_removal_reasons_commit_changes(): void
    {
        $p = $this->lifecycle();
        $base = '/api/v1/projects/'.$p;
        $this->withToken($this->token);
        $draft = $this->postJson($base.'/requirements', $this->requirement())->assertCreated()->json('data.id');
        $url = $base.'/requirement-drafts/'.$draft;
        $reason = str_repeat('Ш', 251);
        $this->postJson($url.'/approve', ['expected_revision' => 1, 'expected_generation' => 0, 'reason' => $reason])->assertCreated();
        $data = $this->requirement();
        $data['criteria'][0]['text'] = 'Merge';
        $this->patchJson($url, $data + ['expected_revision' => 1])->assertOk();
        $this->postJson($url.'/approve', ['expected_revision' => 2, 'expected_generation' => 1, 'reason' => $reason])->assertCreated();
        array_pop($data['criteria']);
        $this->patchJson($url, $data + ['expected_revision' => 2])->assertOk();
        $this->postJson($url.'/approve', ['expected_revision' => 3, 'expected_generation' => 2, 'reason' => $reason])->assertCreated();
        app(TenantContext::class)->run($this->tenant, function () use ($reason): void {
            $this->assertSame(['criterion_replaced', 'criterion_removed'], DB::table('lifecycle_changes')->orderBy('generation')->pluck('subject_kind')->all());
            $this->assertSame([$reason, $reason], DB::table('lifecycle_changes')->orderBy('generation')->pluck('reason')->all());
            $this->assertSame(2, DB::table('lifecycle_outbox')->count());
            $this->assertSame(3, DB::table('lifecycle_project_state')->value('generation'));
        });
    }

    public function test_requirement_join_rejects_criterion_from_another_requirement(): void
    {
        $p = $this->lifecycle();
        $s = app(RequirementService::class);
        $d = $s->createDraft($this->actor(), $p, $this->requirement());
        $a = $s->approve($this->actor(), $p, $d['id'], 1, 0, 'Synthetic');
        $e = $s->createDraft($this->actor(), $p, $this->requirement());
        $b = $s->approve($this->actor(), $p, $e['id'], 1, 1, 'Synthetic');
        app(TenantContext::class)->run($this->tenant, function () use ($p, $a, $b) {
            try {
                DB::transaction(fn () => DB::table('requirement_version_criteria')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $p, 'requirement_version_id' => $a['id'], 'requirement_id' => $a['requirement_id'], 'criterion_id' => $b['criteria'][0]['id'], 'criterion_version_id' => $b['criteria'][0]['version_id']]));
                $this->fail('Cross-requirement join accepted');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
        });
    }

    public function test_change_writer_requires_active_coordination_and_nested_run_cannot_advance_twice(): void
    {
        $p = $this->lifecycle();
        app(DodPolicyService::class)->approve($this->actor(), $p, ['mandatory_qa' => true, 'human_acceptance' => true, 'fictional' => true], 0);
        try {
            app(TenantContext::class)->run($this->tenant, fn () => app(LifecycleChangeWriter::class)->record($this->actor(), $p, 'requirements.approve', 'criterion_removed', (string) Str::uuid(), 'v2', 'Synthetic'));
            $this->fail('Uncoordinated change accepted');
        } catch (\LogicException $e) {
            $this->assertSame('Lifecycle change requires coordination.', $e->getMessage());
        }
        $coordinator = app(ProjectCoordination::class);
        try {
            $coordinator->run($this->actor(), $p, 'requirements.approve', 1, fn () => $coordinator->run($this->actor(), $p, 'requirements.approve', 1, fn () => []));
            $this->fail('Nested mutation accepted');
        } catch (\LogicException $e) {
            $this->assertSame('Nested lifecycle mutation is prohibited.', $e->getMessage());
        }
        app(TenantContext::class)->run($this->tenant, function () {
            $this->assertSame(0, DB::table('lifecycle_changes')->count());
            $this->assertSame(1, DB::table('lifecycle_project_state')->value('generation'));
        });
    }

    public function test_http_lifecycle_and_stale_revision_generation_are_bounded(): void
    {
        $p = $this->lifecycle();
        $base = '/api/v1/projects/'.$p;
        $this->withToken($this->token);
        $d = $this->postJson($base.'/requirements', $this->requirement())->assertCreated()->json('data');
        $this->patchJson($base.'/requirement-drafts/'.$d['id'], $this->requirement() + ['expected_revision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson($base.'/requirement-drafts/'.$d['id'], $this->requirement() + ['expected_revision' => 1])->assertConflict();
        $this->postJson($base.'/requirement-drafts/'.$d['id'].'/approve', ['expected_revision' => 2, 'expected_generation' => 9, 'reason' => 'Synthetic'])->assertConflict();
        $this->postJson($base.'/requirement-drafts/'.$d['id'].'/approve', ['expected_revision' => 2, 'expected_generation' => 0, 'reason' => 'Synthetic'])->assertCreated()->assertJsonPath('data.generation', 1);
        $this->getJson($base.'/requirements')->assertOk()->assertJsonCount(1, 'data');
        $this->json('HEAD', $base.'/requirements')->assertOk();
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(1, DB::table('lifecycle_project_state')->value('generation')));
    }

    public function test_strict_json_rejects_duplicate_nested_keys_nul_scope_and_type_ambiguity(): void
    {
        $p = $this->lifecycle();
        $url = '/api/v1/projects/'.$p.'/requirements';
        $this->withToken($this->token);
        $raw = json_encode($this->requirement());
        foreach ([str_replace('"text":"Reject"', '"text":"Reject","text":"Merge"', $raw), str_replace('"title":"Synthetic requirement"', '"title":"Bad\\u0000"', $raw), str_replace('"dependencies":[]', '"dependencies":{}', $raw), json_encode($this->requirement() + ['tenant_id' => $this->tenant])] as $bad) {
            $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], $bad)->assertUnprocessable();
        }
        $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], str_repeat(' ', 65537))->assertStatus(413);
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(0, DB::table('requirements')->count()));
    }

    public function test_context_change_replaces_all_versions_administrative_change_replaces_none(): void
    {
        $p = $this->lifecycle();
        $s = app(RequirementService::class);
        $d = $s->createDraft($this->actor(), $p, $this->requirement());
        $a = $s->approve($this->actor(), $p, $d['id'], 1, 0, 'Synthetic');
        $data = $this->requirement();
        $data['stakeholder'] = 'Other synthetic stakeholder';
        $s->updateDraft($this->actor(), $p, $d['id'], $data, 1);
        $b = $s->approve($this->actor(), $p, $d['id'], 2, 1, 'Synthetic');
        $this->assertSame($a['criteria'], $b['criteria']);
        $data['business_outcome'] = 'Changed synthetic outcome';
        $s->updateDraft($this->actor(), $p, $d['id'], $data, 2);
        $c = $s->approve($this->actor(), $p, $d['id'], 3, 2, 'Synthetic');
        foreach ([0, 1] as $i) {
            $this->assertNotSame($a['criteria'][$i]['version_id'], $c['criteria'][$i]['version_id']);
        }
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(2, DB::table('lifecycle_changes')->count()));
    }

    public function test_removed_criterion_emits_one_change_and_policy_does_not_emit_changes(): void
    {
        $p = $this->lifecycle();
        $s = app(RequirementService::class);
        $d = $s->createDraft($this->actor(), $p, $this->requirement());
        $s->approve($this->actor(), $p, $d['id'], 1, 0, 'Synthetic');
        $data = $this->requirement();
        array_pop($data['criteria']);
        $s->updateDraft($this->actor(), $p, $d['id'], $data, 1);
        $s->approve($this->actor(), $p, $d['id'], 2, 1, 'Synthetic');
        app(DodPolicyService::class)->approve($this->actor(), $p, ['mandatory_qa' => true, 'human_acceptance' => true, 'fictional' => true], 2);
        app(TenantContext::class)->run($this->tenant, function () {
            $this->assertSame(1, DB::table('lifecycle_changes')->count());
            $this->assertSame('criterion_removed', DB::table('lifecycle_changes')->value('subject_kind'));
            $this->assertNull(DB::table('lifecycle_project_state')->value('current_dod_policy_id'));
        });
    }

    public function test_database_scopes_rls_and_atomic_coordination_rollback(): void
    {
        $p = $this->lifecycle();
        $other = $this->project();
        $s = app(RequirementService::class);
        $d = $s->createDraft($this->actor(), $p, $this->requirement());
        $this->assertSame(0, DB::table('requirement_drafts')->count());
        app(TenantContext::class)->run($this->tenant, function () use ($d, $other) {
            try {
                DB::transaction(fn () => DB::table('requirement_drafts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'project_id' => $other, 'requirement_id' => $d['requirement_id'], 'content' => '{}']));
                $this->fail('Cross-project draft accepted');
            } catch (QueryException $e) {
                $this->assertSame('23503', $e->errorInfo[0]);
            }
        });
        try {
            app(ProjectCoordination::class)->run($this->actor(), $p, 'requirements.approve', 0, function () {
                throw new \RuntimeException('Synthetic failure');
            });
            $this->fail('No rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic failure', $e->getMessage());
        }
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame(0, DB::table('lifecycle_project_state')->count()));
    }

    public function test_approved_versions_are_immutable_and_drafts_do_not_invalidate(): void
    {
        $p = $this->lifecycle();
        $s = app(RequirementService::class);
        $d = $s->createDraft($this->actor(), $p, $this->requirement());
        $d = $s->updateDraft($this->actor(), $p, $d['id'], $this->requirement(), 1);
        $this->assertSame(2, $d['revision']);
        app(TenantContext::class)->run($this->tenant, function () {
            $this->assertSame(0, DB::table('lifecycle_changes')->count());
            $this->assertSame(0, DB::table('lifecycle_outbox')->count());
        });
        $v = $s->approve($this->actor(), $p, $d['id'], 2, 0, 'Synthetic approval');
        app(TenantContext::class)->run($this->tenant, function () use ($v) {
            foreach (['update', 'delete'] as $op) {
                try {
                    DB::transaction(fn () => $op === 'update' ? DB::table('requirement_versions')->where('id', $v['id'])->update(['reason' => 'Changed']) : DB::table('requirement_versions')->where('id', $v['id'])->delete());
                    $this->fail('Immutable write accepted');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0]);
                }
            }
        });
    }

    public function test_single_changed_criterion_preserves_other_version(): void
    {
        $p = $this->lifecycle();
        $s = app(RequirementService::class);
        $d = $s->createDraft($this->actor(), $p, $this->requirement());
        $a = $s->approve($this->actor(), $p, $d['id'], 1, 0, 'Synthetic approval');
        $payload = $this->requirement();
        $payload['criteria'][0]['text'] = 'Merge';
        $s->updateDraft($this->actor(), $p, $d['id'], $payload, 1);
        $b = $s->approve($this->actor(), $p, $d['id'], 2, 1, 'Synthetic replacement');
        $this->assertNotSame($a['criteria'][0]['version_id'], $b['criteria'][0]['version_id']);
        $this->assertSame($a['criteria'][1]['version_id'], $b['criteria'][1]['version_id']);
        app(TenantContext::class)->run($this->tenant, function () {
            $this->assertSame(1, DB::table('lifecycle_changes')->count());
            $this->assertSame(1, DB::table('lifecycle_outbox')->count());
        });
    }

    public function test_unused_policy_has_no_effective_default(): void
    {
        $p = $this->lifecycle();
        $v = app(DodPolicyService::class)->approve($this->actor(), $p, ['mandatory_qa' => true, 'human_acceptance' => true, 'fictional' => true], 0);
        $this->assertNotEmpty($v['id']);
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertNull(DB::table('lifecycle_project_state')->where('project_id', $p)->value('current_dod_policy_id')));
    }

    public function test_explicit_project_grants_and_owner_scope(): void
    {
        $this->fixture();
        $p = $this->project();
        $this->grant('requirements.manage');
        $this->withToken($this->token)->postJson('/api/v1/projects/'.$p.'/requirements', $this->requirement())->assertForbidden();
        $this->grant('requirements.manage', $p);
        $v = $this->requirement();
        $v['owner_id'] = (string) Str::uuid();
        $this->postJson('/api/v1/projects/'.$p.'/requirements', $v)->assertUnprocessable();
    }
}
