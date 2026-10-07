<?php

namespace Tests\Feature;

use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaHandoffService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QaTestCase;

final class QaConcurrencyTest extends QaTestCase
{
    public static function operations(): array
    {
        return [['selection'], ['definition-review'], ['defect'], ['handoff']];
    }

    public static function expiryModes(): array
    {
        return [['wait'], ['work']];
    }

    #[DataProvider('expiryModes')]
    public function test_actual_database_clock_expiry_during_wait_or_work_rolls_back_all_writes(string $mode): void
    {
        $this->qaInputs();
        [$body] = $this->executionInputs();
        $expiry = $this->qaRun(function () {
            $expiry = DB::scalar("select clock_timestamp()+interval '5 seconds'");
            DB::table('access_grants')->where('project_id', $this->p)->where('user_id', $this->user)->where('purpose', 'qa.handoff.manage')->update(['valid_to' => $expiry]);

            return $expiry;
        });
        $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'expiry');
        $pins = ['handoff_id' => $handoff['id'], 'event' => ['expected_revision' => 1, 'kind' => 'acknowledged', 'reason' => 'Synthetic natural expiry', 'pins' => new \stdClass]];
        if ($mode === 'work') {
            $pins['expire_after_insert'] = $expiry;
        }
        $before = $this->defectWriteCounts();
        $this->contendQaOperation('handoff', $pins, ['status=403', 'status=403'], $mode === 'wait' ? $expiry : null);
        $this->assertSame($before, $this->defectWriteCounts());
    }

    #[DataProvider('operations')]
    public function test_real_overlapping_cas_has_one_winner_and_one_conflict(string $mode): void
    {
        $this->qaInputs();
        if ($mode === 'selection') {
            $plan = $this->approvedPlan();
            $build = $this->qaBuild();
            $environment = $this->qaEnvironment();
            $pins = ['expected_revision' => 0, 'plan_version_id' => $plan['id'], 'build_id' => $build['record_id'], 'environment_version_id' => $environment['record_id'], 'reason' => 'Synthetic CAS'];
        } elseif ($mode === 'definition-review') {
            $definition = $this->reviewedDefinition();
            $pins = ['definition_id' => $definition['id'], 'decision' => ['expected_revision' => 1, 'decision' => 'rejected', 'reason' => 'Synthetic CAS']];
        } elseif ($mode === 'defect') {
            [$body] = $this->executionInputs();
            $defect = app(QaDefectService::class)->report($this->actor(), $this->p, $this->defectReport($body), 'cas');
            $pins = ['defect_id' => $defect['id'], 'event' => ['expected_revision' => 1, 'kind' => 'triaged', 'reason' => 'Synthetic CAS', 'pins' => new \stdClass]];
        } else {
            [$body] = $this->executionInputs();
            $handoff = app(QaHandoffService::class)->open($this->actor(), $this->p, $this->handoffData($body), 'cas');
            $pins = ['handoff_id' => $handoff['id'], 'event' => ['expected_revision' => 1, 'kind' => 'acknowledged', 'reason' => 'Synthetic CAS', 'pins' => new \stdClass]];
        }
        $before = $this->qaRun(fn () => DB::table('qa_changes')->where('project_id', $this->p)->count());
        $this->contendQaOperation($mode, $pins);
        $after = $this->qaRun(fn () => DB::table('qa_changes')->where('project_id', $this->p)->count());
        $this->assertSame($before + 1, $after);
        $this->qaRun(function () use ($mode, $pins) {
            $revision = match ($mode) {
                'selection' => DB::table('qa_project_state')->where('project_id', $this->p)->value('selection_revision'),
                'definition-review' => DB::table('qa_definition_versions')->where('id', $pins['definition_id'])->value('review_revision'),
                'defect' => DB::table('qa_defects')->where('id', $pins['defect_id'])->value('revision'),
                'handoff' => DB::table('qa_handoffs')->where('id', $pins['handoff_id'])->value('revision'),
            };
            $this->assertSame($mode === 'selection' ? 1 : 2, $revision);
        });
    }
}
