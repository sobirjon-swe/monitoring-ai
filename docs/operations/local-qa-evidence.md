# Local synthetic QA operations — E05-01

2026-10-06. [Contract](../contracts/qa-evidence-v1.md), [dictionary](../contracts/qa-data-dictionary.md), [acceptance](../acceptance/e05-01.md). Existing locked PHP8.5.11/Laravel13.34.0/PostgreSQL17.10/PHPUnit12.5.37/Opis2.6/Pint; no dependency/runtime/model provisioning. All AI unavailable; E04 actual OS parser remains disabled. No source network call/write, UI, scheduler, external AI fallback, deployment or finance activation.

## Guarded operators

Both commands refuse nonlocal/testing environments, wrong default driver/runtime role, nonloopback host, port other than55432, wrong dedicated dev/test database, URL/read/write/options/search-path overrides, unexpected live session/current role/database/server port/marker, SUPERUSER/BYPASSRLS and runtime ownership of any QA table. Config guard is fixed in `backend/config/qa.php`; normal DB credentials/endpoints remain ignored environment configuration. This guard does not grant permissions or create credentials. Shared `LocalQaDatabase` only reads configuration/catalog identity. Source/model service endpoints are not involved.

From `backend/`, using explicit existing scoped actor/token and local fixture paths:

```powershell
& ../.local/tools/php85/php.exe artisan app:qa-fixture $projectId $fixturePath --token-file=$tokenFilePath --expected-source-revision=0
& ../.local/tools/php85/php.exe artisan app:qa-fixture $projectId $fixturePath --token-file=$tokenFilePath --expected-source-revision=0 --idempotency-key=synthetic-fixture-1
& ../.local/tools/php85/php.exe artisan app:qa-drain $tenantId $actorId $projectId --limit=20
```

Fixture accepts only build/environment/definition strict envelopes, reads at most65537 bytes to detect overflow, and uses the same R1 parser/schema/secret gates as HTTP. Token comes only from an explicit file, at most512 bytes; it is never an argv option/value or output. Fixture actor needs existing `qa.fixture.manage` + `qa.source.read`; execution registration is a separate HTTP purpose. No self-grants/bootstrap/import bypass. Idempotency key is explicit or derived SHA256 of operation/source instance/object/source revision; expected CAS is excluded, so an exact replay can use a different stale CAS safely. Output contains receipt/record UUID, source-head CAS revision/replay marker only; errors are fixed text, no body/proof/exception.

Drain follows the existing explicit operator UUID convention. It validates `qa.process` + `qa.source.read` for every claim/publication, batch1..20. Output is only fixed counters claimed/completed/retry/fenced/processed. It is a maintenance service, not a scheduler/global service principal or model executor. Test fixtures use transaction-local authority; operational use must supply already authorized actors.

## Durable recovery

Canonical source/human facts, typed change, applicability, outbox and safe audit commit atomically. Admission≤100 noncatch-up pending/processing/retry jobs per project; saturated new operations503 before writes. Committed exact authorized retries/reads stay available, subject to terminal applicability. Existing E03 hooks append durable typed origin/change and coalesce catch-up without making E03 acceptance fail due to QA backlog. E03 progress/generation/QA observations are unchanged.

Claim stores actual60-second DB lease, fresh UUID token, attempt count and exact processing proof/hash. Publication rechecks operator/original processing authority, token/lease and current inputs under project serialization. Late/crashed worker cannot publish through an expired/reclaimed token. Retry delays5/30/120/600 seconds; max5claims, expired fifth claim dead-letters without a sixth. Successful bounded catch-up pages complete one job and reserve a continuation; successful pagination does not consume a failed job's retry budget. One publication processes at most20 affected subjects, one catch-up event at a time. Project-filtered global sequence holes are legal; watermark advances monotonically to captured target, and new arrivals extend/coalesce durable continuation.

After interruption, restore the approved local service/permissions and rerun normal drain after available_at/lease expiry. Never manually clear attempts/token/terminal state, delete immutable history, reset database, rerun a finalized event as a new source identity or replace original proofs. Dead letters require investigation/new corrected input; they cannot restart by pointer editing. Current reads recheck and suppress stale/unknown facts while maintenance catches up. Source restoration/replacement grant does not repair observed terminal invalidity. No favourable missing-evidence fallback.

