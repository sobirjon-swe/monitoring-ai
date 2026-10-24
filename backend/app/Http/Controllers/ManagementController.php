<?php

namespace App\Http\Controllers;

use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementReadService;
use App\Domain\Management\ManagementReviewService;
use App\Domain\Management\ManagementSupportService;
use App\Http\Controllers\Concerns\StrictManagementJson;
use Illuminate\Http\Request;

class ManagementController extends Controller
{
    use StrictManagementJson;

    public function write(Request $request): mixed
    {
        return $this->managementResponse(function () use ($request) {
            $operation = $request->route('management_operation');
            $source = in_array($operation, ['open', 'support'], true);
            $controls = $this->managementControls($request, $source ? ['Idempotency-Key', 'Expected-Source-Revision'] : ['Idempotency-Key']);
            $payload = $this->managementJson($request, $source ? 'envelope' : $operation);
            if ($source && ! in_array($payload['operation'], $operation === 'open' ? ['action.open'] : ['support.record', 'support.correct', 'support.retract', 'support.decline'], true)) {
                $this->invalidManagement();
            }
            $actor = $request->attributes->get('trusted_actor');
            $project = strtolower($request->route('project'));
            $action = strtolower($request->route('action') ?? '');
            if ($operation === 'support' && $payload['body']->action_id !== $action) {
                $this->invalidManagement();
            }
            $key = $controls['Idempotency-Key'];

            return match ($operation) {
                'open' => app(ManagementActionService::class)->open($actor, $project, $payload, $key, $controls['Expected-Source-Revision']),
                'support' => app(ManagementSupportService::class)->record($actor, $project, $action, $payload, $key, $controls['Expected-Source-Revision']),
                'event' => app(ManagementActionService::class)->event($actor, $project, $action, $payload, $key),
                'review' => app(ManagementReviewService::class)->review($actor, $project, $action, $payload, $key),
            };
        }, true);
    }

    public function read(Request $request, ManagementReadService $service): mixed
    {
        return $this->managementResponse(function () use ($request, $service) {
            $query = $this->managementPage($request);
            $actor = $request->attributes->get('trusted_actor');
            $project = strtolower($request->route('project'));

            return $request->route('management_operation') === 'queue'
                ? $service->queue($actor, $project, $query)
                : $service->history($actor, $project, strtolower($request->route('action')), $query);
        });
    }
}
