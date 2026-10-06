<?php

namespace App\Domain\DeveloperAnalysis;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FindingReviewService
{
    public function __construct(private readonly TenantContext $context, private readonly DeveloperCoordination $coordination,
        private readonly AnalysisPins $pins, private readonly AnalysisInvalidation $invalidation, private readonly SecretGate $secrets, private readonly AuditWriter $audit) {}

    public function review(TrustedActor $actor, string $finding, int $expectedRevision, string $decision, string $reason): array
    {
        // Bound untrusted text before the scanner, database lookup or audit writer.
        if ($expectedRevision < 0 || $expectedRevision >= 2147483647 || ! in_array($decision, ['verified', 'rejected', 'context-added'], true)
            || strlen($reason) > 2000 || ! mb_check_encoding($reason, 'UTF-8') || preg_match('/\A\s*\z/u', $reason)) {
            $this->invalid();
        }
        try {
            $this->secrets->safeText($reason);
        } catch (InvalidArgumentException) {
            $this->invalid();
        }
        $hint = $this->context->run($actor->tenantId, fn () => DB::table('developer_findings')->where('id', $finding)->first());
        if (! $hint) {
            throw new NotFoundHttpException;
        }
        $runHint = $this->context->run($actor->tenantId, fn () => DB::table('developer_runs')->where('project_id', $hint->project_id)->where('id', $hint->run_id)->first());
        if (! $runHint) {
            throw new NotFoundHttpException;
        }
        $proof = json_decode($runHint->authority, true);
        try {
            return $this->coordination->run($actor, $hint->project_id, ['analysis.read', 'analysis.source.read', 'analysis.review'], false,
                function () use ($actor, $hint, $runHint, $finding, $expectedRevision, $decision, $reason): array {
                    $run = DB::table('developer_runs')->where('project_id', $hint->project_id)->where('id', $hint->run_id)->first();
                    if (! $run || $run->authority !== $runHint->authority || $run->requester_id !== $runHint->requester_id) {
                        throw new NotFoundHttpException;
                    }
                    if (DB::table('lifecycle_project_state')->where('project_id', $hint->project_id)->value('is_closed')) {
                        throw new ConflictHttpException;
                    }
                    $this->invalidation->observe($actor, $run, 'finding-review');
                    $applicability = $this->pins->applicability($actor, $run);
                    // Project/authority/source have been coordinated before the finding CAS lock.
                    $row = DB::table('developer_findings')->where('project_id', $hint->project_id)->where('run_id', $hint->run_id)->where('id', $finding)->lockForUpdate()->first();
                    if (! $row) {
                        throw new NotFoundHttpException;
                    }
                    if ($row->review_revision !== $expectedRevision) {
                        throw new ConflictHttpException;
                    }
                    $revision = $expectedRevision + 1;
                    $disposition = $decision === 'context-added' ? $row->disposition : $decision;
                    $id = (string) Str::uuid();
                    $instant = DB::scalar('select clock_timestamp()');
                    DB::table('developer_reviews')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $hint->project_id,
                        'finding_id' => $finding, 'revision' => $revision, 'decision' => $decision, 'disposition' => $disposition,
                        'actor_id' => $actor->id, 'reason' => $reason, 'effective_at' => $instant]);
                    if (DB::table('developer_findings')->where('id', $finding)->where('review_revision', $expectedRevision)
                        ->update(['review_revision' => $revision, 'disposition' => $disposition, 'current_review_id' => $id]) !== 1) {
                        throw new ConflictHttpException;
                    }
                    $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $hint->project_id], 'developer.finding_reviewed', ['type' => 'developer_reviews', 'id' => $id], $revision, 'approved_change');

                    return ['id' => $finding, 'run_id' => $hint->run_id, 'review_id' => $id, 'review_revision' => $revision,
                        'disposition' => $disposition, 'applicability' => $applicability, 'effective_at' => $instant];
                }, [['actor_id' => $runHint->requester_id, 'purposes' => $proof['purposes'], 'pinned_proof' => $proof]]);
        } catch (AccessDeniedHttpException $exception) {
            $this->invalidation->refreshRun($actor, $hint->project_id, $hint->run_id);
            throw $exception;
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['payload' => 'Invalid finding review.']);
    }
}
