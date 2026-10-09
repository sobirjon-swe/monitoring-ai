<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Ba\BaContract;
use App\Support\LocalBaDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

trait StrictBaJson
{
    protected function baResponse(Closure $work, bool $creation = false): mixed
    {
        try {
            try {
                app(LocalBaDatabase::class)->assertRuntime();
            } catch (Throwable) {
                throw new ServiceUnavailableHttpException;
            }
            $data = $work();

            return response()->json(['data' => $data], $creation && ! ($data['replayed'] ?? false) ? 201 : 200);
        } catch (InvalidArgumentException) {
            $this->invalidBa();
        }
    }

    protected function baJson(Request $request, string $operation): array
    {
        if ($request->query()) {
            $this->invalidBa();
        }
        $raw = $request->getContent();
        if (strlen($raw) > 65536) {
            abort(413);
        }
        $parsed = app(BaContract::class)->parse($raw);
        app(BaContract::class)->validate($operation, $parsed);

        // Preserve JSON object/array identity for the service's independent validation.
        return $parsed;
    }

    protected function baControls(Request $request, array $required = []): array
    {
        $controls = [];
        foreach (['Idempotency-Key', 'Expected-Source-Revision', 'Expected-Series-Revision', 'Expected-Project-Generation', 'Expected-Revision'] as $name) {
            $values = $request->headers->all($name);
            if (! in_array($name, $required, true)) {
                if ($values) {
                    $this->invalidBa();
                }

                continue;
            }
            if (count($values) !== 1 || ! is_string($values[0])) {
                $this->invalidBa();
            }
            $value = $values[0];
            if ($name === 'Idempotency-Key') {
                if (! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $value)) {
                    $this->invalidBa();
                }
                $controls[$name] = $value;
            } else {
                if (! preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', $value) || (int) $value > 9007199254740991) {
                    $this->invalidBa();
                }
                $controls[$name] = (int) $value;
            }
        }

        return $controls;
    }

    protected function baPage(Request $request): array
    {
        $this->baControls($request);
        if ($request->getContent() !== '' || array_diff(array_keys($request->query()), ['limit', 'cursor', 'department'])) {
            $this->invalidBa();
        }
        $limit = $request->query('limit', '20');
        $cursor = $request->query('cursor');
        $department = $request->query('department');
        if (! is_string($limit) || ! preg_match('/\A(?:[1-9]|[1-4][0-9]|50)\z/D', $limit)
            || ($cursor !== null && (! is_string($cursor) || strlen($cursor) > 4096 || ! preg_match('/\A[A-Za-z0-9+\/=]+\.[a-f0-9]{64}\z/D', $cursor)))
            || ($department !== null && (! is_string($department) || ! Str::isUuid($department)))) {
            $this->invalidBa();
        }

        return ['limit' => (int) $limit, 'cursor' => $cursor, 'department_id' => $department === null ? null : strtolower($department)];
    }

    protected function invalidBa(): never
    {
        throw ValidationException::withMessages(['payload' => 'Invalid BA payload.']);
    }
}
