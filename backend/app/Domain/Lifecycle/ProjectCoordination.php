<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\AccessGateway;
use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProjectCoordination
{
    public function __construct(private readonly AccessGateway $access, private readonly TenantContext $context) {}

    public function run(TrustedActor $actor, string $projectId, string $purpose, int $expectedGeneration, Closure $work): array
    {
        return $this->mutate($actor, $projectId, $purpose, $expectedGeneration, $work, false);
    }

    /** Only baseline approval may perform the closed-to-new-baseline transition. */
    public function reopenBaseline(TrustedActor $actor, string $projectId, int $expectedGeneration, Closure $work): array
    {
        return $this->mutate($actor, $projectId, 'baseline.approve', $expectedGeneration, $work, true);
    }

    private function mutate(TrustedActor $actor, string $projectId, string $purpose, int $expectedGeneration, Closure $work, bool $reopen): array
    {
        if (DB::scalar("select nullif(current_setting('app.lifecycle_mutation',true),'')") !== null) {
            throw new LogicException('Nested lifecycle mutation is prohibited.');
        }

        return $this->read($actor, $projectId, $purpose, function () use ($actor, $projectId, $purpose, $expectedGeneration, $work, $reopen): array {
            DB::table('lifecycle_project_state')->insertOrIgnore(['tenant_id' => $actor->tenantId, 'project_id' => $projectId]);
            $state = DB::table('lifecycle_project_state')->where('project_id', $projectId)->lockForUpdate()->first();
            if ($state->generation !== $expectedGeneration || $state->is_closed !== $reopen) {
                throw new ConflictHttpException;
            }
            $binding = json_encode([$actor->tenantId, $projectId, $actor->id, $purpose, $expectedGeneration], JSON_THROW_ON_ERROR);
            DB::select("select set_config('app.lifecycle_mutation',?,true)", [$binding]);
            $result = $work();
            if ($reopen) {
                $next = DB::table('lifecycle_project_state')->where('project_id', $projectId)->first();
                if ($next->is_closed || $next->current_baseline_id === $state->current_baseline_id || ! $next->current_baseline_id) {
                    throw new ConflictHttpException;
                }
            }
            $generation = $expectedGeneration + 1;
            DB::table('lifecycle_project_state')->where('project_id', $projectId)->update(['generation' => $generation]);
            DB::select("select set_config('app.lifecycle_mutation','',true)");

            return ['result' => $result, 'generation' => $generation];
        });
    }

    public function read(TrustedActor $actor, string $projectId, string $purpose, Closure $read): mixed
    {
        $scope = ['tenant_id' => $actor->tenantId, 'project_id' => $projectId];
        $this->access->authorize($actor, $purpose, $scope);

        return $this->context->run($actor->tenantId, function () use ($actor, $projectId, $purpose, $scope, $read): mixed {
            // The parent row also serializes the first mutation before a state row exists. Reads never create state.
            if (! DB::table('projects')->where('id', $projectId)->lockForUpdate()->first()) {
                throw new NotFoundHttpException;
            }
            DB::table('lifecycle_project_state')->where('project_id', $projectId)->lockForUpdate()->first();
            $result = $read();
            $this->access->authorize($actor, $purpose, $scope);

            return $result;
        });
    }
}