History/queue pages use default50/max100 and scope-bound cursor≤128; every page rechecks access. Department filtering precedes pagination/count and current queue includes today's relevant reviewer; immutable history retains original dependency semantics. Queue age is elapsed UTC seconds, business_age unavailable. No named hidden counts, employee scores, inferred hours or automated notifications.

## Verification and smoke boundary

```powershell
& ../.local/tools/php85/php.exe artisan test --colors=never --filter=QaApiTest
& ../.local/tools/php85/php.exe artisan test --colors=never
& ../.local/tools/php85/php.exe vendor/bin/pint --test
& ../.local/tools/php85/php.exe C:/php/php84/composer.phar validate --strict
& ../.local/tools/php85/php.exe C:/php/php84/composer.phar check-platform-reqs --lock
& ../.local/tools/php85/php.exe artisan app:migrate-local
```

The last command is authorized reviewed dev19–28 **incremental** migration only, after independent schema/recovery gates; no reset or down/history deletion. Separate migrator/owner connection supplies DDL and is purged; runtime remains nonowner. Testing migrations use `--env=testing` only on the isolated configured test substrate. R6 final actual results are recorded in acceptance, not inferred from these instructions.

Dev CRM smoke creates synthetic tenant/employee/user/token/grants and evidence in one outer **runtime** transaction. Real services and authenticated Laravel HTTP kernel use the same PDO/savepoints so uncommitted fixtures are visible. Finally rollback and confirm token/grants/QA IDs absent. This proves dev schema/auth/router/controller/service behavior **in process**; it is not authenticated TCP end-to-end, production UAT or load evidence. No permanent active grants/tokens or retained-history deletion. Separate loopback `/up`200 and unauthenticated `/api/v1/me`401 are real-network liveness/denial only, never a substitute for CRM behavior.

Inspected2026-10-07: guarded dev19–28 incrementals passed (history22→32 entries); runtime nonowner/fixed55432/marker and separate migrator checks passed before/after. Rollback service+authenticated HTTP-kernel CRM diagnostic passed62 static checks outside PHPUnit:1/3failed/blocked coverage separate from E03 weight67/generation,3attempts/4events/manual close, permitted department filter before count/page, reassignment/criterion staleness with preserved event-time attribution.31zero scoped absence counts cover25new E05 QA tables plus preexisting E03 `qa_test_identities`/`qa_observations`/`qa_observation_heads`, tenant/token/grants; token authentication null/transaction0. Separate actual TCP127.0.0.1:18080 `/up`200, unauthenticated `/api/v1/me`401. These results are synthetic engineering evidence, not authenticated TCP CRM/UAT/load. [Acceptance](../acceptance/e05-01.md) records final current-tree543tests/12676assertions pass and the remaining whole-unit findings/fix/re-review gate.

Open gates: DEC-02 real DoD/calendar/allocation; DEC-03 real QA source/build contracts; DEC-05 named-person/privacy/notice/retention. No adopted five-person threshold, deletion policy, calibrated forecasts, backup/restore/RPO/RTO, p95/load, running local inference or full six-role AC-07 claim. AI remains unavailable when local provisioning/quality gates are absent; external provider is prohibited.

Final E05-01 gate2026-10-07: the single whole-unit fix wave and independent scoped rereview addressed I1–I4/M1 with no new breakage. Fresh bare PHP suite557passed/12987assertions/614.13s exit0; covering192passed/4780assertions; root integrated24passed/705assertions/51.13s exit0. Full Pint, locked Composer and OpenAPI checks passed. Guarded dev19–28 already applied; refreshed authenticated Laravel HTTP-kernel CRM69 static assertions passed with31 scoped absence counts zero, token absent and transaction0. TCP200/401 remains liveness only. The approved synthetic QA backend E05-01 is complete and ready for commit approval; full E05/AC-07, BA/PM/UI, live sources, local inference, production NFR and deployment remain open. E03 acceptance remains separate; image/design scope unchanged. No commit/push/merge/deploy permission inferred.
