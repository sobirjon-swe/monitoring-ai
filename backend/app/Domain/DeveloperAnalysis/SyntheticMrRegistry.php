<?php

namespace App\Domain\DeveloperAnalysis;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class SyntheticMrRegistry
{
    public function __construct(private readonly DeveloperCoordination $coordination, private readonly SecretGate $secrets, private readonly AuditWriter $audit) {}

    public function register(TrustedActor $actor, string $project, array $fixture, int $expectedRevision): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Synthetic registration is local-only.');
        }
        $keys = ['source_instance_id', 'repository_id', 'mr_id', 'revision_ordinal', 'base_sha', 'head_sha', 'source_status', 'occurred_at', 'received_at', 'source_updated_at', 'diff', 'redaction_policy'];
        if (array_diff(array_keys($fixture), $keys) || array_diff($keys, array_keys($fixture)) || $expectedRevision < 0
            || ! is_int($fixture['revision_ordinal']) || $fixture['revision_ordinal'] < 1 || $fixture['revision_ordinal'] > 2147483647) {
            throw new InvalidArgumentException('Invalid synthetic fixture.');
        }
        foreach ($keys as $key) {
            if ($key !== 'revision_ordinal' && ! is_string($fixture[$key])) {
                throw new InvalidArgumentException('Invalid synthetic fixture.');
            }
        }
        foreach (['source_instance_id', 'repository_id', 'mr_id'] as $key) {
            if (! preg_match('/\A[A-Za-z0-9_.-]{1,200}\z/D', $fixture[$key])) {
                throw new InvalidArgumentException('Invalid synthetic source identifier.');
            }
            $this->secrets->safeText($fixture[$key]);
        }
        foreach (['base_sha', 'head_sha'] as $key) {
            if (! preg_match('/\A[0-9a-f]{40}\z/D', $fixture[$key])) {
                throw new InvalidArgumentException('Invalid synthetic SHA.');
            }
        }
        if ($fixture['base_sha'] === $fixture['head_sha'] || ! in_array($fixture['source_status'], ['open', 'merged', 'released'], true)) {
            throw new InvalidArgumentException('Invalid synthetic revision.');
        }
        foreach (['occurred_at', 'received_at', 'source_updated_at'] as $key) {
            if (! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/D', $fixture[$key])) {
                throw new InvalidArgumentException('UTC fixture timestamps required.');
            }
            $parsed = date_parse($fixture[$key]);
            if ($parsed['warning_count'] || $parsed['error_count']) {
                throw new InvalidArgumentException('Invalid fixture timestamp.');
            }
        }
        $payloadHash = hash('sha256', DeveloperContract::canonical($fixture));
        $safe = $this->secrets->inspect($fixture['diff'], $fixture['redaction_policy']);
        $paths = $safe['accepted'] ? $this->paths($safe['text']) : [];
        // Raw fixture is absent from the transactional closure and all persisted bindings.
        unset($fixture['diff']);

        return $this->coordination->run($actor, $project, ['analysis.fixture.manage'], true, function () use ($actor, $project, $fixture, $expectedRevision, $payloadHash, $safe, $paths): array {
            $source = ['tenant_id' => $actor->tenantId, 'project_id' => $project, 'source_instance_id' => $fixture['source_instance_id'], 'repository_id' => $fixture['repository_id'], 'mr_id' => $fixture['mr_id']];
            $head = DB::table('developer_source_heads')->where($source)->lockForUpdate()->first();
            $event = DB::table('developer_source_events')->where($source)->where('revision_ordinal', $fixture['revision_ordinal'])->where('payload_hash', $payloadHash)->first();
            if ($event) {
                return ['accepted' => $event->snapshot_id !== null, 'snapshot_id' => $event->snapshot_id, 'event_id' => $event->id, 'current' => $head?->snapshot_id === $event->snapshot_id, 'revision' => $head?->revision ?? 0, 'reason_code' => $event->reason_code];
            }
            if (($head?->revision ?? 0) !== $expectedRevision) {
                throw new ConflictHttpException;
            }
            $eventId = (string) Str::uuid();
            $snapshotId = null;
            if ($safe['accepted']) {
                if (DB::table('developer_snapshots')->where($source)->where('revision_ordinal', $fixture['revision_ordinal'])->exists()) {
                    throw new ConflictHttpException;
                }
                $snapshotId = (string) Str::uuid();
                DB::table('developer_snapshots')->insert($source + [
                    'id' => $snapshotId, 'revision_ordinal' => $fixture['revision_ordinal'], 'base_sha' => $fixture['base_sha'], 'head_sha' => $fixture['head_sha'], 'source_status' => $fixture['source_status'],
                    'safe_diff' => $safe['text'], 'content_hash' => $safe['hash'], 'paths' => DeveloperContract::canonical($paths),
                    'redaction' => DeveloperContract::canonical(array_intersect_key($safe, array_flip(['version', 'policy', 'hash', 'mapping']))),
                    'actor_id' => $actor->id, 'occurred_at' => $fixture['occurred_at'], 'received_at' => $fixture['received_at'], 'source_updated_at' => $fixture['source_updated_at'],
                ]);
            }
            DB::table('developer_source_events')->insert($source + ['id' => $eventId, 'snapshot_id' => $snapshotId, 'revision_ordinal' => $fixture['revision_ordinal'], 'payload_hash' => $payloadHash, 'reason_code' => $safe['accepted'] ? 'approved' : $safe['reason_code'], 'actor_id' => $actor->id]);
            DB::table('developer_outbox')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'source_event_id' => $eventId]);
            $current = $safe['accepted'] && (! $head || $fixture['revision_ordinal'] > $head->revision_ordinal);
            $revision = $head?->revision ?? 0;
            if ($current) {
                $values = ['revision' => ++$revision, 'revision_ordinal' => $fixture['revision_ordinal'], 'snapshot_id' => $snapshotId];
                if ($head) {
                    if (DB::table('developer_source_heads')->where($source)->where('revision', $expectedRevision)->update($values) !== 1) {
                        throw new ConflictHttpException;
                    }
                } else {
                    DB::table('developer_source_heads')->insert($source + $values);
                }
            }
            if ($snapshotId) {
                $this->audit->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $project], 'developer.snapshot_registered', ['type' => 'developer_snapshots', 'id' => $snapshotId], $fixture['revision_ordinal'], 'approved_change');
            }
            app(AnalysisInvalidation::class)->observeProject($actor, $project, 'source:'.$eventId, ['source' => $source]);

            return ['accepted' => $safe['accepted'], 'snapshot_id' => $snapshotId, 'event_id' => $eventId, 'current' => $current, 'revision' => $revision, 'reason_code' => $safe['accepted'] ? 'approved' : $safe['reason_code']];
        });
    }

    private function paths(string $diff): array
    {
        $paths = [];
        $hunks = 0;
        $changes = 0;
        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, 'diff ')) {
                if (! preg_match('/\Adiff --git a\/(\S+) b\/(\S+)\z/D', $line, $m)) {
                    throw new InvalidArgumentException('Unsupported diff header.');
                }
                DeveloperContract::path($m[1]);
                DeveloperContract::path($m[2]);
                $paths[] = ['old_path' => $m[1], 'new_path' => $m[2]];
            } elseif (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ')) {
                if (! $paths) {
                    throw new InvalidArgumentException('Diff header required.');
                }
                $key = str_starts_with($line, '--- ') ? 'old_path' : 'new_path';
                $path = substr($line, 4);
                if ($path === '/dev/null') {
                    $paths[count($paths) - 1][$key] = null;
                } else {
                    $prefix = $key === 'old_path' ? 'a/' : 'b/';
                    if (! str_starts_with($path, $prefix)) {
                        throw new InvalidArgumentException('Invalid diff path marker.');
                    }
                    DeveloperContract::path(substr($path, 2));
                    if (substr($path, 2) !== $paths[count($paths) - 1][$key]) {
                        throw new InvalidArgumentException('Ambiguous diff path marker.');
                    }
                }
            } elseif (str_starts_with($line, '@@')) {
                $hunks++;
            } elseif (in_array($line[0] ?? '', ['+', '-'], true)) {
                $changes++;
            }
        }
        if (! $paths || count($paths) > 100 || $hunks > 2000 || $changes > 50000) {
            throw new InvalidArgumentException('Diff limits exceeded.');
        }

        return $paths;
    }
}
