<?php

namespace App\Domain\DeveloperAnalysis;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DeveloperCoordination
{
    public function __construct(private readonly TenantContext $context, private readonly DeveloperAuthority $authority) {}

    public function run(TrustedActor $actor, string $project, array $purposes, bool $requireOpen, Closure $work, array $authorityRequests = []): mixed
    {
        if (DB::scalar("select nullif(current_setting('app.developer_authority_closed',true),'')") !== null) {
            throw new LogicException('Nested developer coordination is prohibited.');
        }

        return $this->context->run($actor->tenantId, function () use ($actor, $project, $purposes, $requireOpen, $work, $authorityRequests) {
            if (! DB::table('projects')->where('id', $project)->lockForUpdate()->first()) {
                throw new NotFoundHttpException;
            }
            $state = DB::table('lifecycle_project_state')->where('project_id', $project)->lockForUpdate()->first();
            if ($requireOpen && $state?->is_closed) {
                throw new ConflictHttpException;
            }
            $proofs = $this->authority->captureBatch($actor, $project, [['actor_id' => $actor->id, 'purposes' => $purposes], ...$authorityRequests]);
            DB::select("select set_config('app.developer_authority_closed','1',true)");
            $result = $work($proofs);
            $this->authority->assertEffective($proofs, DB::scalar('select clock_timestamp()'));
            DB::select("select set_config('app.developer_authority_closed','',true)");

            return $result;
        });
    }
}
