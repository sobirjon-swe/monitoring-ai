<?php

namespace Tests\Support;

use App\Domain\Access\TrustedActor;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

abstract class BrowserTestCase extends AccessTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'array', 'session.secure' => false]);
        app('url')->forceRootUrl('http://127.0.0.1:18080');
        $this->withServerVariables(['HTTP_HOST' => '127.0.0.1:18080', 'SERVER_PORT' => 18080]);
    }

    protected function browserInputs(array $purposes = []): array
    {
        $this->fixture();
        $project = $this->project();
        foreach ($purposes as $purpose) {
            $this->grant($purpose, $project);
        }
        $subject = app(TenantContext::class)->run($this->tenant, fn () => DB::table('auth_subjects')->value('id'));

        return ['actor' => new TrustedActor($this->user, $this->tenant), 'tenant' => $this->tenant,
            'project' => $project, 'token' => $this->token, 'token_id' => explode('.', $this->token)[1],
            'subject_id' => $subject, 'user_id' => $this->user, 'employee_id' => $this->employee, 'policy_id' => $this->policy];
    }
}
