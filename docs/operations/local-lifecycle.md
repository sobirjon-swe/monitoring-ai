# Local E03 lifecycle handoff

2026-10-06. Branch requirements-evidence-baselines, base a9ad71e. R1–R5 implemented and individually reviewed; R6 final whole-unit/scoped review and verification passed; ten reviewed dev incrementals000009–000014 and rollback smoke passed. Commit pending. No production readiness claim.

Use existing PHP8.5.11 at `.local/tools/php85/php.exe`, locked Laravel13.34.0, PostgreSQL17.10 dedicated loopback55432 dev/test and existing ignored environment configuration. [Current environment](local-development.md). No external inference, source fetch/write, scheduler or service principal is added.

After whole-unit review and any scoped re-review, run from backend:

```powershell
& ../.local/tools/php85/php.exe artisan app:migrate-local
```

The reviewed incrementals are 000009_create_requirement_versions, 000009_fix_requirement_guards, 000009_strengthen_requirement_lineage, 000010_create_lifecycle_tasks, 000011_create_lifecycle_evidence, 000011_strengthen_evidence_lineage, 000012_create_baselines_and_acceptance, 000012_strengthen_acceptance_authority, 000013_strengthen_lifecycle_dispatch and 000014_correct_lifecycle_action_times. Do not reset/fresh/drop or edit migration history. Isolated test history also retains the initially applied old name 000010_correct_lifecycle_draft_guard; renamed corrective function replacement was applied incrementally. No deletion was used to normalize history.

The ignored `.local/e03-smoke.php` prepares synthetic user/employee/project/grants, runs real services within an outer development transaction, checks67/100, explicit carry67/120 with frozen67/100, latest failed QA/invalidation, then rolls everything back and verifies no tenant survives. Controller executed it after review/migration: all checks passed, zero tenant retained; it never persists an admin or authenticating token. Transactional smoke is not durable claim/concurrency evidence. Dedicated PostgreSQL tests supply committed two-session lock/lease evidence and retain synthetic immutable fixtures.

Authorized explicit local operator processing:

```powershell
& ../.local/tools/php85/php.exe artisan app:lifecycle-drain <tenant-uuid> <actor-uuid> <project-uuid> --limit=20
```

The command refuses nonlocal/testing, non-PostgreSQL, wrong loopback/name/marker, superuser/BYPASSRLS/owner role, URL/read/write overrides and invalid UUID/batch. Operator needs explicit project lifecycle.process; evidence/decision rebuild also needs ingestion.read. Original change actor/purpose is independently rechecked. Successful output uses six fixed counters. No payload or authority proof is printed.

Lease recovery: expired lease can be reclaimed with a new token; old token cannot complete/retry or overwrite newer work. Processing and completion commit atomically. Retry intervals5/30/120/600s, maximum five claims; expired fifth claim dead-letters. blocked access_revoked and dead_letter require investigation and a later separately authorized recovery procedure; no automatic redrive API exists. Source correction immediately affects live eligibility even if E02 projection work is pending; E03 does not add a cross-outbox scheduler.

Inspect correlation IDs, bounded status/count metadata and permission/coverage reason codes; never dump receipt contents, prompts, access proofs, credentials or raw SQL. Backup/restore, production rollout/backfill, retention/deletion policy and service provisioning remain open. Migration rollback that discards immutable history is not an authorized recovery action.

HTTP current endpoint is configurable127.0.0.1:18080. `/up`200 and unauthenticated protected-route401 prove liveness/denial only, not DB/queue/model/source readiness. GET/HEAD lifecycle reads are nonmutating and enforce the same permission; HEAD body is empty.

Creating a new immutable historical progress snapshot during close or baseline replacement additionally requires independent ingestion.read; baseline.approve never grants source access. Denial rolls back the whole operation. Initial baseline creation has no prior history to freeze; reopening already-frozen history does not recreate it. Historical delivery still checks the viewer source permission.

000014 changes only prospective defaults for requirement_versions.approved_at, dod_policy_versions.approved_at, lifecycle_changes.occurred_at, baselines.effective_at, human_acceptance_decisions.decided_at and baseline_progress_snapshots.as_of to PostgreSQL clock_timestamp(). New values describe server action/observation time after project coordination, including an outer transaction or lock wait; retained immutable timestamps are not rewritten.
