<?php

namespace App\Http\Controllers;

use App\Domain\Lifecycle\ProgressCalculator;
use App\Http\Controllers\Concerns\StrictLifecycleJson;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProgressController extends Controller
{
    use StrictLifecycleJson;

    public function handle(Request $request, ProgressCalculator $service): mixed
    {
        $q = $request->query();
        if (array_diff(array_keys($q), ['baseline_id']) || (isset($q['baseline_id']) && (! is_string($q['baseline_id']) || ! Str::isUuid($q['baseline_id'])))) {
            $this->invalid();
        }

        return response()->json(['data' => $service->calculate($request->attributes->get('trusted_actor'), $request->route('project'), $q['baseline_id'] ?? null)]);
    }
}
