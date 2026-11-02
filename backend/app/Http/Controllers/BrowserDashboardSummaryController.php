<?php

namespace App\Http\Controllers;

use App\Domain\Browser\DashboardSummaryReader;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BrowserDashboardSummaryController extends Controller
{
    public function show(Request $request, DashboardSummaryReader $reader): mixed
    {
        if ($request->query()) {
            throw ValidationException::withMessages(['query' => 'Dashboard summary does not accept query parameters.']);
        }

        return response()->json(['data' => $reader->read($request->attributes->get('trusted_actor'), strtolower($request->route('project')))]);
    }
}
