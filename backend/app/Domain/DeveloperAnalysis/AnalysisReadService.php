<?php

namespace App\Domain\DeveloperAnalysis;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AnalysisReadService
{
    public function __construct(private readonly TenantContext $context, private readonly DeveloperCoordination $coordination, private readonly AnalysisPins $pins) {}

    public function run(TrustedActor $actor, string $run): array
    {
        return $this->read($actor, $run, null);
    }

    public function finding(TrustedActor $actor, string $finding, int $limit = 50, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > 100 || ($cursor !== null && strlen($cursor) > 128)) {
            $this->invalidCursor();
        }
        $hint = $this->context->run($actor->tenantId, fn () => DB::table('developer_findings')->where('id', $finding)->first());
        if (! $hint) {
            throw new NotFoundHttpException;
        }

        return $this->read($actor, $hint->run_id, $finding, $limit, $cursor);
    }

    private function read(TrustedActor $actor, string $id, ?string $finding, int $limit = 50, ?string $cursor = null): array
    {
        $hint = $this->context->run($actor->tenantId, fn () => DB::table('developer_runs')->where('id', $id)->first());
        if (! $hint) {
            throw new NotFoundHttpException;
        }
        $proof = json_decode($hint->authority, true);

        try {
            return $this->coordination->run($actor, $hint->project_id, ['analysis.read', 'analysis.source.read'], false, function () use ($actor, $hint, $id, $finding, $limit, $cursor): array {
                $run = DB::table('developer_runs')->where('project_id', $hint->project_id)->where('id', $id)->first();
                if (! $run || $run->authority !== $hint->authority || $run->requester_id !== $hint->requester_id) {
                    throw new NotFoundHttpException;
                }
                $pins = json_decode($run->pins, true);
                if (app(AnalysisInvalidation::class)->observe($actor, $run, 'delivery')) {
                    // Return the newly committed status/reason together with its stale applicability.
                    $run = DB::table('developer_runs')->where('project_id', $hint->project_id)->where('id', $id)->first();
                }
                $applicability = $this->pins->applicability($actor, $run);
                $current = $applicability === 'current';
                $data = ['id' => $run->id, 'project_id' => $run->project_id, 'snapshot_id' => $run->snapshot_id, 'status' => $run->status, 'applicability' => $applicability,
                    'pins' => $pins, 'ai_interpretation' => 'unavailable', 'reason_code' => $run->reason_code, 'created_at' => $run->created_at, 'completed_at' => $run->completed_at];
                if ($finding !== null) {
                    $row = DB::table('developer_findings')->where('project_id', $run->project_id)->where('run_id', $run->id)->where('id', $finding)->first();
                    if (! $row) {
                        throw new NotFoundHttpException;
                    }

                    return ['id' => $row->id, 'run_id' => $run->id, 'applicability' => $applicability, 'kind' => $row->kind,
                        'review_revision' => $row->review_revision, 'disposition' => $row->disposition, 'ai_interpretation' => 'unavailable',
                        'facts' => $current ? json_decode($row->facts, true) : null, 'summary' => $current ? $row->summary : null,
                        'review_history' => $this->history($row, $limit, $cursor)];
                }
                $result = $current ? DB::table('developer_results')->where('run_id', $run->id)->first() : null;
                $data['output'] = $result ? json_decode($result->output, true) : null;
                $data['verified_findings'] = $current ? DB::table('developer_findings')->where('project_id', $run->project_id)->where('run_id', $run->id)->where('disposition', 'verified')
                    ->orderBy('kind')->limit(3)->get(['id', 'kind', 'summary', 'review_revision'])->map(fn ($row) => (array) $row)->all() : [];

                return $data;
            }, [['actor_id' => $hint->requester_id, 'purposes' => $proof['purposes'], 'pinned_proof' => $proof]]);
        } catch (AccessDeniedHttpException $exception) {
            app(AnalysisInvalidation::class)->refreshRun($actor, $hint->project_id, $hint->id);
            throw $exception;
        }
    }

    private function history(object $finding, int $limit, ?string $cursor): array
    {
        $after = 0;
        if ($cursor !== null) {
            if (! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $cursor)) {
                $this->invalidCursor();
            }
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($decoded === false || ! preg_match('/\A'.preg_quote($finding->id, '/').':([1-9][0-9]{0,9})\z/D', $decoded, $matches)
                || $this->cursor($finding->id, (int) $matches[1]) !== $cursor || (int) $matches[1] > 2147483647) {
                $this->invalidCursor();
            }
            $after = (int) $matches[1];
            if (! DB::table('developer_reviews')->where('project_id', $finding->project_id)->where('finding_id', $finding->id)->where('revision', $after)->exists()) {
                $this->invalidCursor();
            }
        }
        $rows = DB::table('developer_reviews')->where('project_id', $finding->project_id)->where('finding_id', $finding->id)->where('revision', '>', $after)
            ->orderBy('revision')->limit($limit + 1)->get(['id', 'revision', 'decision', 'disposition', 'actor_id', 'reason', 'effective_at']);
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->map(fn ($row) => (array) $row)->all();

        return ['items' => $items, 'next_cursor' => $more ? $this->cursor($finding->id, end($items)['revision']) : null];
    }

    private function cursor(string $finding, int $revision): string
    {
        return rtrim(strtr(base64_encode($finding.':'.$revision), '+/', '-_'), '=');
    }

    private function invalidCursor(): never
    {
        throw ValidationException::withMessages(['payload' => 'Invalid finding history page.']);
    }
}
