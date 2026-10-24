<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementCoordination;
use App\Domain\Management\ManagementPins;
use App\Domain\Management\ManagementReadService;
use App\Domain\Management\ManagementReviewService;
use App\Domain\Management\ManagementSourceProofs;
use App\Domain\Management\ManagementSupportService;
use App\Support\LocalManagementDatabase;
use Tests\Support\ManagementTestCase;

final class ManagementProjectionBoundaryTest extends ManagementTestCase
{
    public function test_declined_subject_keeps_reason_without_a_projected_result(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $fixture = $this->workflowAssigned();
        $body = $this->workflowSupportBody($fixture);
        unset($body['text'], $body['outcome']);
        $body['reason'] = 'Synthetic decline remains unresolved';
        $decline = app(ManagementSupportService::class)->record($fixture['helper'], $this->p, $fixture['open']['action_id'], $this->managementEnvelope('support.decline', $body, 'decline-read'), 'decline-read', 0);
        $page = app(ManagementReadService::class)->queue($this->actor(), $this->p);
        $this->assertCount(1, $page['items']);
        $item = $page['items'][0];
        $this->assertSame('declined', $item['support_status']);
        $this->assertSame($decline['support_id'], $item['subject_support']['id']);
        $this->assertSame($body['reason'], $item['subject_support']['reason']);
        $this->assertNull($item['support']);
        $this->assertNull($item['projected_support_id']);
        $this->assertNull($item['current_outcome']);
    }

    public function test_rejected_subject_is_separate_from_the_cleared_projection(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $fixture = $this->workflowAssigned();
        $support = app(ManagementSupportService::class)->record($fixture['helper'], $this->p, $fixture['open']['action_id'], $this->managementEnvelope('support.record', $this->workflowSupportBody($fixture), 'rejected-read'), 'rejected-read', 0);
        $this->grant('management.outcome.review', $this->p);
        app(ManagementReviewService::class)->review($this->actor(), $this->p, $fixture['open']['action_id'], $this->workflowReviewBody($support, 'outcome.reject'), 'rejected-review');
        $page = app(ManagementReadService::class)->queue($this->actor(), $this->p);
        $this->assertCount(1, $page['items']);
        $item = $page['items'][0];
        $this->assertSame('rejected', $item['review_status']);
        $this->assertSame($support['support_id'], $item['subject_support']['id']);
        $this->assertSame('completed', $item['subject_support']['outcome']);
        $this->assertNull($item['support']);
        $this->assertNull($item['projected_support_id']);
        $this->assertNull($item['current_outcome']);
    }

    public function test_batch_applicability_is_independent_for_each_selected_boundary(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $current = $this->seedManagementBoundary(object: 'current', exactSource: true);
        $stale = $this->seedManagementBoundary(object: 'stale', exactSource: false);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$current['action'], $stale['action']]);
        $this->assertSame('complete', $graph['coverage']);
        $evaluations = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph) {
            $pins = app(ManagementPins::class);
            $loaded = $pins->loadLocked($this->p, $graph);

            return $pins->evaluateBatchLocked($this->p, $graph, $loaded['rows'], $proofs);
        });
        $this->assertSame('current', $evaluations[$graph['boundaries'][0]['event_id']]['applicability']);
        $this->assertSame('stale', $evaluations[$graph['boundaries'][1]['event_id']]['applicability']);
        foreach ([$current, $stale] as $ids) {
            $single = app(ManagementPins::class)->collect($this->actor(), $this->p, [$ids['action']]);
            $result = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $single, function ($proofs) use ($single) {
                $loaded = app(ManagementPins::class)->loadLocked($this->p, $single);

                return app(ManagementSourceProofs::class)->evaluateLocked($this->p, $single, $loaded['rows'], $proofs);
            });
            $this->assertSame($result, $evaluations[$single['boundaries'][0]['event_id']]);
        }
    }

    public function test_one_root_proof_reason_does_not_change_another_root_evaluation(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $first = $this->seedManagementBoundary(object: 'first', exactSource: true);
        $second = $this->seedManagementBoundary(object: 'second', exactSource: true);
        $graph = app(ManagementPins::class)->collect($this->actor(), $this->p, [$first['action'], $second['action']]);
        $this->assertSame('complete', $graph['coverage']);
        $evaluations = app(ManagementCoordination::class)->run($this->actor(), $this->p, ['management.read'], false, $graph, function ($proofs) use ($graph) {
            $pins = app(ManagementPins::class);
            $loaded = $pins->loadLocked($this->p, $graph);
            // Exercise semantic ownership after the real whole-page fence. This
            // diagnostic map never replaces coordination's delivery proofs.
            $key = $graph['boundary_origins'][$graph['boundaries'][1]['event_id']]['proof_keys'][0];
            $proofs[$key] = ['valid' => false, 'reason_code' => 'historical_proof_missing'];

            return $pins->evaluateBatchLocked($this->p, $graph, $loaded['rows'], $proofs);
        });
        $firstResult = $evaluations[$graph['boundaries'][0]['event_id']];
        $secondResult = $evaluations[$graph['boundaries'][1]['event_id']];
        $this->assertSame('current', $firstResult['applicability']);
        $this->assertSame([], $firstResult['reason_codes']);
        $this->assertSame('unknown', $secondResult['applicability']);
        $this->assertContains('historical_proof_missing', $secondResult['reason_codes']);
    }
}
