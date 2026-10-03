<?php

namespace App\Http\Controllers;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Domain\Organization\OrganizationService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class OrganizationController extends Controller
{
    public function handle(Request $request, AccessGateway $access, TenantContext $context, OrganizationService $organization): mixed
    {
        $actor = $request->attributes->get('trusted_actor');
        if (! $actor instanceof TrustedActor) {
            throw new AccessDeniedHttpException;
        }
        $write = $request->isMethod('post') || $request->isMethod('patch');
        $access->authorize($actor, $write ? 'organization.manage' : 'organization.read', ['tenant_id' => $actor->tenantId]);
        $department = $request->route('organization_resource') === 'departments';

        return $context->run($actor->tenantId, function () use ($request, $organization, $actor, $write, $department): mixed {
            if ($write) {
                $field = $department ? 'name' : 'display_name';
                $rules = [$field => 'required|string|max:200', 'reason_code' => 'required|in:local_setup,approved_change'];
                if ($request->isMethod('patch')) {
                    $rules['expected_revision'] = 'required|integer|min:1|max:2147483646';
                }
                $data = $request->validate($rules);
                if ($request->isMethod('patch')) {
                    $result = $department
                        ? $organization->updateDepartment($actor->tenantId, $actor->id, $request->route('id'), $data[$field], $data['expected_revision'], $data['reason_code'])
                        : $organization->updateEmployee($actor->tenantId, $actor->id, $request->route('id'), $data[$field], $data['expected_revision'], $data['reason_code']);

                    return response()->json(['data' => $result]);
                }
                $result = $department
                    ? $organization->createDepartment($actor->tenantId, $actor->id, $data[$field], $data['reason_code'])
                    : $organization->createEmployee($actor->tenantId, $actor->id, $data[$field], $data['reason_code']);

                return response()->json(['data' => $result], 201);
            }
            $data = $request->validate(['limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'sometimes|uuid']);

            return response()->json($department ? $organization->listDepartments((int) ($data['limit'] ?? 50), $data['cursor'] ?? null) : $organization->listEmployees((int) ($data['limit'] ?? 50), $data['cursor'] ?? null));
        });
    }
}
