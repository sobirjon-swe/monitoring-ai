<?php

namespace App\Http\Controllers;

use App\Domain\PeopleRoles\PeopleRolesService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PeopleRolesController
{
    public function read(Request $request, PeopleRolesService $service): mixed
    {
        if ($request->getContent() !== '') {
            throw ValidationException::withMessages(['query' => 'Invalid query.']);
        }
        $operation = $request->route('people_operation');
        $data = $operation === 'employees'
            ? $service->employees($request->attributes->get('trusted_actor'), $request->route('project'), $request->query())
            : $service->catalog($request->attributes->get('trusted_actor'), $request->route('project'), $request->query());

        return response()->json(['data' => $data]);
    }

    public function write(Request $request, PeopleRolesService $service): mixed
    {
        if ($request->query()) {
            throw ValidationException::withMessages(['query' => 'Invalid query.']);
        }
        $actor = $request->attributes->get('trusted_actor');
        $result = match ($request->route('people_operation')) {
            'create' => $service->create($actor, $request->json()->all()),
            'update' => $service->update($actor, $request->route('role'), $request->json()->all()),
            'assign' => $service->assign($actor, $request->route('project'), $request->json()->all()),
        };

        return response()->json(['data' => $result['item']], $result['created'] && $request->method() === 'POST' ? 201 : 200);
    }
}
