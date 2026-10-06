<?php

namespace Tests\Feature;

use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\QaObservationService;
use App\Domain\Lifecycle\RequirementService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\BaselineTestCase;

class AcceptedProgressTest extends BaselineTestCase
{
    public function test_exact_67_of_100(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $this->accept($this->evidence());
        $r = $this->progress();
        $this->assertSame(67, $r['accepted_weight']);
        $this->assertSame(100, $r['total_weight']);
        $this->assertSame(6700, $r['percentage_basis_points']);
        $this->assertStringNotContainsString('Sensitive', json_encode($r));
    }

    public function test_done_failed_qa_zero(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $l = $this->evidence();
        $this->accept($l);
        app(QaObservationService::class)->record($this->actor(), $this->p, ['criterion_version_id' => $this->criteria[0]['version_id'], 'link_id' => $l['id'], 'test_key' => 'test-a', 'result' => 'failed'], 1, $this->generation);
        $r = $this->progress();
        $this->assertSame(0, $r['accepted_weight']);
        $this->assertContains('qa_failed', $r['coverage_reason_codes']);
    }

    public function test_scope_120_keeps_old_100_snapshot(): void
    {
        $this->inputs();
        $old = $this->baseline($this->scope());
        $d = $this->accept($this->evidence());
        $this->baseline($this->scope([67, 20, 33], $d['id']));
        $r = $this->progress();
        $this->assertSame(67, $r['accepted_weight']);
        $this->assertSame(120, $r['total_weight']);
        $this->assertSame(5583, $r['percentage_basis_points']);
        $historical = $this->progress($old['id']);
        $this->assertSame(67, $historical['accepted_weight']);
        $this->assertSame(100, $historical['total_weight']);
        $this->assertSame(100, $r['previous_snapshot']['total_weight']);
        app(TenantContext::class)->run($this->tenant, fn () => $this->assertSame($old['id'], DB::table('human_acceptance_decisions')->where('id', $d['id'])->value('baseline_id')));
        $this->baseline($this->scope([67, 20, 33]));
        $this->assertSame(0, $this->progress()['accepted_weight']);
    }

    public function test_draft_and_unrelated_change_do_not_stale(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $this->accept($this->evidence());
        app(RequirementService::class)->createDraft($this->actor(), $this->p, $this->requirement());
        app(DodPolicyService::class)->approve($this->actor(), $this->p, ['fictional' => true, 'mandatory_qa' => true, 'human_acceptance' => true], $this->generation);
        $this->assertSame(67, $this->progress()['accepted_weight']);
    }

    public function test_revoked_viewer_source_access_unknown_current_and_historical(): void
    {
        $this->inputs();
        $old = $this->baseline($this->scope());
        $d = $this->accept($this->evidence());
        $this->baseline($this->scope([67, 20, 33], $d['id']));
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('id', $this->readGrant)->update(['revoked_at' => now()]));
        foreach ([null, $old['id']] as $id) {
            $r = $this->progress($id);
            $this->assertSame('unavailable', $r['status']);
            $this->assertNull($r['percentage_basis_points']);
            $this->assertSame(0, $r['accepted_weight']);
            $this->assertContains('source_access_unavailable', $r['coverage_reason_codes']);
        }
    }

    public function test_progress_read_is_coherent_and_nonmutating(): void
    {
        $this->inputs();
        $this->baseline($this->scope());
        $this->accept($this->evidence());
        $before = app(TenantContext::class)->run($this->tenant, fn () => [DB::table('lifecycle_project_state')->first(), DB::table('lifecycle_changes')->count(), DB::table('baseline_progress_snapshots')->count()]);
        $this->withToken($this->token);
        $this->getJson('/api/v1/projects/'.$this->p.'/progress')->assertOk()->assertJsonPath('data.accepted_weight', 67);
        $this->call('HEAD', '/api/v1/projects/'.$this->p.'/progress', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token])->assertOk();
        $after = app(TenantContext::class)->run($this->tenant, fn () => [DB::table('lifecycle_project_state')->first(), DB::table('lifecycle_changes')->count(), DB::table('baseline_progress_snapshots')->count()]);
        $this->assertEquals($before, $after);
    }
}
