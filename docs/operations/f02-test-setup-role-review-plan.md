# F02 TEST-only setup role — review plan

Status: **proposal only**. No role, credential, grant, policy or database object is created by this document. ReceiptStore remains disabled; this does not authorize publication or recovery.

## Existing boundary and decision

The existing `monitoring_ai_app` is granted CONNECT on both dedicated databases. Do not grant it to the new role or grant the new role to it: inheritance or SET ROLE would either expose DEV to setup or let runtime forge receipts. `monitoring_ai_migrator` can SET ROLE to the owner and is too powerful for fixture execution. PostgreSQL roles are cluster-wide; merely connecting to TEST when creating a role does not make the role TEST-specific.

Use a separate `monitoring_ai_ba_demo_setup` role: initially `NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS`, no memberships in either direction, no owned relations/schema/database, and no default privileges. A reviewed explicit provisioning step may enable LOGIN only after secure credential provisioning and successful privilege checks. Grant CONNECT only on `monitoring_ai_test`; verify effective CONNECT is false for `monitoring_ai_dev` and every other database, including via PUBLIC and memberships. Refuse rather than modifying grants on any other database. Do not put credentials into source, environment, argv, console output, logs, or SQL error output; a secure channel and owner-only precreated credential file must be independently reviewed before password provisioning.

## Guarded preflight and creation

1. Validate the protected installation record and exact marker `monitoring-ai-local-v1:<installation_id>` without displaying its secret fields. Require loopback `127.0.0.1:55432`, `current_database() = 'monitoring_ai_test'`, expected database owner, server version and matching marker. Refuse URL/split endpoints, missing marker, existing unmarked role, unexpected ownership or any existing role membership. Pin a single authenticated administrative session and repeat database/marker assertions immediately before any DDL; administrator role is required to CREATE ROLE, but must never be used for fixture writes.
2. In a single TEST-guarded transaction, after collision checks, create the role with the flags above and COMMENT it with a role-specific marker derived from the verified installation ID. Only after a separately reviewed full grant and RLS inventory, grant TEST CONNECT, `USAGE` on `public`, and individually enumerated table/sequence operations. Never grant schema CREATE, database CREATE/TEMP, TRUNCATE, ownership, role administration, or broad `ALL TABLES`/default grants. Existing owner default grants for `monitoring_ai_app` do not grant permissions to this role. A role-creation transaction must not print its SQL or credentials.
3. For **every** tenant-scoped relation available to setup, require an existing ENABLE + FORCE RLS tenant policy and add a role-targeted **restrictive** policy binding `tenant_id = 'f0200000-0000-4000-8000-000000000001'::uuid` for USING and WITH CHECK. For `tenants`, bind `id` to that same UUID. Relations without an appropriate tenant boundary, owner-only functions, mutable global tables and views that bypass RLS require individual review or no grant. Check privileges/ownership and all applicable policies, including functions, views and partition children; deny if any remain unaccounted. No policy may weaken the existing tenant policy.
4. After grants, assert role flags, memberships in both directions, owner-free relations and schemas, exact TEST-only CONNECT, receipt INSERT, denied runtime/PUBLIC receipt INSERT, forbidden CREATE/TRUNCATE, and the effective privileges for every enumerated relation. Test fixed-tenant insert/read and missing/other-tenant denial as the setup login on independent connections, including query builder and upserts. Roll back fixture data in TEST; no shared database resets.

The explicit table inventory is unresolved. Direct calls include tenants, projects, employees, users, auth_subjects, auth_tokens, memberships, role_assignments, access_grants, source_instances, ingestion_connectors, audit, lifecycle/requirement and BA tables; transitive service calls, triggers, sequences and snapshot reads add more. The inventory must be derived from traced fixture execution plus catalog inspection, not inferred from this partial list. Trace only synthetic data in a rolled-back TEST transaction, redact secrets, and independently review every SELECT/INSERT/UPDATE privilege. `BaDemoFixtureService` and `LocalManagementDatabase` currently require the runtime role and default connection; a separate guarded setup connection and its identity checks must be implemented without relaxing existing runtime guards. All fixture/receipt operations must use that same physical connection and transaction.

## Recovery and publication remain blocked

