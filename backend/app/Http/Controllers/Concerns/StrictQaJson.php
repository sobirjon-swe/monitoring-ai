<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Qa\QaContract;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait StrictQaJson
{
    protected function qaResponse(Closure $work, bool $creation = false): mixed
    {
        try {
            $data = $work();

            return response()->json(['data' => $data], $creation && ! ($data['replayed'] ?? false) ? 201 : 200);
        } catch (InvalidArgumentException) {
            $this->invalidQa();
        }
    }

    protected function qaJson(Request $request, string $operation): array
    {
        if ($request->query()) {
            $this->invalidQa();
        }
        $raw = $request->getContent();
        if (strlen($raw) > 65536) {
            abort(413);
        }

        $parsed = app(QaContract::class)->parse($raw);
        app(QaContract::class)->validate($operation, $parsed);

        // Services validate again: retain empty-object identity until their own validation.
        return $parsed;
    }

    /** Only the named QA controls are restricted; normal HTTP headers remain available. */
    protected function qaControls(Request $request, array $required = []): array
    {
        $controls = [];
        foreach (['Idempotency-Key', 'Expected-Source-Revision', 'Expected-Series-Revision'] as $name) {
            $values = $request->headers->all($name);
            if (! in_array($name, $required, true)) {
                if ($values) {
                    $this->invalidQa();
                }

                continue;
            }
            if (count($values) !== 1 || ! is_string($values[0])) {
                $this->invalidQa();
            }
            $value = $values[0];
            if ($name === 'Idempotency-Key') {
                if (! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $value)) {
                    $this->invalidQa();
                }
                $controls[$name] = $value;
            } else {
                if (! preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', $value) || (int) $value > 9007199254740991) {
                    $this->invalidQa();
                }
                $controls[$name] = (int) $value;
            }
        }

        return $controls;
    }

    protected function qaPage(Request $request, bool $paginated = true): array
    {
        $this->qaControls($request);
        $allowed = $paginated ? ['limit', 'cursor', 'department'] : ['department'];
        if ($request->getContent() !== '' || array_diff(array_keys($request->query()), $allowed)) {
            $this->invalidQa();
        }
        $department = $request->query('department');
        if ($department !== null && (! is_string($department) || ! Str::isUuid($department))) {
            $this->invalidQa();
        }
        $limit = $request->query('limit', '50');
        $cursor = $request->query('cursor');
        if (! is_string($limit) || ! preg_match('/\A(?:[1-9][0-9]?|100)\z/D', $limit)
            || ($cursor !== null && (! is_string($cursor) || strlen($cursor) > 128 || ! preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor)))) {
            $this->invalidQa();
        }

        return [(int) $limit, $cursor, $department === null ? null : strtolower($department)];
    }

    protected function invalidQa(): never
    {
        throw ValidationException::withMessages(['payload' => 'Invalid QA payload.']);
    }
}
