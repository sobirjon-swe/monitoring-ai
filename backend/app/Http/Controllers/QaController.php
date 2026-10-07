<?php

namespace App\Http\Controllers;

use App\Domain\Qa\QaDefectService;
use App\Domain\Qa\QaDefinitionService;
use App\Domain\Qa\QaExecutionService;
use App\Domain\Qa\QaFixtureService;
use App\Domain\Qa\QaHandoffService;
use App\Domain\Qa\QaPlanService;
use App\Domain\Qa\QaReadService;
use App\Domain\Qa\QaSelectionService;
use App\Http\Controllers\Concerns\StrictQaJson;
use Illuminate\Http\Request;

class QaController extends Controller
{
    use StrictQaJson;

    public function create(Request $request): mixed
    {
        return $this->qaResponse(function () use ($request) {
            $kind = $request->route('qa_operation');
            $required = ['Idempotency-Key'];
            if (in_array($kind, ['fixture', 'execution'], true)) {
                $required[] = 'Expected-Source-Revision';
            }
            if ($kind === 'execution') {
                $required[] = 'Expected-Series-Revision';
            }
            $controls = $this->qaControls($request, $required);
            $schema = match ($kind) {
                'entry' => 'plan.entry', 'plan' => 'plan.compose', default => 'envelope',
            };
            $payload = $this->qaJson($request, $schema);
            $allowed = match ($kind) {
                'fixture' => ['build', 'environment', 'definition'], 'execution' => ['execution'],
                'defect' => ['defect'], 'handoff' => ['handoff'], default => null,
            };
            if ($allowed !== null && (! in_array($payload['operation'], $allowed, true)
                || (in_array($kind, ['defect', 'handoff'], true) && $payload['source_revision'] !== 1))) {
                $this->invalidQa();
            }
            $actor = $request->attributes->get('trusted_actor');
            $project = strtolower($request->route('project'));
            $key = $controls['Idempotency-Key'];

            return match ($kind) {
                'fixture' => app(QaFixtureService::class)->register($actor, $project, $payload, $key, $controls['Expected-Source-Revision']),
                'execution' => app(QaExecutionService::class)->register($actor, $project, $payload, $key, $controls['Expected-Source-Revision'], $controls['Expected-Series-Revision']),
                'entry' => app(QaPlanService::class)->createEntry($actor, $project, $payload, $key),
                'plan' => app(QaPlanService::class)->compose($actor, $project, $payload, $key),
                'defect' => app(QaDefectService::class)->report($actor, $project, $payload, $key),
                'handoff' => app(QaHandoffService::class)->open($actor, $project, $payload, $key),
            };
        }, true);
    }

    public function decision(Request $request): mixed
    {
        return $this->qaResponse(function () use ($request) {
            $this->qaControls($request);
            $operation = $request->route('qa_operation');
            $payload = $this->qaJson($request, $operation);
            $actor = $request->attributes->get('trusted_actor');
            $project = strtolower($request->route('project'));

            return match ($operation) {
                'definition.review' => app(QaDefinitionService::class)->review($actor, $project, strtolower($request->route('definition')), $payload),
                'plan.review' => app(QaPlanService::class)->review($actor, $project, strtolower($request->route('plan')), $payload),
                'selection.select' => app(QaSelectionService::class)->select($actor, $project, $payload),
                'defect.transition' => app(QaDefectService::class)->transition($actor, $project, strtolower($request->route('defect')), $payload),
                'handoff.transition' => app(QaHandoffService::class)->transition($actor, $project, strtolower($request->route('handoff')), $payload),
            };
        });
    }

    public function read(Request $request, QaReadService $service): mixed
    {
        return $this->qaResponse(function () use ($request, $service) {
            $operation = $request->route('qa_operation');
            [$limit, $cursor, $department] = $this->qaPage($request, $operation !== 'verification');
            $actor = $request->attributes->get('trusted_actor');
            $project = strtolower($request->route('project'));

            return match ($operation) {
                'verification' => $service->verification($actor, $project, $department),
                'queue' => $service->queue($actor, $project, $limit, $cursor, $department),
                'history' => $service->history($actor, $project, $request->route('kind'), strtolower($request->route('subject')), $limit, $cursor, $department),
            };
        });
    }
}
