# E02 lokal ingestion poydevori — implementatsiya rejasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Synthetic manbadan tenant/project doirasidagi hodisalarni doimiy qabul qilish, takrorlarni ajratish va worker uzilishidan tiklanadigan PostgreSQL outboxni yetkazish.

**Architecture:** Mavjud Laravel autentifikatsiya, AccessGateway va TenantContext qayta ishlatiladi. Hodisa va outbox bitta PostgreSQL transactionda yoziladi; delivery at-least-once va consumer idempotent. Real Kanban API tekshirilmaguncha hech qanday masofaviy connector requesti yoki write bajarilmaydi.

**Tech Stack:** Mavjud composer.lock bilan Laravel/PHP, PostgreSQL dev/test; yangi dependency yoki frontend runtime yo‘q.

**Spec:** docs/superpowers/specs/2026-10-05-platform-completion-brief.md; tasdiqqa ko‘rsatilgan docs/design-mockups/e02-ingestion-flow-v1.png; repository DOCX §§5/6/23/26/30.3, FR-040/050/220, AC-02/09/10.

## Global Constraints

- User “Ishni boshla” instruction on 2026-10-05 authorized execution of this saved plan following the displayed E02 design.
- Continue branch monitoring-platform-foundation for this ongoing task; preserve all existing changes. No automatic commit/push/merge/deploy.
- kanban.mo.local is read-only. Do not create/edit tickets, change statuses/configuration, install tokens/webhooks, or replay requests to it.
- Synthetic data only in this unit. No real employee/source content or credentials in fixtures, prompts, logs, audit or documentation.
- All product AI local/company-controlled; this unit does not invoke AI or external inference.
- Existing safe local migrator only; no migrate:fresh, reset, truncate or SQLite substitution. Test writes only in guarded monitoring_ai_test.
- New scoped tables use composite tenant foreign keys and FORCE RLS. Runtime stays nonowner/nonSUPERUSER/nonBYPASSRLS.
- Accepted payload, event and immutable lineage must be conserved; exactly-once transport is not promised.
- Limits below are implementation safety assumptions for the synthetic unit, not approved production capacity or retention policy.

## Review Focus

1. Same logical event/version with different bytes must conflict and preserve the first payload; I2 test.
2. Tenant/project supplied in client content must not override the trusted actor and connector binding; I1/I2 tests.
3. Crash after lease/processing must not lose outbox work or apply a duplicate projection; I3 tests.
4. Revoked access or disabled connector between acceptance and processing must block further reads/delivery; I3 tests.
5. Late events, identical timestamps with contradictory state, missing actor and incomplete payload must remain explicit; I4 tests.

## Files and ownership

- I1 worker: migration 000007, connector domain/controller and access-purpose additions, ConnectorAccessTest.
- I2 worker: envelope schema/validator, ingestion service/controller, IngestionAcceptanceTest.
- I3 worker: outbox consumer/lease service, local console drain command, OutboxRecoveryTest.
- I4 worker: synthetic normalization/projection/freshness and CanonicalProjectionTest.
- Lead: route and interface integration; docs/OpenAPI updates after each task. Independent database/security/backend reviewer after each task and whole unit. Workers share workspace, preserve others' changes, do not delegate/branch/commit.

## I1 — Connector binding and database boundaries

**Files:** Create backend/database/migrations/2026_10_05_000007_create_ingestion_foundation.php; backend/app/Domain/Ingestion/ConnectorService.php; backend/app/Http/Controllers/ConnectorController.php; backend/tests/Feature/ConnectorAccessTest.php. Modify backend/routes/api.php and backend/app/Domain/Access/DatabaseAccessGateway.php.

**Interfaces:** ConnectorService::create(TrustedActor $actor, string $projectId, array $data): object; ConnectorService::status(TrustedActor $actor, string $connectorId): array. Purposes connector.manage and ingestion.accept are project-scoped and require current membership; ingestion.read is separate. All three checked by AccessGateway, with explicit grants, no inherited report access. Synthetic connectors bind existing kind=manual source instances; one source/project binding is unique. Status contains operational counters only; raw receipt content has no HTTP read endpoint in this unit.

**Schema:** ingestion_connectors(id, tenant_id, project_id, source_instance_id, adapter='synthetic-v1', status='disabled'|'active', revision, created_at); ingestion_receipts(id, tenant_id, project_id, connector_id, source_object_id, source_version, event_kind, payload_hash, payload_json JSONB, occurred_at, source_updated_at nullable, received_at); ingestion_outbox(id, tenant_id, project_id, receipt_id, status, attempt_count, available_at, lease_token nullable, lease_until nullable, completed_at nullable, reason_code nullable); ingestion_projections(id, tenant_id, project_id, connector_id, source_object_id, current_receipt_id, source_updated_at nullable, state JSONB, coverage_flags JSONB). IDs UUID; timestamps timestamptz(6). Unique receipt(tenant,connector,source_object_id,source_version,event_kind), outbox(tenant,receipt), projection(tenant,connector,source_object_id). Composite FKs bind receipt/outbox/project/connector/source with no mismatched project. Add needed composite unique parent keys. Receipt UPDATE/DELETE/TRUNCATE forbidden; projection/outbox identity columns immutable; state/lease fields mutable via bounded service operations.

