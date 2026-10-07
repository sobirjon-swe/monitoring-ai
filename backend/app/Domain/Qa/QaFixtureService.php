<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class QaFixtureService
{
    public function register(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision): array
    {
        return app(QaInvalidation::class)->guardRequest($actor, $project, $envelope['operation'] ?? '', $key, fn () => $this->registerRequest($actor, $project, $envelope, $key, $expectedSourceRevision));
    }

    private function registerRequest(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Synthetic registration is local-only.');
        }
        $envelope = app(QaContract::class)->validate('envelope', $envelope);
        $kind = $envelope['operation'];
        if (! in_array($kind, ['build', 'environment', 'definition'], true) || $expectedSourceRevision < 0) {
            throw new InvalidArgumentException('Invalid QA fixture operation.');
        }
        $refs = app(TenantContext::class)->run($actor->tenantId, function () use ($project, $envelope) {
            $id = DB::table('qa_receipts')->where('project_id', $project)->where('source_instance_id', $envelope['source_instance_id'])->where('object_id', $envelope['object_id'])->where('source_revision', $envelope['source_revision'])->value('id');

            return $id ? ['receipt' => [$id]] : [];
        });
        $graph = app(QaPlanService::class)->collectPins($actor, $project, $refs, [$envelope['source_instance_id']]);

        return app(QaCoordination::class)->run($actor, $project, ['qa.fixture.manage', 'qa.source.read'], true, function ($proofs) use ($actor, $project, $envelope, $key, $expectedSourceRevision, $kind, $graph) {
            app(QaPlanService::class)->loadGraphLocked($project, $graph);
            foreach ($graph['requests'] as $request) {
                if (! ($proofs[$request['key']]['valid'] ?? false)) {
                    throw new AccessDeniedHttpException;
                }
            }
            $writer = app(QaReceiptWriter::class);
            $result = $writer->recordLocked($actor, $project, $envelope, $key, $expectedSourceRevision, $proofs['current']['proof']);
            if ($result['replayed']) {
                // A concurrently created receipt requires its own original proof on a fresh retry.
                if (! isset($graph['refs']['receipt']) || ! in_array($result['receipt_id'], $graph['refs']['receipt'], true)) {
                    throw new ConflictHttpException;
                }
                app(QaInvalidation::class)->assertReplayCurrentLocked($project, ['receipt_id' => $result['receipt_id']]);
                $writer->bindRequestLocked($actor, $project, $kind, $key, $result['input_hash'], ['receipt_id' => $result['receipt_id']]);

                return $result;
            }
            $body = $envelope['body'];
            $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $project];
            $id = (string) Str::uuid();
            if ($kind === 'definition') {
                // Logical definition successors require a current source arrival; the transaction rolls back historical receipts.
                if (! $result['current']) {
                    throw new ConflictHttpException;
                }
                $criterion = DB::table('criterion_versions')->where('project_id', $project)->where('id', $body['criterion_version_id'])->first();
                if (! $criterion) {
                    throw new NotFoundHttpException;
                }
                app(QaPlanService::class)->assertCriteriaCurrentLocked($project, [$criterion->id]);
                $identity = DB::table('qa_definition_identities')->where('project_id', $project)->where('logical_key', $body['logical_key'])->lockForUpdate()->first();
                if ($identity && ($identity->criterion_id !== $criterion->criterion_id || $body['revision'] !== $identity->revision + 1)) {
                    throw new ConflictHttpException;
                }
                if (! $identity && $body['revision'] !== 1) {
                    throw new ConflictHttpException;
                }
                $identityId = $identity?->id ?? (string) Str::uuid();
                if (! $identity) {
                    DB::table('qa_definition_identities')->insert(['id' => $identityId, 'criterion_id' => $criterion->criterion_id, 'logical_key' => $body['logical_key']] + $scope);
                }
                $definition = $body;
                unset($definition['logical_key']);
                DB::table('qa_definition_versions')->insert(['id' => $id, 'receipt_id' => $result['receipt_id'], 'test_identity_id' => $identityId, 'criterion_id' => $criterion->criterion_id, 'content_hash' => hash('sha256', QaContract::canonical($body))] + $scope + $definition);
                DB::table('qa_definition_identities')->where('id', $identityId)->update(['revision' => $body['revision'], 'current_definition_id' => $id]);
            } else {
                $table = $kind === 'build' ? 'qa_build_versions' : 'qa_environment_versions';
                if (DB::table($table)->where('project_id', $project)->where('logical_key', $body['logical_key'])->where('revision', $body['revision'])->exists()) {
                    throw new ConflictHttpException;
                }
                if ($kind === 'build') {
                    if (isset($body['base_sha']) !== isset($body['head_sha']) || (isset($body['base_sha']) && $body['base_sha'] === $body['head_sha'])) {
                        throw new InvalidArgumentException('Invalid immutable build SHAs.');
                    }
                    if (isset($body['mr_snapshot_id'])) {
                        $snapshot = DB::table('developer_snapshots')->where('project_id', $project)->where('id', $body['mr_snapshot_id'])->first(['base_sha', 'head_sha']);
                        if (! $snapshot) {
                            throw new NotFoundHttpException;
                        }
                        // Compare immutable metadata only; this is no Git lookup or parser activation.
                        if (isset($body['base_sha']) && ($body['base_sha'] !== $snapshot->base_sha || $body['head_sha'] !== $snapshot->head_sha)) {
                            throw new ConflictHttpException;
                        }
                    }
                }
                DB::table($table)->insert(['id' => $id, 'receipt_id' => $result['receipt_id']] + $scope + $body);
            }
            $writer->bindRequestLocked($actor, $project, $kind, $key, $result['input_hash'], ['receipt_id' => $result['receipt_id']]);
            app(QaChangeWriter::class)->appendLocked($actor, $project, 'source_recorded', ['receipt_id' => $result['receipt_id']], $result['input_hash'], $proofs['current']['proof']);
            $result['record_id'] = $id;

            return $result;
        }, $graph['requests']);
    }
}
