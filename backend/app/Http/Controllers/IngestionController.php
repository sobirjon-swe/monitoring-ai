<?php

namespace App\Http\Controllers;

use App\Domain\Ingestion\IngestionEnvelopeValidator;
use App\Domain\Ingestion\IngestionService;
use Illuminate\Http\Request;

final class IngestionController extends Controller
{
    public function accept(Request $request, IngestionService $ingestion, IngestionEnvelopeValidator $validator): mixed
    {
        $raw = $request->getContent();
        $envelope = $validator->validateJson($raw);
        $result = $ingestion->accept($request->attributes->get('trusted_actor'), $request->route('connector'), $envelope, $raw);

        return response()->json(['data' => $result], $result['duplicate'] ? 200 : 201);
    }
}
