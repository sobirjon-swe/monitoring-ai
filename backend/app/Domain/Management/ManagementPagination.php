<?php

namespace App\Domain\Management;

use App\Domain\Access\TrustedActor;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ManagementPagination
{
    /** @return array{limit:int,department_id:?string,cursor:?string} */
    public function query(array $query): array
    {
        if (array_diff(array_keys($query), ['limit', 'cursor', 'department_id'])) {
            throw new InvalidArgumentException('Invalid management query.');
        }
        $limit = $query['limit'] ?? 20;
        if (is_string($limit) && preg_match('/\A[1-9][0-9]?\z/D', $limit)) {
            $limit = (int) $limit;
        }
        if (! is_int($limit) || $limit < 1 || $limit > 50 || (isset($query['department_id']) && (! is_string($query['department_id']) || ! Str::isUuid($query['department_id']))) || (isset($query['cursor']) && (! is_string($query['cursor']) || $query['cursor'] === '' || strlen($query['cursor']) > 4096))) {
            throw new InvalidArgumentException('Invalid management query.');
        }

        return ['limit' => $limit, 'department_id' => $query['department_id'] ?? null, 'cursor' => $query['cursor'] ?? null];
    }

    public function decode(TrustedActor $actor, string $project, string $resource, ?string $action, array $query, int $generation): ?array
    {
        $query = $this->query($query);
        $cursor = $query['cursor'];
        if ($cursor === null) {
            return null;
        }
        if (substr_count($cursor, '.') !== 1) {
            throw new ConflictHttpException('Management cursor conflict.');
        }
        [$body, $mac] = explode('.', $cursor);
        if (! preg_match('/\A[0-9a-f]{64}\z/D', $mac) || ! hash_equals(hash_hmac('sha256', $body, $this->key()), $mac)) {
            throw new ConflictHttpException('Management cursor conflict.');
        }
        try {
            $decoded = base64_decode($body, true);
            $value = $decoded === false ? null : json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($value) || array_keys($value) !== ['position', 'scope'] || ManagementContract::canonical($value['scope']) !== ManagementContract::canonical($this->scope($actor, $project, $resource, $action, $query, $generation))) {
                throw new LogicException;
            }
            $this->position($resource, $value['position']);

            return $resource === 'queue' ? ['opened_at' => $value['position']['opened_at'], 'id' => $value['position']['id']] : ['revision' => $value['position']['revision'], 'id' => $value['position']['id']];
        } catch (\Throwable) {
            throw new ConflictHttpException('Management cursor conflict.');
        }
    }

    public function encode(TrustedActor $actor, string $project, string $resource, ?string $action, array $query, int $generation, array $last): string
    {
        $query = $this->query($query);
        $this->position($resource, $last);
        $body = base64_encode(ManagementContract::canonical(['scope' => $this->scope($actor, $project, $resource, $action, $query, $generation), 'position' => $last]));
        $cursor = $body.'.'.hash_hmac('sha256', $body, $this->key());
        if (strlen($cursor) > 4096) {
            throw new LogicException('Management cursor size unavailable.');
        }

        return $cursor;
    }

    private function scope(TrustedActor $actor, string $project, string $resource, ?string $action, array $query, int $generation): array
    {
        if (! Str::isUuid($project) || ! in_array($resource, ['queue', 'history'], true) || ($resource === 'history' ? ! Str::isUuid($action ?? '') : $action !== null) || $generation < 0) {
            throw new InvalidArgumentException('Invalid management cursor scope.');
        }

        return ['tenant' => $actor->tenantId, 'actor' => $actor->id, 'project' => $project, 'resource' => $resource, 'action' => $action, 'department' => $query['department_id'], 'limit' => $query['limit'], 'generation' => $generation];
    }

    private function position(string $resource, mixed $position): void
    {
        if (! is_array($position) || array_is_list($position) || ! Str::isUuid($position['id'] ?? '') || ($resource === 'history' ? (array_diff(array_keys($position), ['revision', 'id']) || count($position) !== 2 || ! is_int($position['revision'] ?? null) || $position['revision'] < 1) : (array_diff(array_keys($position), ['opened_at', 'id']) || count($position) !== 2 || ! is_string($position['opened_at'] ?? null) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/D', $position['opened_at'])))) {
            throw new InvalidArgumentException('Invalid management cursor position.');
        }
    }

    private function key(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new LogicException('Management cursor key unavailable.');
        }

        return $key;
    }
}
