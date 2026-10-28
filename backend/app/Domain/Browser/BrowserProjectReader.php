<?php

namespace App\Domain\Browser;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\ProjectReadScope;
use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BrowserProjectReader
{
    public const PURPOSES = ['project.read', 'requirements.read', 'progress.read', 'qa.read', 'qa.source.read', 'ba.read', 'ba.source.read', 'ba.request.manage', 'management.read', 'management.source.read'];

    public function __construct(private readonly TenantContext $context, private readonly ProjectReadScope $scope) {}

    public function page(TrustedActor $actor, ?string $cursor): array
    {
        $after = $cursor === null ? null : $this->decode($actor, $cursor);

        return $this->context->run($actor->tenantId, function () use ($actor, $after): array {
            $query = $this->scope->apply(DB::table('projects'), $actor, now()->utc());
            if ($after !== null) {
                $query->where('projects.id', '>', $after);
            }
            $rows = $query->orderBy('projects.id')->limit(26)->get(['projects.id', 'projects.name', 'projects.revision']);
            $hasMore = $rows->count() > 25;
            $items = $rows->take(25)->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'revision' => $row->revision])->all();

            return ['items' => $items, 'next_cursor' => $hasMore ? $this->encode($actor, $items[24]['id']) : null];
        });
    }

    public function capabilities(TrustedActor $actor, string $project): array
    {
        app(AccessGateway::class)->authorize($actor, 'project.read', ['tenant_id' => $actor->tenantId, 'project_id' => $project]);

        return $this->context->run($actor->tenantId, function () use ($actor, $project): array {
            $instant = now()->utc();
            $at = $instant->format('Y-m-d H:i:s.u');
            $granted = DB::table('access_grants as g')->join('policy_versions as p', function ($join): void {
                $join->on('p.id', '=', 'g.policy_version_id')->on('p.tenant_id', '=', 'g.tenant_id');
            })->where('g.tenant_id', $actor->tenantId)->where('g.user_id', $actor->id)->whereIn('g.purpose', self::PURPOSES)
                ->where('p.status', 'active')->whereNull('g.revoked_at')->where('g.valid_from', '<=', $at)
                ->where(fn ($q) => $q->whereNull('g.valid_to')->orWhere('g.valid_to', '>', $at))
                ->where(fn ($q) => $q->where(fn ($q) => $q->where('g.scope_type', 'project')->where('g.project_id', $project))
                    ->orWhere(fn ($q) => $q->where('g.purpose', 'project.read')->where('g.scope_type', 'tenant')))
                ->distinct()->pluck('g.purpose')->all();

            return ['project_id' => $project, 'purposes' => array_values(array_intersect(self::PURPOSES, $granted)), 'observed_at' => $instant->format('Y-m-d\TH:i:s.uP')];
        });
    }

    private function encode(TrustedActor $actor, string $id): string
    {
        $body = base64_encode(json_encode([$actor->id, $actor->tenantId, $id], JSON_THROW_ON_ERROR));

        return $body.'.'.hash_hmac('sha256', 'browser.projects.v1.'.$body, $this->key());
    }

    private function decode(TrustedActor $actor, string $cursor): string
    {
        $invalid = fn () => throw ValidationException::withMessages(['cursor' => 'Invalid cursor.']);
        if (strlen($cursor) > 1024 || substr_count($cursor, '.') !== 1) {
            $invalid();
        }
        [$body, $mac] = explode('.', $cursor);
        if (! hash_equals(hash_hmac('sha256', 'browser.projects.v1.'.$body, $this->key()), $mac)) {
            $invalid();
        }
        $value = json_decode(base64_decode($body, true) ?: '', true);
        if (! is_array($value) || count($value) !== 3 || ($value[0] ?? null) !== $actor->id || ($value[1] ?? null) !== $actor->tenantId || ! is_string($value[2] ?? null) || ! Str::isUuid($value[2])) {
            $invalid();
        }

        return $value[2];
    }

    private function key(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new \LogicException('Browser cursor key unavailable.');
        }

        return $key;
    }
}
