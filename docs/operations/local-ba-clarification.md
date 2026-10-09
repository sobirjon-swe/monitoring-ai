# Local synthetic BA operations — E05-02

[Contract](../contracts/ba-evidence-v1.md), [dictionary](../contracts/ba-data-dictionary.md), [acceptance](../acceptance/e05-02.md). Existing PHP8.5/Laravel13/PostgreSQL17 and installed Opis validator; no runtime, dependency, model or server provisioning. Product AI is unavailable; E04 Windows parser stays disabled. Source fixtures are synthetic, authenticity not verified. No deployment/live integration/UI/finance activation.

## Guarded party registration

From backend/, with an existing authorized actor/project and ignored token file:

```powershell
& ../.local/tools/php85/php.exe artisan app:register-local-ba-party $projectId $fixturePath --token-file=$tokenFilePath --expected-source-revision=0 --idempotency-key=synthetic-customer-1
```

Fixture is a strict ba-evidence-v1 party.register envelope. Command reads token only from explicit file≤512 bytes, never argv/fixture/log output. It reads≤65537 fixture bytes to detect65536 limit, enforces schema/secret gates, and invokes existing BaFixtureService under ba.fixture.manage + ba.source.read. It creates no grants, employees/login, source calls or credentials. Output is safe receipt/party UUIDs and replay flag; failure is generic. Delete temporary token/fixture files in finally after use. Existing operator-owned credential files are not automatically deleted.

LocalBaDatabase delegates all established LocalQaDatabase endpoint/role/marker checks and adds read-only runtime ownership rejection for ba_ tables. Both local/testing only, defaultpgsql, expected dedicated database, loopback55432, runtime monitoring_ai_app, public search path, no URL/read/write/options overrides, live database/session/current role/server port/marker checked, nonowner/nonSUPERUSER/nonBYPASSRLS. HTTP and CLI use this guard. Unavailable guard returns generic503 for BA HTTP; CLI fails before mutation. No guard changes environment configuration or relaxes QA checks.

## Incremental migration and recovery

Existing dev incrementals29–33 applied2026-10-09 using app:migrate-local, after separate runtime **and actual migration session** preflight of fixed loopback55432/database marker, distinct migrator role and allowed nonSUPERUSER/nonBYPASSRLS owner membership. Pending migrations were restricted to29–33. Runtime remained nonowner afterward; selected older module counts were unchanged. Postflight observes37 total migration-history rows and no pending increments; sequence labels29–33 are filenames, not the total history count. Test increments were already applied; no reset, down, fresh migration, history deletion or schema rewrite was used.

Authorized diagnostic commands from repository root:

```powershell
& .local/tools/php85/php.exe .local/ba-task4-preflight.php
& .local/tools/php85/php.exe .local/ba-task4-preflight.php --migrate
& .local/tools/php85/php.exe .local/ba-task4-crm.php
```

Diagnostics are ignored local files, retained with task evidence. The preflight wrapper rejects unexpected pending migration names before invoking app:migrate-local. Migration permission belongs to the approved existing local scope; do not invoke for production/shared databases or new unreserved increments. If refusal/failure occurs, preserve logs and migration history, diagnose and retry the established wrapper with authorized unchanged configuration. Never replay down/reset or delete immutable BA history to repair a failed request.

## Acceptance and cleanup

CRM diagnostic uses actual authenticated Laravel HTTP kernel, one runtime PDO and outer transaction/savepoints. Every HTTP step asserts same PDO and outer transaction level1. It exercises Ivan question/customer wait, John exact distinct confirmation/replay/stale conflict, inherited scope obligation, Victoria named department view, linked task staleness/unrelated current/history and GET/HEAD revocation. All fixture setup is synthetic and rolls back unconditionally; no DELETE cleanup. Postrollback counts every public tenant-column table for the new tenant plus tenant itself, including12 BA tables, token/subject/grant/membership/E03/QA tables. All must be0; all created tokens must authenticate null and final transaction level0. The temporary CLI files are removed in finally.

Real TCP `/up`200 and `/api/v1/me`401 establish unauthenticated server liveness only. They are separate from the authenticated HTTP-kernel diagnostic and do not establish authenticated TCP UAT, live source connectivity or production readiness.

Retry exactly authorized envelopes/keys for replay; conflicting hash/source/aggregate revisions need a fresh explicit operation with current exact pins. Do not fall back to older answers or invent scope clearance when unavailable/stale/revoked. Changed criteria/tasks remain historical and current BA dependency becomes stale only for exact affected links. Calendar/model outages remain honest unavailable fields; external AI fallback is prohibited.
