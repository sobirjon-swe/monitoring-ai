<?php

namespace App\Http\Controllers;

use App\Domain\Ba\BaAnswerService;
use App\Domain\Ba\BaClarificationService;
use App\Domain\Ba\BaReadService;
use App\Domain\Ba\BaReviewService;
use App\Http\Controllers\Concerns\StrictBaJson;
use Illuminate\Http\Request;

class BaController extends Controller
{
    use StrictBaJson;

    public function write(Request $request): mixed
    {
        return $this->baResponse(function () use ($request) {
            $operation = $request->route('ba_operation');
            $source = in_array($operation, ['open', 'answer'], true);
            $controls = $this->baControls($request, $source ? ['Idempotency-Key', 'Expected-Source-Revision'] : ['Idempotency-Key']);
            $payload = $this->baJson($request, $source ? 'envelope' : $operation);
            if ($source && ! in_array($payload['operation'], $operation === 'open' ? ['request.open'] : ['answer.record', 'answer.correct', 'answer.retract'], true)) {
                $this->invalidBa();
            }
            $actor = $request->attributes->get('trusted_actor');
            $project = strtolower($request->route('project'));
            $id = strtolower($request->route('clarification') ?? '');
            $key = $controls['Idempotency-Key'];

            return match ($operation) {
                'open' => app(BaClarificationService::class)->open($actor, $project, $payload, $key, $controls['Expected-Source-Revision']),
                'answer' => app(BaAnswerService::class)->record($actor, $project, $id, $payload, $key, $controls['Expected-Source-Revision']),
                'event' => app(BaClarificationService::class)->event($actor, $project, $id, $payload, $key),
                'review' => app(BaReviewService::class)->review($actor, $project, $id, $payload, $key),
            };
        }, true);
    }

    public function read(Request $request, BaReadService $service): mixed
    {
        return $this->baResponse(function () use ($request, $service) {
            $query = $this->baPage($request);
            $actor = $request->attributes->get('trusted_actor');
            $project = strtolower($request->route('project'));

            return $request->route('ba_operation') === 'queue'
                ? $service->queue($actor, $project, $query)
                : $service->history($actor, $project, strtolower($request->route('clarification')), $query);
        });
    }
}
