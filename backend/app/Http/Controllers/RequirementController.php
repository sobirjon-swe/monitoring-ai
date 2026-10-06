<?php

namespace App\Http\Controllers;

use App\Domain\Access\AccessGateway;
use App\Domain\Lifecycle\DodPolicyService;
use App\Domain\Lifecycle\RequirementService;
use App\Http\Controllers\Concerns\StrictLifecycleJson;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RequirementController extends Controller
{
    use StrictLifecycleJson;

    public function handle(Request $request, RequirementService $requirements, DodPolicyService $policies): mixed
    {
        $actor = $request->attributes->get('trusted_actor');
        $p = $request->route('project');
        $op = $request->route('lifecycle_operation');
        $read = $request->isMethod('get') || $request->isMethod('head');
        $purpose = $read ? 'requirements.read' : match ($op) {
            'approve' => 'requirements.approve','policy' => 'policy.approve',default => 'requirements.manage'
        };
        app(AccessGateway::class)->authorize($actor, $purpose, ['tenant_id' => $actor->tenantId, 'project_id' => $p]);
        if ($read) {
            $q = $request->validate(['limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'sometimes|uuid']);

            return response()->json(['data' => $requirements->listing($actor, $p, (int) ($q['limit'] ?? 20), $q['cursor'] ?? null)]);
        }
        $data = $this->json($request->getContent());
        try {
            $result = match ($op) {
                'approve' => $requirements->approve($actor, $p, $request->route('draft'), $this->integer($data, 'expected_revision', 1), $this->integer($data, 'expected_generation', 0), $this->reason($data)),
                'policy' => $policies->approve($actor, $p, $this->policy($data), $this->integer($data, 'expected_generation', 0)),
                'update' => $requirements->updateDraft($actor, $p, $request->route('draft'), $this->draft($data), $this->integer($data, 'expected_revision', 1)),
                default => $requirements->createDraft($actor, $p, $data)
            };
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0], ['23503', '23505'], true)) {
                throw new ConflictHttpException;
            }throw $e;
        }

        return response()->json(['data' => $result], $op === 'update' ? 200 : 201);
    }

    private function draft(array $data): array
    {
        $this->integer($data, 'expected_revision', 1);
        unset($data['expected_revision']);

        return $data;
    }

    private function policy(array $data): array
    {
        if (array_diff(array_keys($data), ['mandatory_qa', 'human_acceptance', 'fictional', 'expected_generation'])) {
            $this->invalid();
        }unset($data['expected_generation']);

        return $data;
    }

    private function reason(array $data): string
    {
        if (array_diff(array_keys($data), ['expected_revision', 'expected_generation', 'reason']) || ! is_string($data['reason'] ?? null)) {
            $this->invalid();
        }

        return $data['reason'];
    }
}
