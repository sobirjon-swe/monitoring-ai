<?php

namespace App\Http\Controllers;

use App\Domain\Access\AccessGateway;
use App\Domain\Lifecycle\BaselineService;
use App\Http\Controllers\Concerns\StrictLifecycleJson;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BaselineController extends Controller
{
    use StrictLifecycleJson;

    public function handle(Request $request, BaselineService $service): mixed
    {
        $actor = $request->attributes->get('trusted_actor');
        $project = $request->route('project');
        app(AccessGateway::class)->authorize($actor, 'baseline.approve', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
        $data = $this->json($request->getContent());
        $generation = $this->integer($data, 'expected_generation', 0);
        if ($request->route('baseline_operation') === 'close') {
            if (array_diff(array_keys($data), ['expected_generation', 'reason']) || ! is_string($data['reason'] ?? null)) {
                $this->invalid();
            }
        } else {
            $schema = json_decode(file_get_contents(base_path('../contracts/lifecycle-baseline-v1.schema.json')), false, 16, JSON_THROW_ON_ERROR);
            if (! (new CompliantValidator)->validate(json_decode($request->getContent()), $schema)->isValid()) {
                $this->invalid();
            }
        }
        try {
            $r = $request->route('baseline_operation') === 'close' ? $service->close($actor, $project, $generation, $data['reason']) : $service->approve($actor, $project, $data['items'], $data['policy_id'], $data['expected_current_baseline_id'], $generation, $data['reason'], $data['reopen'] ?? false);
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0], ['23503', '23505', '23514'], true)) {
                throw new ConflictHttpException;
            }throw $e;
        }

        return response()->json(['data' => $r], 201);
    }
}