Database review refinement: receipt also stores source_instance_id and source_object_type='ticket'; canonical unique tuple is (tenant_id, source_instance_id, source_object_type, source_object_id, source_version, event_kind), rather than connector identity alone. A source-object cannot be silently rebound across projects; a conflicting project under this tuple yields409. Preserve the bounded original request bytes as payload_bytes separately from validated canonical JSON, and calculate both transport_hash and canonical_hash. Canonical hash determines semantic replay; original bytes prove the initially accepted payload. CHECK constraints bound size, hash format, enums, positive revisions/nonnegative attempts and lease field consistency. Live delivery-attempt history/quarantine for authenticated malformed envelopes is a subsequent adapter unit; malformed synthetic requests are rejected before persistence, never claimed durably accepted.

- [x] Write tests proving unauthenticated/unauthorized/revoked actors denied; explicit connector grant cannot read project reports; unknown adapter rejected; cross-tenant and same-tenant wrong-project references rejected; missing tenant context yields no reads/writes; HEAD cannot create anything.
- [x] Run `php artisan test --filter=ConnectorAccessTest --colors=never`; capture expected missing behavior failure before implementation.
- [x] Implement schema, RLS/grants, service and GET/POST `/api/v1/projects/{project}/connectors`, GET `/api/v1/connectors/{connector}`. No arbitrary URL, token storage, network, delete or settings update endpoint in this synthetic unit.
- [x] Incrementally migrate guarded test DB with `php artisan app:migrate-local --env=testing`; run the targeted tests green and existing access/isolation regressions.
- [x] Independent schema/access review; fix and re-review before I2. Commit only if separately authorized.

## I2 — Bounded durable acceptance and replay

**Files:** Create contracts/ingestion-envelope-v1.schema.json; backend/app/Domain/Ingestion/IngestionEnvelopeValidator.php; backend/app/Domain/Ingestion/IngestionService.php; backend/app/Http/Controllers/IngestionController.php; backend/tests/Feature/IngestionAcceptanceTest.php. Modify backend/routes/api.php.

**Interfaces:** IngestionEnvelopeValidator::validate(array $envelope): array; IngestionService::accept(TrustedActor $actor, string $connectorId, array $envelope): array returns receipt_id and duplicate boolean. POST `/api/v1/connectors/{connector}/receipts` existing local bearer plus ingestion.accept, response 201 for new durable receipt or 200 for exact replay. No unauthenticated public webhook in this unit; real source authentication is a later selected-adapter task.

**Envelope:** version='ingestion-v1', source_object_id and source_version nonempty max200, event_kind='ticket.snapshot', occurred_at timezone-explicit ISO8601, source_updated_at nullable timezone-explicit ISO8601, payload={external_project_id, external_ticket_id, title max500, status max100, external_actor_id nullable max200, due_at nullable timezone-explicit ISO8601}. additionalProperties=false at both levels; reject tenant_id/project_id and arbitrary metadata/description/code. Synthetic max64KiB encoded payload. Server computes canonical sorted-key JSON SHA256; null/string/number types preserved. No name/email identity merge.

- [x] Write tests: triple replay plus equivalent key order yields one receipt/one outbox; different hash under same logical key yields409; missing/invalid/oversized fields rejected before write; forged scope and disabled/cross-project connector denied; injected transaction error leaves no partial receipt/outbox and no success response.
- [x] Run `php artisan test --filter=IngestionAcceptanceTest --colors=never`; record red.
- [x] Implement actual local Opis JSON schema validation and atomic acceptance using DB uniqueness for concurrent duplicate safety. Existing immutable receipt returned only after rechecking authorization. Never expose raw payload/hash bindings in safe errors/audit.
- [x] Run tests green, verify DB invariants with runtime direct writes and authorization regressions.
- [x] Independent spec/security review; no claim real webhook verification is implemented.

## I3 — Durable outbox consumer and recovery

**Files:** Create backend/app/Domain/Ingestion/OutboxProcessor.php; backend/app/Console/Commands/DrainLocalIngestion.php; backend/tests/Feature/OutboxRecoveryTest.php.

**Interfaces:** OutboxProcessor::drain(TrustedActor $actor, string $connectorId, int $limit=20): array returns counts only; claim/complete uses receipt immutable binding and lease UUID fencing. CLI `app:ingestion-drain {tenant} {actor} {connector} {--limit=20}` guarded local/testing; validates actor/current grant/membership/tenant/connector at each batch and before delivery. No credentials in CLI args/output.

