<?php

namespace App\Domain\Qa;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class QaCoordination
{
    public function __construct(private readonly TenantContext $context, private readonly QaAuthority $authority) {}

    public function run(TrustedActor $actor, string $project, array $purposes, bool $requireOpen, Closure $work, array $requests = [], ?string $department = null, ?array $departmentRefs = null): mixed
    {
        if (DB::scalar("select nullif(current_setting('app.qa_authority_closed',true),'')") !== null) {
            throw new LogicException('Nested QA coordination is prohibited.');
        }

        return $this->context->run($actor->tenantId, function () use ($actor, $project, $purposes, $requireOpen, $work, $requests, $department, $departmentRefs) {
            if (! DB::table('projects')->where('id', $project)->lockForUpdate()->first()) {
                throw new NotFoundHttpException;
            }
            $e03 = DB::table('lifecycle_project_state')->where('project_id', $project)->lockForUpdate()->first();
            if ($requireOpen && $e03?->is_closed) {
                throw new ConflictHttpException;
            }
            DB::table('qa_project_state')->insertOrIgnore(['tenant_id' => $actor->tenantId, 'project_id' => $project]);
            DB::table('qa_project_state')->where('project_id', $project)->lockForUpdate()->first();
            $current = ['key' => 'current', 'actor_id' => $actor->id, 'purposes' => $purposes, 'required' => true];
            if ($departmentRefs !== null) {
                $current['department_refs'] = $departmentRefs;
            }
            $proofs = $this->authority->captureBatch($actor, $project, [$current, ...$requests], $department);
            DB::select("select set_config('app.qa_authority_closed','1',true)");
            $result = $work($proofs);
            $this->authority->assertEffective($proofs, DB::scalar('select clock_timestamp()'));
            DB::select("select set_config('app.qa_authority_closed','',true),set_config('app.qa_responsibility','',true)");

            return $result;
        });
    }
}