Current candidate snapshot is multiple READ COMMITTED queries, not an authorization proof. Define and test a locking protocol covering every selected table and phantom insert, token/subject/user/role/policy revocation, expiry at database clock, and the exact held native file/ciphertext identities, serialized through the no-replace rename. Establish lock privilege requirements before granting them. Review lock ordering, deadlocks, unknown commit outcome on a new connection, retry and crashes around physical commit/rename. Reject unsafe or stale observations. The separate role alone does not solve these issues or protect against another process running under the same Windows account.

## Review gates

Independent database/security review precedes any role or credential mutation. Review and prove secret-file exclusive creation and ACLs, secure password provisioning and uncertain-outcome recovery; only then prepare exact executable SQL and test-only commands. Runtime, PUBLIC and unrelated roles must have no way to assume setup privileges. No DEV access, server deployment or publication follows from this plan.

## Lock privilege finding

PostgreSQL 17 permits `LOCK TABLE ... IN SHARE MODE` with MAINTAIN, UPDATE, DELETE or TRUNCATE on each table. Append-only tables deny the latter three to runtime, so the proposed setup role could receive MAINTAIN on a frozen, individually reviewed snapshot allowlist solely if security review accepts its additional maintenance capabilities. The current snapshot dynamically discovers tenant tables: a short fixed lock list would be unsound. Freeze the exact inventory, fail closed on schema drift, and lock all snapshot relations plus tenants and receipts in stable order with NOWAIT if this approach is accepted. SHARE locks are table-wide and affect every tenant. A database session failure can release them while the OS rename is in progress; holding a transaction and checking immediately before rename alone cannot prove atomicity. Resolve that failure mode through a reviewed protocol and adversarial tests before enabling publication. Reference: https://www.postgresql.org/docs/17/sql-lock.html.

## Connection and inventory review findings

The following are design findings, not an approved grant list or permission to enable setup:

- `TenantContext::run` explicitly selects Laravel's `pgsql` connection, while the fixture, its lifecycle and BA services, audit writer, snapshot and local database guards use the DB facade. A second connection used only for receipt insertion would place the receipt and fixture on different physical sessions and transactions. A setup execution context must bind every transitive operation, tenant setting and receipt to one pinned physical connection, detect reconnect or connection replacement, and restore framework state even on exceptions. Changing the default globally without process isolation is unsafe for concurrent requests. Existing runtime identity guards must remain strict; setup needs its own separately checked authority.
- `BaDemoSnapshot` discovers tenant tables through `information_schema.columns` and `information_schema.tables`. Those views filter objects by the current role's privileges, so a restricted setup role can silently omit an ungranted table and still hash a candidate snapshot. A trusted, independently obtained complete schema inventory must be frozen and compared for drift, including unprivileged relations, partitions and relevant views. Granting SELECT on whatever happens to be visible does not prove completeness. The currently dynamic snapshot is not an authorization proof.
- Direct fixture writes include tenants, projects, policy_versions, departments, employees, users, project_memberships, department_memberships, role_assignments, access_grants, auth_subjects, auth_tokens, source_instances and ingestion_connectors. It revokes temporary access_grants by UPDATE and writes audit_events through `AuditWriter`. Requirement and task services also write requirements, requirement_drafts, requirement_versions, criteria, criterion_versions, requirement_version_criteria, lifecycle_tasks, task_versions and task_criterion_links. BA services write their own receipts, source heads, party/request versions, links, clarification and events. These are source-code observations only: conditional branches, authority locks, triggers, foreign keys, sequences and snapshot reads are not accounted for, and this list must not be used as executable grants.
- The candidate receipt trigger reads auth_tokens and auth_subjects under the invoking role. Test the actual effective trigger/function privileges and RLS policies, not only INSERT on `ba_demo_receipts`. Runtime and PUBLIC must remain unable to insert, update, delete or truncate receipts, including through inherited roles, ownership, functions and views.
- `sharedLock()` and `lockForUpdate()` in the transitive services can require privileges beyond SELECT. Determine exact required operations from a synthetic fixture trace and PostgreSQL semantics, then independently assess whether they can be limited to the fixed tenant. A broad UPDATE grant solely to make a lock succeed is not automatically acceptable.

