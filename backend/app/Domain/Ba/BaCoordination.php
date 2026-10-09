<?php

namespace App\Domain\Ba;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BaCoordination
{
    public function __construct(private readonly TenantContext $context, private readonly BaAuthority $authority) {}

    public function run(TrustedActor $actor, string $project, array $purposes, bool $requireOpen, Closure $work, array $requests = [], ?string $department = null, array $subjectRefs = [], bool $observeOriginalSourceInvalidity = false, array $coordinatorRefs = [], array $requestSubjectRefs = []): mixed
    {
        if (DB::scalar("select nullif(current_setting('app.ba_authority_closed',true),'')") !== null) {
            throw new LogicException('Nested BA coordination is prohibited.');
        }
        if ($observeOriginalSourceInvalidity && array_diff(['ba.fixture.manage', 'ba.source.read'], $purposes)) {
            throw new AccessDeniedHttpException;
        }

        return $this->context->run($actor->tenantId, function () use ($actor, $project, $purposes, $requireOpen, $work, $requests, $department, $subjectRefs, $observeOriginalSourceInvalidity, $coordinatorRefs, $requestSubjectRefs) {
            if (! DB::table('projects')->where('id', $project)->lockForUpdate()->first()) {
                throw new NotFoundHttpException;
            }
            $state = DB::table('lifecycle_project_state')->where('project_id', $project)->lockForUpdate()->first();
            if ($requireOpen && $state?->is_closed) {
                throw new ConflictHttpException;
            }
            // Project serialization excludes BA and E03 head changes before the authority phase.
            foreach (['root_refs' => ['ba_clarifications', 50], 'source_head_refs' => ['ba_source_heads', 200]] as $field => [$table, $limit]) {
                $ids = array_values(array_unique(array_merge(...array_map(fn ($request) => $request[$field] ?? [], $requests))));
                if (count($ids) > $limit) {
                    throw new AccessDeniedHttpException;
                }
                sort($ids, SORT_STRING);
                DB::table($table)->where('project_id', $project)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
            }
            $current = ['key' => 'current', 'actor_id' => $actor->id, 'purposes' => $purposes, 'required' => true];
            if ($coordinatorRefs) {
                $current['coordinator_refs'] = $coordinatorRefs;
            }
            if ($requestSubjectRefs) {
                $current['request_subject_refs'] = $requestSubjectRefs;
            }
            if ($department !== null) {
                $current['department_refs'] = $subjectRefs;
            }
            if ($observeOriginalSourceInvalidity) {
                foreach ($requests as &$request) {
                    if ($request['key'] === 'sources') {
                        $request['required'] = false;
                    }
                }
                unset($request);
            }
            $proofs = $this->authority->captureBatch($actor, $project, [$current, ...$requests], $department, $subjectRefs);
            DB::select("select set_config('app.ba_authority_closed','1',true),set_config('app.ba_proofs',?,true),set_config('app.ba_required_keys','[]',true)", [BaContract::canonical($proofs)]);
            if ($observeOriginalSourceInvalidity && ! ($proofs['sources']['valid'] ?? false)) {
                // Current grant/membership checks already succeeded. Denied source access
                // permits only saved-original observations; the work callback never runs.
                foreach ($proofs as $key => $proof) {
                    if (str_starts_with($key, 'receipt:') && ($proof['reason_code'] ?? null) === 'source_revoked') {
                        $id = substr($key, 8);
                        $receipt = DB::table('ba_receipts')->where('project_id', $project)->where('id', $id)->first();
                        if ($receipt && ($proof['original_source_proof_hash'] ?? null) === hash('sha256', BaContract::canonical(json_decode($receipt->authority_proof, true, 32, JSON_THROW_ON_ERROR)))) {
                            app(BaTerminal::class)->observeLocked($project, 'receipt', $id, hash('sha256', $receipt->authority_proof), 'source_revoked');
                        }
                    }
                }
                $result = ['denied' => true];
            } else {
                $result = $work($proofs);
            }
            foreach (json_decode(DB::scalar("select current_setting('app.ba_required_keys')"), true, 8, JSON_THROW_ON_ERROR) as $key) {
                $proofs[$key]['required'] = true;
            }
            $this->authority->assertEffective($proofs, DB::scalar('select clock_timestamp()'));
            // Do not issue SQL on an aborted transaction: rollback restores local settings on failure.
            DB::select("select set_config('app.ba_authority_closed','',true),set_config('app.ba_proofs','',true),set_config('app.ba_required_keys','',true)");

            return $result;
        });
    }
}
