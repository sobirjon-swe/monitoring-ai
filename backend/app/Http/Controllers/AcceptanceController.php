<?php

namespace App\Http\Controllers;

use App\Domain\Access\AccessGateway;
use App\Domain\Lifecycle\HumanAcceptanceService;
use App\Http\Controllers\Concerns\StrictLifecycleJson;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AcceptanceController extends Controller
{
    use StrictLifecycleJson;

    public function handle(Request $request, HumanAcceptanceService $service): mixed
    {
        $actor = $request->attributes->get('trusted_actor');
        $project = $request->route('project');
        app(AccessGateway::class)->authorize($actor, 'acceptance.review', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
        $d = $this->json($request->getContent());
        $object = json_decode($request->getContent(), false, 16, JSON_THROW_ON_ERROR);
        if (! is_array($object->reviewed_link_ids ?? null)) {
            $this->invalid();
        }
        $g = $this->integer($d, 'expected_generation', 0);
        if (array_diff(array_keys($d), ['baseline_id', 'criterion_version_id', 'reviewed_link_ids', 'expected_generation', 'reason']) || ! is_string($d['baseline_id'] ?? null) || ! is_string($d['criterion_version_id'] ?? null) || ! is_array($d['reviewed_link_ids'] ?? null) || ! is_string($d['reason'] ?? null)) {
            $this->invalid();
        }
        try {
            $r = $service->accept($actor, $project, $d['baseline_id'], $d['criterion_version_id'], $d['reviewed_link_ids'], $g, $d['reason']);
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0], ['23503', '23505', '23514'], true)) {
                throw new ConflictHttpException;
            }throw $e;
        }

        return response()->json(['data' => $r], 201);
    }
}
