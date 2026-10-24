<?php

namespace App\Http\Controllers;

use App\Domain\Browser\BrowserProjectReader;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BrowserProjectController
{
    public function index(Request $request, BrowserProjectReader $reader): mixed
    {
        if ($request->getContent() !== '' || array_diff(array_keys($request->query()), ['cursor'])) {
            throw ValidationException::withMessages(['query' => 'Invalid query.']);
        }
        $query = $request->validate(['cursor' => 'sometimes|required|string|max:1024']);

        return response()->json(['data' => $reader->page($request->attributes->get('trusted_actor'), $query['cursor'] ?? null)]);
    }

    public function capabilities(Request $request, BrowserProjectReader $reader): mixed
    {
        if ($request->getContent() !== '' || $request->query()) {
            throw ValidationException::withMessages(['query' => 'Invalid query.']);
        }

        return response()->json(['data' => $reader->capabilities($request->attributes->get('trusted_actor'), $request->route('project'))]);
    }
}
