<?php

namespace App\Domain\Browser\BaDemo;

use App\Support\LocalManagementDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;

/** A bounded candidate only: the caller must serialize authority through publication. */
final class BaDemoSnapshot
{
    // Frozen against the TEST migration schema; additions require independent review.
    private const TENANT_TABLES = <<<'TABLES'
acceptance_applicability access_grants audit_events auth_subjects auth_tokens ba_answer_versions ba_clarifications ba_demo_receipts ba_events ba_receipts ba_request_bindings ba_request_links ba_request_versions ba_reviews ba_scope_obligations ba_source_heads ba_source_party_versions ba_terminal_applicability baseline_items baseline_progress_snapshots baselines browser_attempt_cleanup_cursors browser_ba_attempts browser_session_fences criteria criterion_versions department_memberships departments developer_findings developer_invalidations developer_outbox developer_results developer_reviews developer_runs developer_snapshots developer_source_events developer_source_heads dod_policy_versions employees evidence_link_proposals evidence_review_heads evidence_reviews human_acceptance_decisions identities identity_mapping_histories identity_mappings ingestion_connectors ingestion_outbox ingestion_projections ingestion_receipts lifecycle_changes lifecycle_outbox lifecycle_project_state lifecycle_tasks management_action_versions management_actions management_assignments management_events management_project_state management_receipts management_reference_sets management_references management_request_bindings management_reviews management_source_heads management_support_versions management_terminal_applicability policy_versions project_memberships projects qa_applicability qa_build_versions qa_changes qa_defect_events qa_defects qa_definition_identities qa_definition_versions qa_environment_versions qa_execution_attempts qa_execution_series qa_execution_versions qa_handoff_events qa_handoffs qa_invalidations qa_observation_heads qa_observations qa_outbox qa_plan_entries qa_plan_entry_definitions qa_plan_entry_snapshots qa_plan_versions qa_project_state qa_receipts qa_request_keys qa_reviews qa_selection_events qa_source_heads qa_test_identities reporting_relationships requirement_drafts requirement_version_criteria requirement_versions requirements role_assignments source_instances task_criterion_links task_dependencies task_dependency_events task_versions users
TABLES;

    public function assertInventory(array $rows): void
    {
        $names = [];
        foreach ($rows as $row) {
            if ($row->kind !== 'r' || $row->readable !== true) {
                throw new LogicException('Synthetic snapshot inventory refused.');
            }
            $names[] = $row->name;
        }
        if ($names !== explode(' ', self::TENANT_TABLES)) {
            throw new LogicException('Synthetic snapshot inventory refused.');
        }
    }

    public function assertTenantPolicy(array $rows, bool $tenantTable): void
    {
        $column = $tenantTable ? 'id' : 'tenant_id';
        $expression = "($column = (NULLIF(current_setting('app.tenant_id'::text, true), ''::text))::uuid)";
        if (count($rows) !== 1 || ! $rows[0]->enabled || ! $rows[0]->forced
            || $rows[0]->name !== 'tenant_isolation' || ! $rows[0]->permissive
            || $rows[0]->command !== '*' || ! $rows[0]->public_role
            || $rows[0]->using_expr !== $expression || $rows[0]->check_expr !== $expression) {
            throw new LogicException('Synthetic snapshot policy refused.');
        }
    }

