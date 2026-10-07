<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class QaMaintenanceProcessor
{
    public function claim(TrustedActor $operator, string $project): ?array
    {
        return app(QaCoordination::class)->run($operator, $project, ['qa.process', 'qa.source.read'], false, function ($proofs) use ($project) {
            $expired = DB::table('qa_outbox')->where('project_id', $project)->where('status', 'processing')->where('lease_until', '<=', DB::raw('clock_timestamp()'))->orderBy('id')->limit(20)->lockForUpdate()->get();
            foreach ($expired as $job) {
                $this->retryLocked($job, 'lease_expired');
            }
            $job = DB::table('qa_outbox')->where('project_id', $project)->whereIn('status', ['pending', 'retry'])->where('available_at', '<=', DB::raw('clock_timestamp()'))->where('attempt_count', '<', 5)->orderByDesc('is_catchup')->orderBy('id')->lockForUpdate()->first();
            if (! $job) {
                return null;
            }
            $token = (string) Str::uuid();
            $proof = QaContract::canonical($proofs['current']['proof']);
            DB::table('qa_outbox')->where('id', $job->id)->update(['status' => 'processing', 'attempt_count' => $job->attempt_count + 1, 'lease_token' => $token, 'lease_until' => DB::raw("clock_timestamp()+interval '60 seconds'"), 'processing_authority' => $proof, 'processing_authority_hash' => hash('sha256', $proof), 'reason_code' => null]);

            return ['id' => $job->id, 'lease_token' => $token, 'attempt_count' => $job->attempt_count + 1, 'proof_hash' => hash('sha256', $proof), 'watermark' => $job->watermark];
        });
    }

    public function processClaim(TrustedActor $operator, string $project, array $claim): array
    {
        if (! Str::isUuid($claim['id'] ?? '') || ! Str::isUuid($claim['lease_token'] ?? '') || ! preg_match('/\A[0-9a-f]{64}\z/D', $claim['proof_hash'] ?? '')) {
            throw new InvalidArgumentException('Invalid QA claim.');
        }
        $hint = app(TenantContext::class)->run($operator->tenantId, fn () => DB::table('qa_outbox')->where('project_id', $project)->find($claim['id']));
        $requests = [];
        $graph = ['refs' => [], 'requests' => [], 'source_ids' => []];
        if ($hint?->processing_authority) {
            $pin = json_decode($hint->processing_authority, true);
            if (! hash_equals(hash('sha256', QaContract::canonical($pin)), $hint->processing_authority_hash ?? '')) {
                throw new ConflictHttpException;
            }
            $requests[] = ['key' => 'claim:'.$hint->id, 'actor_id' => $pin['actor_id'], 'purposes' => $pin['purposes'], 'required' => false, 'pinned_proof' => $pin];
        }
        if ($hint?->change_id) {
            $event = app(TenantContext::class)->run($operator->tenantId, fn () => DB::table('qa_changes')->where('project_id', $project)->find($hint->change_id));
            $refs = [];
            foreach (QaInvalidation::COLUMNS as $kind => $column) {
                if ($event?->{$column}) {
                    $refs[$kind] = [$event->{$column}];
                }
            }
            // Ordinary work observes only its canonical subject, never the unrelated selected graph.
            if ($refs) {
                $graph = app(QaPins::class)->collect($operator, $project, $refs);
            }
        }
        try {
            return app(QaCoordination::class)->run($operator, $project, ['qa.process', 'qa.source.read'], false, function ($proofs) use ($operator, $project, $claim, $graph) {
                $job = DB::table('qa_outbox')->where('project_id', $project)->where('id', $claim['id'])->lockForUpdate()->first();
                if (! $job || $job->status !== 'processing' || $job->lease_token !== $claim['lease_token'] || $job->processing_authority_hash !== $claim['proof_hash'] || ! DB::scalar('select ?::timestamptz>clock_timestamp()', [$job->lease_until])) {
                    return ['status' => 'fenced', 'processed' => 0];
                }
                if (! ($proofs['claim:'.$job->id]['valid'] ?? false)) {
                    $this->retryLocked($job, 'access_revoked');

                    return ['status' => 'retry', 'processed' => 0];
                }
                $invalidations = app(QaInvalidation::class);
                $cursor = (int) DB::table('qa_project_state')->where('project_id', $project)->value('maintenance_watermark');
                $events = $job->is_catchup ? DB::table('qa_changes')->where('project_id', $project)->where('change_seq', '>', $cursor)->where('change_seq', '<=', $job->watermark)->orderBy('change_seq')->limit(1)->get() : DB::table('qa_changes')->where('project_id', $project)->where('id', $job->change_id)->get();
                $processed = 0;
                $complete = true;
                if (! $job->is_catchup && $graph['refs']) {
                    app(QaPins::class)->loadLocked($project, $graph['refs']);
                    $processed = $invalidations->observeProofsLocked($operator, $project, $graph, $proofs, 'worker:'.$job->id);
                }
                foreach ($events as $event) {
                    $affected = $invalidations->affectedLocked($project, $event, 20 - $processed);
                    $invalidations->observeSubjectsLocked($operator, $project, $affected['subjects'], 'change:'.$event->id);
                    $processed += count($affected['subjects']);
                    $complete = $affected['complete'];
                    if ($complete && $job->is_catchup) {
                        $cursor = $event->change_seq;
                    }
                }
                // Global sequences may contain other projects or rolled-back gaps.
                if ($job->is_catchup && $events->isEmpty()) {
                    $cursor = max($cursor, (int) $job->watermark);
                }
                if (! DB::scalar('select ?::timestamptz>clock_timestamp()', [$job->lease_until])) {
                    throw new ConflictHttpException;
                }
                DB::table('qa_outbox')->where('id', $job->id)->update(['status' => 'completed', 'lease_token' => null, 'lease_until' => null, 'completed_at' => DB::raw('clock_timestamp()')]);
                if ($job->is_catchup) {
                    DB::table('qa_project_state')->where('project_id', $project)->update(['maintenance_watermark' => $cursor]);
                    if ($cursor < $job->watermark) {
                        $invalidations->reserveLocked(['tenant_id' => $operator->tenantId, 'project_id' => $project], $job->watermark);
                    }
                } elseif (! $complete) {
                    $invalidations->reserveLocked(['tenant_id' => $operator->tenantId, 'project_id' => $project], DB::table('qa_changes')->where('id', $job->change_id)->value('change_seq'));
                }

                return ['status' => 'completed', 'processed' => $processed];
            }, [...$requests, ...$graph['requests']]);
        } catch (AccessDeniedHttpException|ConflictHttpException $failure) {
            // Publication failure never commits a partial result. A separately authorized recovery may retry it.
            throw $failure;
        }
    }

    public function drain(TrustedActor $operator, string $project, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Invalid QA drain bound.');
        }
        $counts = ['claimed' => 0, 'completed' => 0, 'retry' => 0, 'fenced' => 0, 'processed' => 0];
        foreach (range(1, $limit) as $number) {
            $claim = $this->claim($operator, $project);
            if ($claim === null) {
                break;
            } $counts['claimed']++;
            $result = $this->processClaim($operator, $project, $claim);
            $counts[$result['status']]++;
            $counts['processed'] += $result['processed'];
        }

        return $counts;
    }

    private function retryLocked(object $job, string $reason): void
    {
        $dead = $job->attempt_count >= 5;
        $delay = [1 => 5, 2 => 30, 3 => 120, 4 => 600][$job->attempt_count] ?? 600;
        DB::table('qa_outbox')->where('id', $job->id)->update(['status' => $dead ? 'dead_letter' : 'retry', 'lease_token' => null, 'lease_until' => null, 'reason_code' => $reason, 'available_at' => DB::raw("clock_timestamp()+interval '$delay seconds'")]);
    }
}
