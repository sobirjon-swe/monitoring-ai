<?php

namespace Tests\Unit;

use App\Domain\Access\TrustedActor;
use App\Support\ReadDeliveryFence;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ReadDeliveryFenceTest extends TestCase
{
    public function test_checks_every_captured_page_at_one_instant_and_partitions_failures(): void
    {
        $actor = new TrustedActor('00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');
        $fence = new ReadDeliveryFence($actor, 'project');
        $observed = [];
        $fence->within('qa', function () use ($fence, $actor, &$observed) {
            $fence->capture($actor, 'project', [[]], function ($instant) use (&$observed) {
                $observed[] = $instant;
            });
            $fence->capture($actor, 'project', [[]], function ($instant) use (&$observed) {
                $observed[] = $instant;
                throw new AccessDeniedHttpException;
            });
        });
        $fence->within('ba', fn () => $fence->capture($actor, 'project', [[]], fn ($instant) => null));
        $this->assertSame('original_authority_expired', $fence->reason('qa', '2026-11-02T00:00:00Z'));
        $this->assertSame(['2026-11-02T00:00:00Z', '2026-11-02T00:00:00Z'], $observed);
        $this->assertNull($fence->reason('ba', '2026-11-02T00:00:00Z'));
    }

    public function test_proof_overflow_withholds_block_and_scope_mismatch_fails_closed(): void
    {
        $actor = new TrustedActor('00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002');
        $fence = new ReadDeliveryFence($actor, 'project');
        $fence->within('qa', fn () => $fence->capture($actor, 'project', array_fill(0, 10001, []), fn ($instant) => $this->fail('Overflow must discard retained callbacks.')));
        $this->assertSame('coverage_limit', $fence->reason('qa', '2026-11-02T00:00:00Z'));
        $this->expectException(\LogicException::class);
        $fence->within('ba', fn () => $fence->capture(new TrustedActor('00000000-0000-4000-8000-000000000003', $actor->tenantId), 'project', [], fn ($instant) => null));
    }
}
