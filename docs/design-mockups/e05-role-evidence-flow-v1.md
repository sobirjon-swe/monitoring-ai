# E05 rol dalillari — diagramma v1 va E05-01 doirasi tasdiqlangan

2026-10-06. Branch `role-evidence-monitoring`, base E04 `49519f0ace50cd618e3300d093cc91486a3a35a4`. User authorized committing E04 and starting E05 investigation; the E05-01 image/scope was subsequently explicitly approved by “Tasdiqlayman, ishni boshla”. Written design and implementation plan received their subsequent recorded approvals; current verification status is below. E04 commit contains the reviewed safe-disabled partial unit; native parser activation remains blocked.

## Maqsad va spetsifikatsiya

Repository DOCX v1.0 §30.6 E05 depends directly on E03. FR-070/080/090/100 and AC-07/08 require role-specific evidence and handoff queues: Andrew QA, Steve developer, Ivan BA, John PM, Adam development head, Victoria BA head. Observed facts, deterministic projections, unavailable AI and human decisions stay separate. No volume ranking, hours inference or automatic blame.

## Variantlar va tavsiya

1. **Tavsiya: E05-01 QA dalillari, keyin boshqa rol jarayonlari.** Immutable synthetic build/environment/test-type and criterion-version bindings, versioned test definitions and executions, defects/retest, bounded QA/handoff facts API. Smaller reviewable units; full AC-07 remains pending until BA/PM/department follow-up.
2. All six role modules in one unit: broader acceptance coverage, but larger authorization/schema/calendar surface and more unresolved policy dependencies.
3. Live-source-first: realistic data, but DEC-02/03/05 and source/retention permissions are unresolved. Not recommended for this local synthetic stage.

## E05-01 taklif doirasi

- Local synthetic fixtures only; import registration through authenticated bounded contracts, no real source calls or arbitrary artifact execution.
- Explicit immutable build identity and environment version, test type, criterion version and source execution identity. Designed/reviewed test state stays separate from executed/passed/failed/blocked outcomes and derived stale applicability.
- Versioned test plan defines applicable criterion denominator and explicit exclusions. Coverage counts distinct applicable criteria with current valid executions, shows pass/fail/blocked/missing separately; release-critical failures cannot be hidden by majority passes. Missing applicability/plan => unknown, never fabricated denominator.
- Distinct retests retain old executions. Scoped replay does not inflate counts; source ordering and immutable identities prevent late events replacing newer state. Build/criterion/environment changes stale affected projections without rewriting evidence.
- Defect severity, reproduction evidence, first build, affected criterion, triage/duplicate links, fixing MR reference and retest. Reopen requires reviewed cause category; no person blame inference, no escaped-defect ratio without approved release/observation window.
- Explicit append-only handoff/blocker/escalation-owner events bind exact task version and event-time assignee/role; later reassignment does not rewrite original responsibility. Queue age is elapsed UTC time only; business-time age remains unavailable without approved calendar.
- QA queue and task-linked handoff facts for Andrew/Steve/John; Adam only within current authorized project/department scope. No implicit access from role names, no cross-project or named employee summary permission inferred. Ivan/Victoria workflows and full six-role AC-07 follow in subsequent units.
- Tenant/project composite keys, nonowner FORCE RLS, exact source/purpose authorization on write/read/job, immutable audit/history, CAS and keyset bounds. Existing event-time role/allocation gaps remain explicit unknown.
- E03 `QaObservationService` stores only passed/failed/missing without build/environment. E05-01 is a separate QA projection: a failed candidate-build QA signal stays visible even beside E03 synthetic accepted progress (for example67%); it does not claim E03 accepted progress is build-qualified, mutate E03 gate, silently backfill old records or approve release. Explicit E03 acceptance bridge requires a later separately approved policy/design.

## Keyingi E05 qismlari

E05-02: BA readiness/clarifications/decisions; customer wait and selective criterion changes. E05-03: PM/department handoff/escalation/support and event-time responsibility; fictional versioned calendar/allocation with unknown fallback. E05-04: role snapshots and permission-filtered notification previews/quiet hours (no messages sent), six-role CRM end-to-end acceptance and explicit E03 bridge. These are roadmap proposals, not implemented or approved.

## Qabul ssenariylari

CRM-151 authorization test failure remains visible; blocked environment remains blocked; wrong build/old criterion cannot count as current verification; duplicate execution import adds no count; fixing-MR retest preserves failure history; reject-to-merge criterion change stales only affected test projections; cross-tenant/project and revoked-source reads denied. Isolated PostgreSQL concurrency/RLS tests and fresh independent Laravel/database/security review are required after approved design/plan.

## Chegaralar va ochiq qarorlar

All product AI remains local only and `unavailable` until local runtime/quality gates exist. No live Kanban/GitLab/QA writes, UI, external inference, deployment, financial allocation or automatic notification. Real DEC-02 calendar/DoD/allocation, DEC-03 QA source/build contracts and DEC-05 named-person/small-group/notice/retention policy are future gates. Five-person privacy threshold is a proposal, not adopted policy. `grill-me` was unavailable in the design-stage session catalog; requirements were resolved using the supplied specification and read-only specialist analyses. The user approved the E05-01 depicted image/scope; roadmap later units remain proposals. PNG is unchanged and its historical approval label is superseded by this dated record. Subsequent [written-design v1](../superpowers/specs/2026-10-06-e05-qa-handoff-design.md) and plan/execution approvals superseded the historical preimplementation waiting state.

Approval2026-10-06: user “Tasdiqlayman, ishni boshla”; exact image SHA256 `c621ecf0656b0b16c4d67a5395b7909038f7a36d092cdda762ffe8a020ecb764`. Build changes mean explicit selection compatibility, not mutation of immutable build. Completed passed/failed executions count toward execution coverage; pass rate and E03 acceptance remain separate.

Final E05-01 gate2026-10-07: the single whole-unit fix wave and independent scoped rereview addressed I1–I4/M1 with no new breakage. Fresh bare PHP suite557passed/12987assertions/614.13s exit0; covering192passed/4780assertions; root integrated24passed/705assertions/51.13s exit0. Full Pint, locked Composer and OpenAPI checks passed. Guarded dev19–28 already applied; refreshed authenticated Laravel HTTP-kernel CRM69 static assertions passed with31 scoped absence counts zero, token absent and transaction0. TCP200/401 remains liveness only. The approved synthetic QA backend E05-01 is complete and ready for commit approval; full E05/AC-07, BA/PM/UI, live sources, local inference, production NFR and deployment remain open. E03 acceptance remains separate; image/design scope unchanged. No commit/push/merge/deploy permission inferred.