Before enabling any path, test missing/other tenant context, arbitrary query-builder writes and upserts against each granted relation, session/current role and endpoint substitution, rollback on a late failure, pooled/reconnected sessions, receipt poisoning by runtime and PUBLIC, inventory omission/schema drift and concurrent mutation/revocation. Verify exact physical session identity throughout the transaction. These tests cannot prove safe publication until the separate database-session-failure versus file-rename race has a reviewed solution.

## Read-only catalog checks for the reviewed TEST connection

Run these only after checking the endpoint is the dedicated loopback TEST connection; output contains no credentials. A missing setup role is expected before creation. These queries are evidence inputs, not a substitute for comparing every returned field to the installation record and allowlist:

```sql
SELECT current_database(), session_user, current_user, inet_server_addr(), inet_server_port(),
       shobj_description(d.oid, 'pg_database') AS database_marker
FROM pg_database d WHERE d.datname = current_database();

SELECT rolname, rolcanlogin, rolinherit, rolsuper, rolcreatedb, rolcreaterole,
       rolreplication, rolbypassrls, shobj_description(oid, 'pg_authid') AS role_marker
FROM pg_roles WHERE rolname IN ('monitoring_ai_app', 'monitoring_ai_ba_demo_setup');

SELECT pg_get_userbyid(m.member) AS member, pg_get_userbyid(m.roleid) AS granted_role,
       m.inherit_option, m.set_option, m.admin_option
FROM pg_auth_members m
WHERE pg_get_userbyid(m.member) IN ('monitoring_ai_app', 'monitoring_ai_ba_demo_setup')
   OR pg_get_userbyid(m.roleid) IN ('monitoring_ai_app', 'monitoring_ai_ba_demo_setup');

SELECT n.nspname, c.relname, c.relkind, c.relrowsecurity, c.relforcerowsecurity,
       pg_get_userbyid(c.relowner) AS owner
FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') ORDER BY c.relname;
```

Once the setup role exists, independently check `has_database_privilege('monitoring_ai_ba_demo_setup', datname, 'CONNECT')` for each database and `has_table_privilege` for each allowlisted relation and each forbidden privilege. Check applicable `pg_policies` definitions and effective PUBLIC/membership privileges; a `REVOKE` from a role alone does not override grants inherited from PUBLIC. Check HBA and actual noninteractive connections as well. The candidate directly reads tenants, projects, users, employees, auth_subjects, auth_tokens, policy_versions, project_memberships, role_assignments, access_grants, source_instances, ingestion_connectors, ba_clarifications, ba_request_versions, ba_request_links, ba_events, ba_source_party_versions and ba_receipts, **plus** its dynamic tenant-table scan; this list is not a complete grant or lock inventory.

## Probe re-review block

The draft probe wrapper is unconditionally disabled. A timeout followed by a new session seeing no role is not enough: the original PostgreSQL backend can still be running and later create the cluster-wide role. A safe implementation must obtain and pin the original backend PID **and backend_start** before issuing DDL, establish a trusted independent administrative connection to the same verified installation, terminate or await that exact backend, verify its absence (without mistaking PID reuse for the original), then verify role absence in a fresh transaction. Handle timeouts, inaccessible `pg_stat_activity`, concurrent cancellation, process crash, ambiguous initial handshake, and cleanup failure as unknown state and refuse retry; prove these cases with an actual delayed backend. A process kill alone is not evidence that server-side work ended.

The draft also uses the default libpq password file, whose identity and ACL are not pinned. Before any probe execution, establish a protected credential file with an authenticated final path and owner/ACL checks on every ancestor, prevent replacement between validation and libpq open, restrict content and protect crash cleanup. Verify the actual file libpq uses; do not put password bytes in argv, environment, SQL, logs, test output or chat. Existing `.local/db-config.json` does not contain a reviewed probe administrator identity. Review must also enumerate effective PUBLIC, schema, function, relation, sequence and default ACL privileges across the relevant databases; draft checks are incomplete. If any identity, lifetime, credential or privilege proof is unavailable, do not create the role or claim rollback. The review SQL is non-executable design material until a security reviewer approves the complete protocol.
