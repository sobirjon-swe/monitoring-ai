<?php

namespace App\Http\Controllers;

use App\Domain\Access\AccessGateway;
use App\Domain\Projects\ProjectService;
use App\Rules\PreciseAfter;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ProjectController extends Controller
{
    public function handle(Request $request, AccessGateway $access, TenantContext $context, ProjectService $projects): mixed
    {
        $actor = $request->attributes->get('trusted_actor');
        $project = $request->route('project');
        $resource = $request->route('project_resource');
        $read = $request->isMethod('get') || $request->isMethod('head');
        abort_unless($read || $request->isMethod('post') || $request->isMethod('patch'), 405);
        $purpose = match (true) {
            $project === null => 'project.create',
            $resource === 'roles' && $read => 'roles.read',
            $resource === 'roles' => 'roles.manage',
            $resource === 'memberships' => 'membership.manage',
            $read => 'project.read',
            default => 'project.manage',
        };
        $scope = ['tenant_id' => $actor->tenantId];
        if ($project !== null) {
            $scope['project_id'] = $project;
        }
        $access->authorize($actor, $purpose, $scope);

        return $context->run($actor->tenantId, function () use ($request, $projects, $actor, $project, $resource, $read): mixed {
            if ($resource === null) {
                if ($read) {
                    return response()->json(['data' => $projects->find($project)]);
                }
                $rules = ['name' => 'required|string|max:200'];
                if ($project !== null) {
                    $rules['expected_revision'] = 'required|integer|min:1|max:2147483646';
                }
                $data = $request->validate($rules);

                return response()->json(['data' => $project === null
                    ? $projects->create($actor->tenantId, $actor->id, $data['name'])
                    : $projects->update($actor->tenantId, $actor->id, $project, $data['name'], $data['expected_revision'])], $project === null ? 201 : 200);
            }
            if ($read) {
                $data = $request->validate(['employee_id' => 'required|uuid', 'at' => 'required|date']);

                return response()->json(['data' => $projects->rolesAt($project, $data['employee_id'], $data['at'])]);
            }
            if ($request->isMethod('patch')) {
                $data = $request->validate(['valid_to' => 'required|date', 'expected_revision' => 'required|integer|min:1|max:2147483646']);

                try {
                    $result = $projects->endPeriod($resource === 'roles' ? 'role_assignments' : 'project_memberships', $actor->tenantId, $actor->id, $project, $request->route('id'), $data['valid_to'], $data['expected_revision']);
                } catch (QueryException $exception) {
                    if (in_array($exception->errorInfo[0], ['23503', '23P01'], true)) {
                        throw new ConflictHttpException;
                    }
                    throw $exception;
                }

                return response()->json(['data' => $result]);
            }
            $rules = ['employee_id' => 'required|uuid', 'valid_from' => 'required|date', 'valid_to' => ['nullable', 'date', new PreciseAfter('valid_from')]];
            if ($resource === 'roles') {
                $rules['role'] = 'required|in:developer,qa,ba,pm,department_head,reviewer';
            }
            $data = $request->validate($rules);
            try {
                $result = $resource === 'roles'
                    ? $projects->assignRole($actor->tenantId, $actor->id, $project, $data['employee_id'], $data['role'], $data['valid_from'], $data['valid_to'] ?? null)
                    : $projects->addMembership($actor->tenantId, $actor->id, $project, $data['employee_id'], $data['valid_from'], $data['valid_to'] ?? null);
            } catch (QueryException $exception) {
                if (in_array($exception->errorInfo[0], ['23503', '23P01'], true)) {
                    throw new ConflictHttpException;
                }
                throw $exception;
            }

            return response()->json(['data' => $result], 201);
        });
    }
}
