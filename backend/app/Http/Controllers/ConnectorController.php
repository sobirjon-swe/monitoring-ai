<?php

namespace App\Http\Controllers;

use App\Domain\Access\AccessGateway;
use App\Domain\Ingestion\ConnectorService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ConnectorController extends Controller
{
    public function handle(Request $request, ConnectorService $connectors): mixed
    {
        $actor = $request->attributes->get('trusted_actor');
        $project = $request->route('project');
        $read = $request->isMethod('get') || $request->isMethod('head');
        abort_unless($read || $request->isMethod('post'), 405);
        if ($read) {
            return response()->json(['data' => $project === null ? $connectors->status($actor, $request->route('connector')) : $connectors->listing($actor, $project)]);
        }
        // Authorize before validation to avoid exposing source configuration to denied actors.
        app(AccessGateway::class)->authorize($actor, 'connector.manage', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);
        $data = $request->validate(['source_instance_id' => 'required|uuid', 'adapter' => 'required|in:synthetic-v1', 'status' => 'sometimes|in:active,disabled']);
        try {
            $result = $connectors->create($actor, $project, $data);
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0], ['23503', '23505'], true)) {
                throw new ConflictHttpException;
            }
            throw $exception;
        }

        return response()->json(['data' => $result], 201);
    }
}
