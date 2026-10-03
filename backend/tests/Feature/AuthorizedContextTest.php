<?php

namespace Tests\Feature;

use App\Domain\Analysis\AuthorizedContextClient;
use App\Domain\Analysis\AuthorizedSnapshot;
use App\Domain\Analysis\SyntheticArtifactRegistry;
use App\Domain\Analysis\TrustedAnalysisScope;
use App\Domain\Identity\LocalTokenAuthenticator;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\Support\AccessTestCase;

class AuthorizedContextTest extends AccessTestCase
{
    private string $projectId;

    private string $artifact;

    private string $evidence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture();
        $this->projectId = $this->project();
        $this->grant('analysis.context', $this->projectId);
        $this->artifact = (string) Str::uuid();
        $this->evidence = (string) Str::uuid();
    }

    public function test_tenant_substitution_is_rejected(): void
    {
        $this->registry(['tenant_id' => (string) Str::uuid()]);
        $this->expectException(AccessDeniedHttpException::class);
        $this->client()->getSnapshot($this->scope(), $this->artifact);
    }

    public function test_unapproved_artifact_is_rejected(): void
    {
        $this->registry(['approved' => false]);
        $this->expectException(AccessDeniedHttpException::class);
        $this->client()->getSnapshot($this->scope(), $this->artifact);
    }

    public function test_unknown_schema_version_is_rejected(): void
    {
        $this->registry(['schema_version' => 'analysis-context.v2']);
        $this->expectException(InvalidArgumentException::class);
        $this->client()->getSnapshot($this->scope(), $this->artifact);
    }

    public function test_output_contract_rejects_invalid_evidence(): void
    {
        $this->registry();
        $client = $this->client();
        $snapshot = $client->getSnapshot($this->scope(), $this->artifact);
        $output = $this->outputPayload($snapshot);
        $output['findings'][0]['evidence_ids'] = [(string) Str::uuid()];
        $this->expectException(AccessDeniedHttpException::class);
        $client->validateOutput($this->scope(), $snapshot, $output);
    }

    public function test_revoked_access_blocks_context_retrieval(): void
    {
        $this->registry();
        $client = $this->client();
        $scope = $this->scope();
        $client->getSnapshot($scope, $this->artifact);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('access_grants')->where('purpose', 'analysis.context')->update(['revoked_at' => now()]));
        $this->expectException(AccessDeniedHttpException::class);
        $client->getSnapshot($scope, $this->artifact);
    }

    public function test_allowed_snapshot_hash_is_stable(): void
    {
        $this->registry();
        $snapshot = $this->client()->getSnapshot($this->scope(), $this->artifact);
        $this->registry([], true);
        $second = $this->client()->getSnapshot($this->scope(), $this->artifact);
        $this->assertSame($snapshot->hash, $second->hash);
        $this->assertSame(64, strlen($snapshot->hash));
        $this->assertSame($this->tenant, $snapshot->payload['tenant_id']);
        $this->assertSame([$this->evidence], $snapshot->payload['evidence_ids']);
        $this->assertTrue($snapshot->payload['content']['synthetic']);
        $this->assertStringContainsString('Untrusted fixture instruction', $snapshot->payload['content']['text']);
        $this->client()->validateOutput($this->scope(), $snapshot, $this->outputPayload($snapshot));
    }

    public function test_fabricated_snapshot_is_rejected_before_output_delivery(): void
    {
        $this->registry();
        $client = $this->client();
        $snapshot = $client->getSnapshot($this->scope(), $this->artifact);
        $forged = $snapshot->payload;
        $forged['evidence_ids'] = [(string) Str::uuid()];
        $fake = new AuthorizedSnapshot($snapshot->artifactId, $snapshot->hash, $forged);
        $this->expectException(AccessDeniedHttpException::class);
        $client->validateOutput($this->scope(), $fake, $this->outputPayload($fake));
    }

    public function test_revoked_membership_blocks_output_validation(): void
    {
        $this->registry();
        $client = $this->client();
        $scope = $this->scope();
        $snapshot = $client->getSnapshot($scope, $this->artifact);
        app(TenantContext::class)->run($this->tenant, fn () => DB::table('project_memberships')->update(['valid_to' => now()]));
        $this->expectException(AccessDeniedHttpException::class);
        $client->validateOutput($scope, $snapshot, $this->outputPayload($snapshot));
    }

    public function test_project_substitution_is_rejected(): void
    {
        $this->registry(['project_id' => (string) Str::uuid()]);
        $this->expectException(AccessDeniedHttpException::class);
        $this->client()->getSnapshot($this->scope(), $this->artifact);
    }

    public function test_actual_output_schema_rejects_unknown_version_invalid_types_and_accepted_state(): void
    {
        $this->registry();
        $client = $this->client();
        $snapshot = $client->getSnapshot($this->scope(), $this->artifact);
        $valid = $this->outputPayload($snapshot);
        $invalid = [];
        $invalid[] = array_replace($valid, ['schema_version' => 'analysis-output.v2']);
        $invalid[] = array_replace($valid, ['extra_provider_url' => 'https://never-called.invalid']);
        $accepted = $valid;
        $accepted['findings'][0]['review_state'] = 'accepted';
        $invalid[] = $accepted;
        $confidence = $valid;
        $confidence['findings'][0]['confidence']['claim'] = 0.95;
        $invalid[] = $confidence;
        $id = $valid;
        $id['findings'][0]['finding_id'] = 'invalid-id';
        $invalid[] = $id;
        foreach ($invalid as $payload) {
            try {
                $client->validateOutput($this->scope(), $snapshot, $payload);
                $this->fail('Invalid output schema was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Invalid analysis contract.', $exception->getMessage());
            }
        }
    }

    public function test_output_scope_and_snapshot_hash_are_bound_to_authorized_input(): void
    {
        $this->registry();
        $client = $this->client();
        $snapshot = $client->getSnapshot($this->scope(), $this->artifact);
        foreach (['tenant_id' => (string) Str::uuid(), 'project_id' => (string) Str::uuid(), 'input_snapshot_hash' => str_repeat('0', 64)] as $key => $value) {
            $payload = $this->outputPayload($snapshot);
            $payload[$key] = $value;
            try {
                $client->validateOutput($this->scope(), $snapshot, $payload);
                $this->fail('Output binding substitution accepted.');
            } catch (AccessDeniedHttpException) {
                $this->assertTrue(true);
            }
        }
    }

    private function registry(array $overrides = [], bool $reverse = false): void
    {
        $fixture = require base_path('tests/Fixtures/analysis-artifact.php');
        $fixture += ['tenant_id' => $this->tenant, 'project_id' => $this->projectId, 'evidence_ids' => [$this->evidence]];
        $fixture = array_replace($fixture, $overrides);
        if ($reverse) {
            $fixture = array_reverse($fixture, true);
            $fixture['content'] = array_reverse($fixture['content'], true);
        }
        app()->instance(SyntheticArtifactRegistry::class, new SyntheticArtifactRegistry([$this->artifact => $fixture]));
    }

    private function scope(): TrustedAnalysisScope
    {
        $actor = app(LocalTokenAuthenticator::class)->authenticate($this->token);
        $this->assertNotNull($actor);

        return new TrustedAnalysisScope($actor, $this->projectId);
    }

    private function client(): AuthorizedContextClient
    {
        return app(AuthorizedContextClient::class);
    }

    private function outputPayload(AuthorizedSnapshot $snapshot): array
    {
        return [
            'schema_version' => 'analysis-output.v1', 'analysis_run_id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant, 'project_id' => $this->projectId, 'purpose' => 'analysis.context',
            'input_snapshot_hash' => $snapshot->hash,
            'findings' => [[
                'finding_id' => (string) Str::uuid(), 'category' => 'evidence_gap', 'explanation' => 'Synthetic contract exercise only.',
                'evidence_ids' => $snapshot->payload['evidence_ids'], 'coverage' => ['state' => 'partial', 'reasons' => ['Only synthetic evidence is available.']],
                'confidence' => ['source' => 'high', 'link' => 'high', 'claim' => 'unknown', 'reasons' => ['No model or quality evaluation has run.']],
                'review_state' => 'pending',
            ]],
        ];
    }
}
