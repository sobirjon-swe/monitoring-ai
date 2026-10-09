<?php

namespace App\Domain\Ba;

use App\Domain\Access\TrustedActor;
use App\Domain\Audit\AuditWriter;
use App\Support\LocalQaDatabase;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class BaFixtureService
{
    public function registerParty(TrustedActor $actor, string $project, array $envelope, string $key, int $expectedSourceRevision): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Synthetic BA registration is local-only.');
        }
        app(LocalQaDatabase::class)->assertRuntime();
        $envelope = app(BaContract::class)->validate('envelope', $envelope);
        if ($envelope['operation'] !== 'party.register' || $expectedSourceRevision < 0) {
            throw new InvalidArgumentException('Invalid synthetic party operation.');
        }
        $refs = app(TenantContext::class)->run($actor->tenantId, function () use ($project, $envelope) {
            $receipt = DB::table('ba_receipts')->where('project_id', $project)->where('source_instance_id', $envelope['source_instance_id'])->where('family', 'party')->where('object_id', $envelope['object_id'])->where('source_revision', $envelope['source_revision'])->value('id');
            $refs = $receipt ? ['receipt' => [$receipt]] : [];
            if ($envelope['body']['predecessor_party_version_id'] !== null) {
                $refs['party'] = [$envelope['body']['predecessor_party_version_id']];
            }

            return $refs;
        });
        $graph = app(BaPins::class)->collect($actor, $project, $refs, [$envelope['source_instance_id']]);
        if ($graph['coverage'] !== 'complete') {
            throw new ServiceUnavailableHttpException;
        }
        $result = app(BaCoordination::class)->run($actor, $project, ['ba.fixture.manage', 'ba.source.read'], true, function ($proofs) use ($actor, $project, $envelope, $key, $expectedSourceRevision, $graph) {
            $loaded = app(BaPins::class)->loadLocked($project, $graph);
            foreach ($proofs as $proofKey => $result) {
                if (! $result['valid']) {
                    if (in_array($result['reason_code'], ['access_revoked', 'source_revoked'], true) && str_starts_with($proofKey, 'receipt:')) {
                        $id = substr($proofKey, 8);
                        $receipt = $loaded['rows']['receipt'][$id];
                        app(BaTerminal::class)->observeLocked($project, 'receipt', $id, hash('sha256', $receipt->authority_proof), $result['reason_code']);
                    }

                    return ['denied' => true];
                }
            }
            $proof = $proofs['current']['proof'];
            $proof['sources'] = $proofs['sources']['proof']['sources'];
            $writer = app(BaReceiptWriter::class);
            $ticket = $writer->beginSourceLocked($actor, $project, $envelope, $key, $expectedSourceRevision, $proof);
            if ($ticket['replayed']) {
                $writer->bindOutputLocked($ticket, $ticket['output']);

                return $ticket['output'] + ['replayed' => true, 'current' => $ticket['current'], 'complete' => $ticket['complete'], 'synthetic' => true, 'ai_interpretation' => 'unavailable', 'customer_authenticity' => 'not_verified'];
            }
            $body = $envelope['body'];
            if ($body['predecessor_party_version_id'] !== null) {
                $predecessor = $loaded['rows']['party'][$body['predecessor_party_version_id']] ?? null;
                if (! $predecessor || $predecessor->source_instance_id !== $envelope['source_instance_id'] || $predecessor->object_id !== $envelope['object_id'] || $predecessor->source_revision >= $envelope['source_revision']) {
                    throw new ConflictHttpException;
                }
            }
            $id = (string) Str::uuid();
            DB::table('ba_source_party_versions')->insert(['id' => $id, 'tenant_id' => $actor->tenantId, 'project_id' => $project, 'receipt_id' => $ticket['receipt_id'], 'source_instance_id' => $envelope['source_instance_id'], 'object_id' => $envelope['object_id'], 'source_revision' => $envelope['source_revision'], 'content_hash' => hash('sha256', BaContract::canonical($body))] + $body);
            $output = ['receipt_id' => $ticket['receipt_id'], 'party_version_id' => $id];
            $writer->bindOutputLocked($ticket, $output);
            app(AuditWriter::class)->record($actor->id, ['tenant_id' => $actor->tenantId, 'project_id' => $project], 'ba.party_registered', ['type' => 'ba_source_party_versions', 'id' => $id], $envelope['source_revision'], 'approved_change');

            return $output + ['replayed' => false, 'current' => $ticket['current'], 'complete' => $ticket['complete'], 'synthetic' => true, 'ai_interpretation' => 'unavailable', 'customer_authenticity' => 'not_verified'];
        }, $graph['requests'], observeOriginalSourceInvalidity: true);
        if (isset($result['denied'])) {
            throw new AccessDeniedHttpException;
        }

        return $result;
    }
}
