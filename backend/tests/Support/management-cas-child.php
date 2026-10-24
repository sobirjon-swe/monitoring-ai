<?php

use App\Domain\Access\TrustedActor;
use App\Domain\Management\ManagementActionService;
use App\Domain\Management\ManagementContract;
use App\Domain\Management\ManagementCoordination;
use App\Domain\Management\ManagementReceiptWriter;
use App\Domain\Management\ManagementSupportService;
use App\Support\LocalManagementDatabase;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
config(['logging.default' => 'null']);
if (! $app->environment('testing')) {
    exit(90);
}
$app->make(LocalManagementDatabase::class)->assertRuntime();
[$script, $tenant, $actor, $project, $action, $encoded, $key] = $argv;
$body = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
echo 'ready pid='.DB::scalar('select pg_backend_pid()')."\n";
flush();
if (trim(fgets(STDIN)) !== 'go') {
    exit(91);
}
if (in_array($argv[7] ?? '', ['series-binding', 'series-deferred-misuse'], true)) {
    try {
        $body = app(ManagementContract::class)->validate('envelope', $body);
        $trusted = new TrustedActor($actor, $tenant);
        $named = app(TenantContext::class)->run($tenant, fn () => (array) DB::table('management_assignments')->where('project_id', $project)->where('id', $body['body']['assignment_id'])->first());
        $purposes = ['management.support.record', 'management.source.read', 'requirements.read', 'progress.read'];
        $graph = ['coverage' => 'complete', 'current_requests' => [['key' => 'current:operation', 'actor_id' => $actor, 'purposes' => $purposes, 'required' => true, 'source_ids' => [$body['source_instance_id']], 'owner_refs' => [$named['owner_id'], $named['helper_id']]]]];
        if ($argv[7] === 'series-deferred-misuse') {
            DB::beginTransaction();
        }
        app(ManagementCoordination::class)->run($trusted, $project, $purposes, true, $graph, function ($proofs) use ($trusted, $project, $action, $body, $key, $argv) {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            $writer = app(ManagementReceiptWriter::class);
            $root = $writer->root($project, $action);
            $input = $body['body'];
            $assignment = DB::table('management_assignments')->where('project_id', $project)->where('id', $input['assignment_id'])->first();
            $version = DB::table('management_action_versions')->where('project_id', $project)->where('id', $input['action_version_id'])->first();
            if ($root['revision'] !== $input['expected_revision'] || $root['state'] !== 'assigned' || $assignment->action_id !== $action || $assignment->action_version_id !== $version->id) {
                throw new LogicException('Direct fixture boundary refused.');
            }
            $proof = $proofs['current:operation']['proof'];
            $scope = ['tenant_id' => $trusted->tenantId, 'project_id' => $project];
            $hash = app(ManagementContract::class)->contentHash('support.record', $body);
            $output = ['receipt_id' => (string) Str::uuid(), 'action_id' => $action, 'event_id' => (string) Str::uuid(), 'support_id' => (string) Str::uuid()];
            $auth = ['actor_id' => $trusted->id, 'authority_proof' => ManagementContract::canonical($proof)];
            $series = $scope + ['source_instance_id' => $body['source_instance_id'], 'family' => 'support', 'object_id' => $body['object_id']];
            $head = DB::table('management_source_heads')->where($series)->first();
            $current = ! $head || $body['source_revision'] > $head->source_revision;
            DB::table('management_receipts')->insert(['id' => $output['receipt_id'], 'variant' => 'source', 'operation' => 'support.record', 'source_revision' => $body['source_revision'], 'input_hash' => $hash, 'envelope' => ManagementContract::canonical($body), 'occurred_at' => $body['occurred_at'], 'source_updated_at' => $body['source_updated_at']] + $series + $auth + array_diff_key($output, ['receipt_id' => true]));
            $fact = array_intersect_key($input, array_flip(['action_id', 'action_version_id', 'action_hash', 'assignment_id', 'assignment_hash', 'text', 'outcome', 'predecessor_support_id']));
            DB::table('management_support_versions')->insert(['id' => $output['support_id'], 'operation' => 'support.record', 'helper_id' => $assignment->helper_id, 'recorder_id' => $proof['employee_id'], 'support_hash' => $hash, 'references_hash' => $version->references_hash, 'source_instance_id' => $body['source_instance_id'], 'object_id' => $body['object_id'], 'source_revision' => $body['source_revision'], 'occurred_at' => $body['occurred_at'], 'source_updated_at' => $body['source_updated_at'], 'receipt_id' => $output['receipt_id']] + $scope + $auth + $fact);
            $set = DB::table('management_reference_sets')->where('project_id', $project)->where('action_version_id', $version->id)->first();
            $references = DB::table('management_references')->where('project_id', $project)->where('reference_set_id', $set->id)->orderBy('ordinal')->limit(21)->get();
            if ($references->isEmpty() || $references->count() > 20) {
                throw new LogicException('Direct fixture references refused.');
            }
            $supportSet = (string) Str::uuid();
            DB::table('management_reference_sets')->insert(['id' => $supportSet, 'action_id' => $action, 'support_id' => $output['support_id'], 'references_hash' => $version->references_hash] + $scope);
            foreach ($references as $ref) {
                DB::table('management_references')->insert(array_replace((array) $ref, ['id' => (string) Str::uuid(), 'reference_set_id' => $supportSet]));
            }
            if ($current) {
                $values = ['source_revision' => $body['source_revision'], 'revision' => ($head?->revision ?? 0) + 1, 'receipt_id' => $output['receipt_id'], 'complete' => $body['source_revision'] === ($head?->source_revision ?? 0) + 1 && ($head?->complete ?? true)];
                if ($head) {
                    DB::table('management_source_heads')->where('id', $head->id)->update($values);
                } else {
                    DB::table('management_source_heads')->insert(['id' => (string) Str::uuid()] + $series + $values);
                }
            }
            $ticket = $scope + ['actor_id' => $trusted->id, 'operation' => 'support.record', 'key' => $key, 'input_hash' => $hash, 'receipt_id' => $output['receipt_id'], 'output' => $output, 'replayed' => false];
            $projection = $current ? ['state' => 'awaiting_review', 'support_status' => 'recorded', 'review_status' => 'unverified', 'current_outcome' => null, 'current_support_id' => $output['support_id'], 'current_review_id' => null] : [];
            $writer->event($ticket, $root, $projection, $proof, ['subject_support_id' => $output['support_id']]);
            $writer->bindOutputLocked($ticket, $output);
            if ($argv[7] === 'series-binding') {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            }
        });
        if ($argv[7] === 'series-deferred-misuse') {
            DB::statement('SET CONSTRAINTS management_support_series_binding IMMEDIATE');
            DB::commit();
        }
        echo "status=201 reason=committed\n";
    } catch (QueryException|PDOException $e) {
        $code = $e->errorInfo[0] ?? '';
        $detail = $e->errorInfo[2] ?? '';
        if ($code === '23503' && str_contains($detail, 'Management support series binding')) {
            echo "status=409 reason=series_binding sqlstate=23503\n";
        } elseif ($code === '42501') {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            echo "status=403 reason=coordination_required sqlstate=42501\n";
        } else {
            echo "status=500 reason=unexpected_database_failure\n";
            exit(92);
        }
    } catch (Throwable $e) {
        echo 'status=500 reason=unexpected_fixture_failure class='.get_class($e)."\n";
        exit(93);
    }
    exit(0);
}
try {
    $trusted = new TrustedActor($actor, $tenant);
    $result = isset($body['schema_version']) ? $app->make(ManagementSupportService::class)->record($trusted, $project, $action, $body, $key, 0) : $app->make(ManagementActionService::class)->event($trusted, $project, $action, $body, $key);
    echo 'status='.($result['replayed'] ? '200' : '201')."\n";
} catch (ConflictHttpException) {
    echo "status=409\n";
}
