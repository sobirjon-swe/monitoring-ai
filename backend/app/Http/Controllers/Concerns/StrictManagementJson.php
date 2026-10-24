<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Management\ManagementContract;
use App\Support\LocalManagementDatabase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

trait StrictManagementJson
{
    protected function managementResponse(Closure $work, bool $creation = false): mixed
    {
        try {
            try {
                app(LocalManagementDatabase::class)->assertRuntime();
            } catch (Throwable) {
                throw new ServiceUnavailableHttpException;
            }
            $data = $work();

            return response()->json(['data' => $data], $creation && ! ($data['replayed'] ?? false) ? 201 : 200);
        } catch (InvalidArgumentException) {
            $this->invalidManagement();
        }
    }

    protected function managementJson(Request $request, string $operation): array
    {
        if ($request->query()) {
            $this->invalidManagement();
        }
        $raw = $request->getContent();
        if (strlen($raw) > 65536) {
            abort(413);
        }
        $parsed = app(ManagementContract::class)->parse($raw);
        if (in_array($operation, ['event', 'review'], true)) {
            $allowed = $operation === 'event'
                ? ['action.revise', 'action.reopen', 'action.withdraw', 'assignment.set', 'context.add']
                : ['outcome.confirm', 'outcome.reject', 'outcome.revoke'];
            if (! in_array($parsed['operation'] ?? null, $allowed, true)) {
                $this->invalidManagement();
            }
            $operation = $parsed['operation'];
        }
        app(ManagementContract::class)->validate($operation, $parsed);

        // Preserve JSON object/array identity for the service's independent validation.
        return $parsed;
    }

    protected function managementControls(Request $request, array $required = []): array
    {
        $controls = [];
        foreach (['Idempotency-Key', 'Expected-Source-Revision', 'Expected-Series-Revision', 'Expected-Project-Generation', 'Expected-Revision'] as $name) {
            $values = $request->headers->all($name);
            if (! in_array($name, $required, true)) {
                if ($values) {
                    $this->invalidManagement();
                }

                continue;
            }
            if (count($values) !== 1 || ! is_string($values[0])) {
                $this->invalidManagement();
            }
            $value = $values[0];
            if ($name === 'Idempotency-Key') {
                if (! preg_match('/\A[A-Za-z0-9._:-]{1,100}\z/D', $value)) {
                    $this->invalidManagement();
                }
                $controls[$name] = $value;
            } else {
                if (! preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/D', $value) || (int) $value > 9007199254740991) {
                    $this->invalidManagement();
                }
                $controls[$name] = (int) $value;
            }
        }

        return $controls;
    }

    protected function managementPage(Request $request): array
    {
        $this->managementControls($request);
        if ($request->getContent() !== '' || array_diff(array_keys($request->query()), ['limit', 'cursor', 'department_id'])) {
            $this->invalidManagement();
        }
        $limit = $request->query('limit', '20');
        $cursor = $request->query('cursor');
        $department = $request->query('department_id');
        if (! is_string($limit) || ! preg_match('/\A(?:[1-9]|[1-4][0-9]|50)\z/D', $limit)
            || ($cursor !== null && (! is_string($cursor) || strlen($cursor) > 4096 || ! preg_match('/\A[A-Za-z0-9+\/=]+\.[a-f0-9]{64}\z/D', $cursor)))
            || ($department !== null && (! is_string($department) || ! Str::isUuid($department)))) {
            $this->invalidManagement();
        }

        return ['limit' => (int) $limit, 'cursor' => $cursor, 'department_id' => $department === null ? null : strtolower($department)];
    }

    protected function invalidManagement(): never
    {
        throw ValidationException::withMessages(['payload' => 'Invalid management payload.']);
    }
}
