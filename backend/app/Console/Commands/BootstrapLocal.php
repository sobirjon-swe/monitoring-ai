<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditWriter;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BootstrapLocal extends Command
{
    protected $signature = 'app:bootstrap-local {--tenant= : Explicit tenant UUID} {--name= : Tenant and user display name} {--subject= : Unique local login subject} {--employee= : Existing employee UUID, if explicitly linked}';

    protected $description = 'Explicitly create a local development identity and bounded administrative grants';

    public function handle(TenantContext $context, AuditWriter $audit): int
    {
        $config = config('database.connections.pgsql');
        $tenant = $this->option('tenant');
        $name = $this->option('name');
        $subject = $this->option('subject');
        $employee = $this->option('employee') ?: null;
        if (! app()->environment('local') || config('database.default') !== 'pgsql' || ($config['driver'] ?? null) !== 'pgsql'
            || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)
            || ! in_array($config['url'] ?? null, [null, ''], true) || ! empty($config['read']) || ! empty($config['write'])
            || ($config['database'] ?? null) !== 'monitoring_ai_dev' || ($config['username'] ?? null) !== 'monitoring_ai_app'
            || ! is_string($tenant) || ! Str::isUuid($tenant) || ! is_string($name) || trim($name) === '' || strlen($name) > 200
            || ! is_string($subject) || ! preg_match('/\A[a-zA-Z0-9._-]{1,200}\z/D', $subject)
            || ($employee !== null && ! Str::isUuid($employee))) {
            $this->error('Bootstrap refused: explicit local development configuration and identity are required.');

            return self::FAILURE;
        }
        try {
            $identity = DB::selectOne("select current_database() as database, current_user as runtime, shobj_description(oid, 'pg_database') as marker from pg_database where datname=current_database()");
            if ($identity->database !== 'monitoring_ai_dev' || $identity->runtime !== 'monitoring_ai_app' || ! str_starts_with($identity->marker ?? '', 'monitoring-ai-local-v1:')) {
                throw new \RuntimeException;
            }
            $token = $context->run(strtolower($tenant), function () use ($tenant, $name, $subject, $employee, $audit): string {
                $tenant = strtolower($tenant);
                if (! DB::table('tenants')->where('id', $tenant)->exists()) {
                    DB::table('tenants')->insert(['id' => $tenant, 'name' => $name]);
                }
                if (DB::table('auth_subjects')->where('issuer', 'local-development')->where('subject', $subject)->exists()) {
                    throw new \RuntimeException;
                }
                $user = (string) Str::uuid();
                $subjectId = (string) Str::uuid();
                $tokenId = (string) Str::uuid();
                $secret = bin2hex(random_bytes(32));
                DB::table('users')->insert(['id' => $user, 'tenant_id' => $tenant, 'employee_id' => $employee, 'display_name' => $name]);
                DB::table('auth_subjects')->insert(['id' => $subjectId, 'tenant_id' => $tenant, 'user_id' => $user, 'issuer' => 'local-development', 'subject' => $subject]);
                DB::table('auth_tokens')->insert(['id' => $tokenId, 'tenant_id' => $tenant, 'auth_subject_id' => $subjectId, 'secret_hash' => hash('sha256', $secret), 'expires_at' => now()->addHours(8)]);
                $policy = DB::table('policy_versions')->where('status', 'active')->orderByDesc('version')->value('id');
                if ($policy === null) {
                    $policy = (string) Str::uuid();
                    $version = (int) DB::table('policy_versions')->max('version') + 1;
                    DB::table('policy_versions')->insert(['id' => $policy, 'tenant_id' => $tenant, 'version' => $version]);
                    $audit->record($user, ['tenant_id' => $tenant], 'access.setup', ['type' => 'policy_versions', 'id' => $policy], 1, 'local_setup');
                }
                foreach (['organization.read', 'organization.manage', 'project.create', 'project.read', 'project.manage', 'roles.read', 'roles.manage', 'membership.manage', 'identity.read', 'identity.manage'] as $purpose) {
                    $grant = (string) Str::uuid();
                    DB::table('access_grants')->insert(['id' => $grant, 'tenant_id' => $tenant, 'user_id' => $user, 'policy_version_id' => $policy, 'purpose' => $purpose, 'scope_type' => 'tenant', 'valid_from' => now(), 'valid_to' => now()->addHours(8)]);
                    $audit->record($user, ['tenant_id' => $tenant], 'access.setup', ['type' => 'access_grants', 'id' => $grant], 1, 'local_setup');
                }
                foreach (['users' => $user, 'auth_subjects' => $subjectId, 'auth_tokens' => $tokenId] as $table => $id) {
                    $audit->record($user, ['tenant_id' => $tenant], 'identity.setup', ['type' => $table, 'id' => $id], 1, 'local_setup');
                }

                return $tenant.'.'.$tokenId.'.'.$secret;
            });
            $this->info('Local identity created. Token expires in eight hours; store securely. It is shown once:');
            $this->line($token);

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Local setup failed; no identity or grant was partially created.');

            return self::FAILURE;
        }
    }
}
