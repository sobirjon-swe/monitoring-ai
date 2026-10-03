# E01 organization and audit dictionary

Local foundation; FR-001/FR-020, AC-09. UUID keys and tenant isolation apply to every table. PostgreSQL application role does not own these tables; FORCE RLS requires transaction-local `app.tenant_id`.

| Table | Meaning and important fields |
| --- | --- |
| employees | Employee domain record independent of login. `id`, `tenant_id`, `display_name`, integer `revision`, UTC timestamps. No login password or email merging. |
| departments | Department record: `id`, `tenant_id`, `name`, `revision`, UTC timestamps. |
| department_memberships | Employee/department relationship with composite tenant foreign keys; `valid_from`, optional `valid_to`, `revision`. |
| reporting_relationships | Employee/manager relationship with composite tenant foreign keys, non-self constraint and effective period. |
| audit_events | Append-only event: tenant/actor UUID, optional project UUID, allowlisted action/resource/reason codes, resource UUID/revision, correlation UUID and UTC occurrence time. No free text payload, names, credentials or prompts. |

Effective periods are `[valid_from, valid_to)`. Historical relationships do not confer current project access. Lookup is bounded to 100 rows; full historical export is deferred. Relationship cycles/overlap business validation beyond non-self/valid-period constraints is not yet implemented.

GET/POST `/api/v1/employees` and `/api/v1/departments`; PATCH corresponding `/{id}` requires `expected_revision`. GET accepts `limit` 1–100 (default 50) and UUID `cursor`; response contains `data` and `next_cursor`. UUID keyset pagination is bounded but is not an immutable multi-page snapshot.

Requests require authentication plus server-produced `trusted_actor`, and `AccessGateway` authorization for `organization.read` or `organization.manage`. It denies all until T05 implements actual grants; payload/header tenant and actor identifiers cannot establish authority. Service methods are internal trusted orchestration APIs, not authorization bypass endpoints.

Mutation and audit append share one transaction. Stale revisions are HTTP 409, nonexistent/scoped-out updates 404, validation 422. Audit reasons are `local_setup`, `approved_change`, `membership_change`, `identity_review`, `access_change`; actions/resources currently allow only organization operations. Later domains explicitly extend these allowlists. Actor UUID authentication linkage is T05; no fabricated users foreign key exists in T04.

Audit rows cannot be updated/deleted by the runtime role; a database trigger additionally rejects UPDATE/DELETE if privileges are accidentally widened. Runtime cannot truncate. The owner can administer schema for explicit maintenance, so this is application-level immutability, not external tamper-proof archival.
