<?php

namespace App\Http\Controllers;

use App\Domain\Access\AccessGateway;
use App\Domain\Identity\IdentityMappingService;
use App\Rules\PreciseAfter;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class IdentityMappingController extends Controller
{
    public function handle(Request $request, AccessGateway $access, TenantContext $context, IdentityMappingService $identities): mixed
    {
        $actor = $request->attributes->get('trusted_actor');
        $read = $request->isMethod('get') || $request->isMethod('head');
        abort_unless($read || $request->isMethod('post') || $request->isMethod('patch'), 405);
        $access->authorize($actor, $read ? 'identity.read' : 'identity.manage', ['tenant_id' => $actor->tenantId]);

        return $context->run($actor->tenantId, function () use ($request, $identities, $actor): mixed {
            $operation = $request->route('identity_operation');
            if ($operation === 'attribution') {
                $data = $request->validate(['at' => 'required|date']);

                return response()->json(['data' => $identities->attribution($request->route('identity'), $data['at'])]);
            }
            if ($operation === 'history') {
                $data = $request->validate(['after_revision' => 'nullable|integer|min:0|max:2147483647']);
                $rows = $identities->history($request->route('mapping'), $data['after_revision'] ?? null);
                $hasMore = count($rows) > 100;
                $rows = array_slice($rows, 0, 100);

                return response()->json(['data' => $rows, 'next_after_revision' => $hasMore ? end($rows)->revision : null]);
            }
            $rules = match ($operation) {
                'source' => ['kind' => 'required|in:gitlab,task_tracker,attendance,qa,manual', 'label' => 'required|string|max:200'],
                'identity' => ['external_user_id' => 'required|string|max:200', 'candidate_name' => 'nullable|string|max:200', 'candidate_email' => 'nullable|email|max:254'],
                default => ['employee_id' => 'required|uuid', 'reason' => 'required|string|max:1000', 'valid_from' => 'required|date', 'valid_to' => ['nullable', 'date', new PreciseAfter('valid_from')]],
            };
            if ($operation === 'review') {
                $rules += ['status' => 'required|in:approved,rejected', 'expected_revision' => 'required|integer|min:1|max:2147483646'];
            }
            $data = $request->validate($rules);
            try {
                $result = match ($operation) {
                    'source' => $identities->createSource($actor->tenantId, $actor->id, $data),
                    'identity' => $identities->createIdentity($actor->tenantId, $actor->id, $request->route('source'), $data),
                    'propose' => $identities->propose($actor->tenantId, $actor->id, $request->route('identity'), $data),
                    'review' => $identities->review($actor->tenantId, $actor->id, $request->route('mapping'), $data),
                };
            } catch (QueryException $exception) {
                if (in_array($exception->errorInfo[0], ['23503', '23505', '23P01'], true)) {
                    throw new ConflictHttpException;
                }
                throw $exception;
            }

            return response()->json(['data' => $result], $operation === 'review' ? 200 : 201);
        });
    }
}
