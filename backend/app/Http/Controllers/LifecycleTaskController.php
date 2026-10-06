<?php

namespace App\Http\Controllers;

use App\Domain\Access\AccessGateway;
use App\Domain\Lifecycle\TaskService;
use App\Http\Controllers\Concerns\StrictLifecycleJson;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class LifecycleTaskController extends Controller
{
    use StrictLifecycleJson;

    public function handle(Request $request, TaskService $tasks): mixed
    {
        $actor = $request->attributes->get('trusted_actor');
        $project = $request->route('project');
        $read = $request->isMethod('get') || $request->isMethod('head');
        app(AccessGateway::class)->authorize($actor, $read ? 'requirements.read' : 'requirements.manage', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
        if ($read) {
            $q = $request->validate(['limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'sometimes|uuid']);

            return response()->json(['data' => $tasks->listing($actor, $project, (int) ($q['limit'] ?? 20), $q['cursor'] ?? null)]);
        }
        $data = $this->json($request->getContent());
        $generation = $this->integer($data, 'expected_generation', 0);
        $op = $request->route('task_operation');
        try {
            if ($op === 'dependency') {
                if (array_diff(array_keys($data), ['prerequisite_id', 'expected_generation']) || ! is_string($data['prerequisite_id'] ?? null)) {
                    $this->invalid();
                }
                $result = $tasks->depend($actor, $project, $request->route('task'), $data['prerequisite_id'], $generation);
            } else {
                $revision = $op === 'revise' ? $this->integer($data, 'expected_revision', 1) : null;
                // Validate original object/array types before converting JSON to PHP arrays.
                $object = json_decode($request->getContent(), false, 16, JSON_THROW_ON_ERROR);
                unset($object->expected_generation);
                if ($op === 'revise') {
                    unset($object->expected_revision);
                }
                $schema = json_decode(file_get_contents(base_path('../contracts/lifecycle-task-v1.schema.json')), false, 16, JSON_THROW_ON_ERROR);
                if (! (new CompliantValidator)->validate($object, $schema)->isValid()) {
                    $this->invalid();
                }
                unset($data['expected_generation']);
                if ($op === 'revise') {
                    unset($data['expected_revision']);
                    $result = $tasks->revise($actor, $project, $request->route('task'), $data, $revision, $generation);
                } else {
                    $result = $tasks->create($actor, $project, $data, $generation);
                }
            }
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0], ['23503', '23505'], true)) {
                throw new ConflictHttpException;
            }
            throw $e;
        }

        return response()->json(['data' => $result], $op === 'revise' ? 200 : 201);
    }
}