This is an explicitly invoked local operator command, not an autonomous service impersonating a user. The command requires local operator authority and an active app user with explicit processing grant; CLI actor identifiers alone do not create/revive grants. Production service-principal/worker credential design remains a separate image/design approval scope. Processing requires ingestion.process, separate from connector.manage/ingestion.accept/ingestion.read, and status never confers payload access.

**Assumptions:** Max100 batch, 60s lease, max5 attempts, retry delays 5/30/120/600 seconds then dead_letter; counters persist PostgreSQL. Reason codes fixed allowlist, no exception text. Disabled/revoked access leaves work safely blocked, not silently delivered. No arbitrary handler/class/URL or tenant from JSON.

- [x] Write tests: crash after committed acceptance recoverable; expired lease reclaim; old lease cannot complete replacement claim; two processors cannot own same active lease; repeated completed work does not duplicate projection; retries persisted, fifth failed attempt dead-letter; tenant/access revocation/connector disable blocks processing; foreign receipt cannot be consumed.
- [x] Run `php artisan test --filter=OutboxRecoveryTest --colors=never`; record red.
- [x] Implement bounded SKIP LOCKED claim and atomic projection+completion, lease fencing and retries. Use separate test sessions for concurrent claims; injected clock for expiry boundaries.
- [x] Run green plus real PostgreSQL concurrent/rollback checks; no shared DB destructive operations.
- [x] Independent durability/security review and re-review fixes.

## I4 — Synthetic normalized facts, coverage and freshness

**Files:** Create backend/app/Domain/Ingestion/TicketSnapshotProjector.php; backend/tests/Feature/CanonicalProjectionTest.php; backend/tests/Fixtures/ingestion-envelope.php. Extend connector status service and tests.

**Interfaces:** TicketSnapshotProjector::apply(object $receipt): array invoked only from authorized OutboxProcessor context; status API includes last_received_at, last_processed_at, pending/dead_letter counts and coverage reason codes, never raw payload.

- [x] Write tests: newer source_updated_at wins; late older event retained without replacing current; equal timestamp contradictory versions marked ordering_conflict, unknown ordering cannot silently choose latest; unresolved actor remains unresolved; approved event-time identity mapping resolved with mapping revision, no email/name inference; UTC microseconds preserved; source missing does not report healthy synchronization.
- [x] Run `php artisan test --filter=CanonicalProjectionTest --colors=never`; record red.
- [x] Implement version-aware synthetic projection and freshness. Missing timestamp/actor returns explicit coverage flags; no productivity/actual-hour/risk conclusions. Display latest accepted receipt separately from latest processed event, no invented source watermark.
- [x] Run targeted suite green, identity/time boundary regressions; independently review.

## I5 — Contracts, verification and local handoff

**Files:** Modify docs/api/openapi.yaml; docs/README.md; docs/decisions.md. Create docs/contracts/ingestion-data-dictionary.md; docs/operations/local-ingestion.md; docs/acceptance/e02.md; docs/contracts/source-capability-matrix.md.

- [x] Record Kanban observed UI capabilities and unknown API/auth/pagination/history/webhook/deletion capabilities; distinguish UI observations from actual connector support. Do not record metadata secrets or unrelated LMS implementation details.
- [x] Record exact new routes/contracts/schema, safety assumptions, permissions, replay/retry/DLQ recovery, limits and disabled real-adapter status. Fix verified stale UI/review/commit statements without guessing unobserved permissions.
- [x] Run `php artisan test --colors=never`, `php vendor/bin/pint --test`, `composer validate --strict`, `composer check-platform-reqs`; parse schemas/OpenAPI and compare actual route list. No new install/network call.
- [x] Independent whole-unit Laravel/database/security review; one focused fix wave and scoped re-review; freshly rerun affected/full checks as necessary.
- [x] Apply reviewed incremental migration to dedicated dev through safe app:migrate-local; local synthetic smoke test without real source payload; document exact results and limitations. No automatic admin/source employee bootstrap or Kanban writes.
- [x] Deliver completion evidence and ask commit approval if scope lacks it. Remaining E02 source pagination/backfill/reconciliation, source credential/webhook verification, encrypted object payload store, real imports, quota scheduling and notifications stay explicitly open.

## Acceptance boundary

This unit tests durable receipt/outbox replay/crash handling (part of AC-02), tenant/job scope (part of AC-09), missing actor/ordering/freshness flags (part of AC-10). It does not satisfy complete AC-02 until notification delivery exists; AC-01 calendar/attendance metrics, real diff coverage and complete source reconciliation are future units. No full E02/MVP/production readiness claim.

## Execution review

Approved execution: subagent-driven with independent reviewers per AGENTS.md and sequential implementation owners. User “Ishni boshla” followed presentation of this saved plan. Dependency refinement: I1 → I2 → I4 → I3 → I5 so I3 consumes the concrete reviewed projector. No commit, push or deployment authorization is inferred.