    public function candidate(BaDemoManifest $manifest, string $expectedSecretHash): string
    {
        $m = $manifest->data;
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $expectedSecretHash) || ! app()->environment('testing') || DB::transactionLevel() < 1
            || DB::scalar("select nullif(current_setting('app.tenant_id', true), '')") !== $m['tenant_id']) {
            throw new LogicException('Synthetic snapshot context refused.');
        }
        app(LocalManagementDatabase::class)->assertRuntime();
        $tables = DB::select("select c.relname as name, c.relkind as kind, has_table_privilege(c.oid, 'SELECT') as readable from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_attribute a on a.attrelid=c.oid where n.nspname='public' and c.relkind in ('r','p','v','m','f') and a.attname='tenant_id' and a.attnum>0 and not a.attisdropped order by c.relname");
        $this->assertInventory($tables);
        $policies = DB::select("select c.relname as relation, c.relrowsecurity as enabled, c.relforcerowsecurity as forced, p.polname as name, p.polpermissive as permissive, p.polcmd as command, p.polroles = array[0::oid] as public_role, pg_get_expr(p.polqual,p.polrelid) as using_expr, pg_get_expr(p.polwithcheck,p.polrelid) as check_expr from pg_catalog.pg_class c join pg_catalog.pg_namespace n on n.oid=c.relnamespace left join pg_catalog.pg_policy p on p.polrelid=c.oid where n.nspname='public' and (c.relname='tenants' or c.relname = any(?::text[]))", ['{'.implode(',', explode(' ', self::TENANT_TABLES)).'}']);
        $byRelation = [];
        foreach ($policies as $policyRow) {
            $byRelation[$policyRow->relation][] = $policyRow;
        }
        foreach (array_merge(['tenants'], explode(' ', self::TENANT_TABLES)) as $relation) {
            $this->assertTenantPolicy($byRelation[$relation] ?? [], $relation === 'tenants');
        }
        // This coordinates only callers that honor the fixture lock; it is not a publication lock.
        DB::select('select pg_advisory_xact_lock(hashtextextended(?,0))', ['ba-demo:'.$m['tenant_id']]);
        $identity = DB::selectOne(<<<'SQL'
select exists (
 select 1 from public.tenants t
 join public.users u on u.tenant_id=t.id
 join public.auth_subjects s on s.tenant_id=u.tenant_id and s.user_id=u.id
 join public.auth_tokens tok on tok.tenant_id=s.tenant_id and tok.auth_subject_id=s.id
 where t.id=?::uuid and t.status='active' and u.id=?::uuid and u.employee_id=?::uuid and u.status='active'
 and s.id=?::uuid and s.issuer='local-development' and s.subject=? and s.revoked_at is null
 and tok.id=?::uuid and tok.secret_hash=? and tok.revoked_at is null
 and tok.expires_at > clock_timestamp()
) as valid
SQL, [$m['tenant_id'], $m['writer']['user_id'], $m['writer']['employee_id'], $m['writer']['subject_id'], 'ba-demo-v1.'.$manifest->hash, $m['writer']['token_id'], $expectedSecretHash]);
        $policy = DB::table('public.policy_versions')->where('tenant_id', $m['tenant_id'])->where('id', $m['policy_id'])->where('status', 'active')->exists();
        $counts = ['users' => 2, 'employees' => 2, 'auth_subjects' => 1, 'auth_tokens' => 1, 'source_instances' => 1, 'ingestion_connectors' => 1];
        foreach ($counts as $table => $expectedCount) {
            if (DB::table('public.'.$table)->where('tenant_id', $m['tenant_id'])->count() !== $expectedCount) {
                throw new LogicException('Synthetic snapshot authority refused.');
            }
        }
        if (! $identity->valid || ! $policy) {
            throw new LogicException('Synthetic snapshot authority refused.');
        }
        foreach (['writer', 'producer'] as $key) {
            $person = $m[$key];
            $user = DB::table('public.users')->where('tenant_id', $m['tenant_id'])->where('id', $person['user_id'])->where('employee_id', $person['employee_id'])->where('status', 'active')->exists();
            $roles = DB::table('public.role_assignments')->where('tenant_id', $m['tenant_id'])->where('employee_id', $person['employee_id'])->whereRaw('valid_from <= clock_timestamp() and (valid_to is null or valid_to > clock_timestamp())')->get(['project_id', 'role'])->toArray();
            $memberships = DB::table('public.project_memberships')->where('tenant_id', $m['tenant_id'])->where('employee_id', $person['employee_id'])->whereRaw('valid_from <= clock_timestamp() and (valid_to is null or valid_to > clock_timestamp())')->get(['project_id'])->toArray();
            $grants = DB::table('public.access_grants')->where('tenant_id', $m['tenant_id'])->where('user_id', $person['user_id'])->whereNull('revoked_at')->whereRaw('valid_from <= clock_timestamp() and (valid_to is null or valid_to > clock_timestamp())')->orderBy('purpose')->get(['purpose', 'policy_version_id', 'scope_type', 'project_id', 'department_id'])->toArray();
            $expected = $person['grants'];
            sort($expected);
            $expectedGrants = array_map(fn ($purpose) => (object) ['purpose' => $purpose, 'policy_version_id' => $m['policy_id'], 'scope_type' => 'project', 'project_id' => $m['project_id'], 'department_id' => null], $expected);
            if (! $user || $roles != [(object) ['project_id' => $m['project_id'], 'role' => $person['role']]]
                || $memberships != [(object) ['project_id' => $m['project_id']]] || $grants != $expectedGrants) {
                throw new LogicException('Synthetic snapshot authority refused.');
            }
        }
        $source = DB::table('public.source_instances as s')->join('public.ingestion_connectors as c', function ($join) {
            $join->on('c.tenant_id', '=', 's.tenant_id')->on('c.source_instance_id', '=', 's.id');
        })->where('s.tenant_id', $m['tenant_id'])->where('s.id', $m['source_id'])->where('s.kind', 'manual')->where('c.project_id', $m['project_id'])->where('c.adapter', 'synthetic-v1')->where('c.status', 'active')->exists();
        if (! $source) {
            throw new LogicException('Synthetic snapshot proof refused.');
        }
        $proof = DB::selectOne(<<<'SQL'
select exists (
 select 1 from public.ba_clarifications c
 join public.ba_request_versions r on r.tenant_id=c.tenant_id and r.project_id=c.project_id and r.id=c.current_request_id and r.clarification_id=c.id
 join public.ba_events e on e.tenant_id=c.tenant_id and e.project_id=c.project_id and e.id=c.current_event_id and e.request_version_id=r.id
 join public.ba_source_party_versions p on p.tenant_id=r.tenant_id and p.project_id=r.project_id and p.id=r.decision_owner_party_version_id
 join public.ba_request_links l on l.tenant_id=r.tenant_id and l.project_id=r.project_id and l.request_version_id=r.id
 join public.ba_receipts rr on rr.tenant_id=r.tenant_id and rr.project_id=r.project_id and rr.id=r.receipt_id
 join public.ba_receipts pr on pr.tenant_id=p.tenant_id and pr.project_id=p.project_id and pr.id=p.receipt_id
 where c.tenant_id=?::uuid and c.project_id=?::uuid and c.source_instance_id=?::uuid and c.object_id='synthetic-f02-request'
 and c.state='open' and c.revision=1 and r.revision=1 and r.question='SYNTHETIC fictional customer clarification'
 and r.requester_id=?::uuid and r.coordinator_id=?::uuid and r.decision_party='customer'
 and r.actor_id=?::uuid and e.kind='request.open' and e.actor_id=?::uuid and l.relation='blocks'
 and p.source_instance_id=?::uuid and p.object_id='synthetic-f02-party' and p.party_reference='synthetic-f02-customer'
 and p.party_kind='customer' and p.synthetic and rr.operation='request.open' and pr.operation='party.register'
) as valid
SQL, [$m['tenant_id'], $m['project_id'], $m['source_id'], $m['producer']['employee_id'], $m['writer']['employee_id'], $m['producer']['user_id'], $m['producer']['user_id'], $m['source_id']]);
        if (! $proof->valid) {
            throw new LogicException('Synthetic snapshot proof refused.');
        }
        $snapshot = ['version' => 'ba-demo-snapshot-v1', 'manifest_hash' => $manifest->hash];
        $tenant = DB::select('select to_jsonb(t)::text as value from public.tenants t where id=?::uuid', [$m['tenant_id']]);
        if (count($tenant) !== 1) {
            throw new LogicException('Synthetic snapshot tenant refused.');
        }
        $snapshot['tenant'] = $tenant[0]->value;
        $bytes = strlen($tenant[0]->value);
        foreach ($tables as $table) {
            $name = $table->name;
            if (! preg_match('/\A[a-z][a-z0-9_]*\z/D', $name)) {
                throw new LogicException('Synthetic snapshot inventory refused.');
            }
            if ($name === 'ba_demo_receipts' || str_starts_with($name, 'browser_')) {
                continue;
            }
            $rows = DB::select('select to_jsonb(t)::text as value from public."'.$name.'" t where tenant_id=?::uuid order by to_jsonb(t)::text limit 1001', [$m['tenant_id']]);
            if (count($rows) > 1000) {
                throw new LogicException('Synthetic snapshot bounds refused.');
            }
            $values = array_column($rows, 'value');
            $bytes += array_sum(array_map('strlen', $values));
            if ($bytes > 4194304) {
                throw new LogicException('Synthetic snapshot bounds refused.');
            }
            $snapshot[$name] = ['count' => count($values), 'sha256' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR))];
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
