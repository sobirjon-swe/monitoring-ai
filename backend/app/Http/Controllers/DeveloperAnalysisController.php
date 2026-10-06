<?php

namespace App\Http\Controllers;

use App\Domain\DeveloperAnalysis\AnalysisReadService;
use App\Domain\DeveloperAnalysis\AnalysisRequestService;
use App\Domain\DeveloperAnalysis\FindingReviewService;
use App\Http\Controllers\Concerns\StrictLifecycleJson;
use Illuminate\Http\Request;

class DeveloperAnalysisController extends Controller
{
    use StrictLifecycleJson;

    public function requestRun(Request $request, AnalysisRequestService $service): mixed
    {
        if ($request->query()) {
            $this->invalid();
        }
        $raw = $request->getContent();
        $payload = $this->json($raw);
        $decoded = json_decode($raw);
        foreach (['criterion_version_ids', 'task_version_ids'] as $key) {
            if (! is_object($decoded) || ! is_array($decoded->$key ?? null)) {
                $this->invalid();
            }
        }

        return response()->json(['data' => $service->request($request->attributes->get('trusted_actor'), $request->route('project'), $payload, $request->header('Idempotency-Key') ?? '')], 202);
    }

    public function run(Request $request, AnalysisReadService $service): mixed
    {
        if ($request->query()) {
            $this->invalid();
        }

        return response()->json(['data' => $service->run($request->attributes->get('trusted_actor'), $request->route('run'))]);
    }

    public function finding(Request $request, AnalysisReadService $service): mixed
    {
        if (array_diff(array_keys($request->query()), ['limit', 'cursor'])) {
            $this->invalid();
        }
        $limit = $request->query('limit', '50');
        $cursor = $request->query('cursor');
        if (! is_string($limit) || ! preg_match('/\A(?:[1-9][0-9]?|100)\z/D', $limit)
            || ($cursor !== null && (! is_string($cursor) || strlen($cursor) > 128))) {
            $this->invalid();
        }

        return response()->json(['data' => $service->finding($request->attributes->get('trusted_actor'), $request->route('finding'), (int) $limit, $cursor)]);
    }

    public function review(Request $request, FindingReviewService $service): mixed
    {
        if ($request->query()) {
            $this->invalid();
        }
        $payload = $this->json($request->getContent());
        $keys = ['expected_revision', 'decision', 'reason'];
        if (array_diff(array_keys($payload), $keys) || array_diff($keys, array_keys($payload))
            || ! is_string($payload['decision'] ?? null) || ! is_string($payload['reason'] ?? null)) {
            $this->invalid();
        }

        return response()->json(['data' => $service->review($request->attributes->get('trusted_actor'), $request->route('finding'),
            $this->integer($payload, 'expected_revision', 0), $payload['decision'], $payload['reason'])]);
    }
}
