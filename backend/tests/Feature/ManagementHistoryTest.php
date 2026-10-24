<?php

namespace Tests\Feature;

use App\Domain\Management\ManagementReadService;
use App\Support\LocalManagementDatabase;
use Illuminate\Support\Str;
use Tests\Support\ManagementTestCase;

final class ManagementHistoryTest extends ManagementTestCase
{
    public function test_absent_history_is_an_authorized_empty_page(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $page = app(ManagementReadService::class)->history($this->actor(), $this->p, (string) Str::uuid());
        $this->assertSame([], $page['items']);
        $this->assertNull($page['next_cursor']);
        $this->assertArrayNotHasKey('action_id', $page);
    }

    public function test_historical_confirmation_survives_stale_null_current_outcome(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary());
        $page = app(ManagementReadService::class)->history($this->actor(), $this->p, $ids['action']);
        $this->assertSame('complete', $page['coverage_status']);
        $this->assertCount(4, $page['items']);
        $review = $page['items'][3];
        $this->assertSame('confirmed', $review['review_status']);
        $this->assertSame('stale', $review['applicability']);
        $this->assertNull($review['current_outcome']);
        $this->assertSame($ids['support'], $review['subject_support']['id']);
        $this->assertNull($review['business_age']);
        $this->assertContains('calendar_unavailable', $review['reason_codes']);
    }

    public function test_current_confirmation_is_unavailable_without_its_own_admitted_boundary(): void
    {
        app(LocalManagementDatabase::class)->assertRuntime();
        $this->managementInputs();
        $ids = $this->seedManagementConfirmation($this->seedManagementBoundary(exactSource: true));
        $page = app(ManagementReadService::class)->history($this->actor(), $this->p, $ids['action'], ['limit' => 3]);
        $this->assertCount(3, $page['items']);
        $this->assertNull($page['items'][2]['current_outcome']);
        $this->assertSame('unknown', $page['items'][2]['current_applicability']);
    }
}
