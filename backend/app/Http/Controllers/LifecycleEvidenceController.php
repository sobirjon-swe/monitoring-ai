<?php

namespace App\Http\Controllers;

use App\Domain\Access\AccessGateway;
use App\Domain\Lifecycle\EvidenceService;
use App\Domain\Lifecycle\QaObservationService;
use App\Http\Controllers\Concerns\StrictLifecycleJson;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LifecycleEvidenceController extends Controller
{
    use StrictLifecycleJson;

    public function handle(Request $request, EvidenceService $evidence, QaObservationService $qa): mixed
    {
        $actor = $request->attributes->get('trusted_actor');
        $project = $request->route('project');
        app(AccessGateway::class)->authorize($actor, 'evidence.review', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
        $evidence->sourceAccess($actor, $project);
        $data = $this->json($request->getContent());
        $generation = $this->integer($data, 'expected_generation', 0);
        unset($data['expected_generation']);
        $operation = $request->route('evidence_operation');
        try {
            if ($operation === 'review') {
                $revision = $this->integer($data, 'expected_revision', 0);
                if (array_diff(array_keys($data), ['decision', 'reason', 'expected_revision']) || ! is_string($data['decision'] ?? null) || ! is_string($data['reason'] ?? null)) {
                    $this->invalid();
                }
                $result = $evidence->review($actor, $project, $request->route('link'), $data['decision'], $revision, $generation, $data['reason']);
            } elseif ($operation === 'qa') {
                $revision = $this->integer($data, 'expected_revision', 0);
                unset($data['expected_revision']);
                $result = $qa->record($actor, $project, $data, $revision, $generation);
            } else {
                $result = $evidence->propose($actor, $project, $data, $generation);
            }
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0], ['23503', '23505', '23514'], true)) {
                throw new ConflictHttpException;
            }
            throw $e;
        }

        return response()->json(['data' => $result], 201);
    }
}
