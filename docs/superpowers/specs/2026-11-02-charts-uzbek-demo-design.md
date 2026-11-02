# Chart dashboard and Uzbek demonstration design — 2026-11-02

## Request and proposed outcome

User requests useful charts and realistic Uzbek demo names/projects on the approved universal liquid glass system. Proposed initial scope:12fictional Uzbek employees,3fictional IT projects (Savdo CRM, Ombor boshqaruvi, Mijozlar portali), descriptive role catalog and meaningful seeded lifecycle/QA/BA/support states. Explicit DEMO marker everywhere; common realistic names do not identify real people and no actual personal data is collected. Keep all existing features/permissions.

## Approach and alternatives

Recommend canonical isolated demo workspace + permission-aware chart aggregates. It exercises real login, native services, persistence and RLS; additional work is reviewed manifest/setup and bounded aggregates. Static browser-only fixtures are cheaper but cannot demonstrate the real workflows, so remain visual QA only. Editing old pinned demo is rejected: would invalidate its hash/recovery receipts and historical authority proofs. Preserve all existing demo rows, credentials and policies unchanged. New dedicated tenant, fixed IDs and versioned setup; no automatic seed on app startup.

## Charts: truthful denominators

1. Accepted progress: accepted/total BASELINE WEIGHT, never task completion percentage. Existing progress API supplies values and coverage. Show unknown/unavailable as explicit state, never zero. No invented trend.
2. Task status bars: current authorized project's canonical current-task count by status. Requires a dedicated bounded aggregate endpoint; never infer totals from first loaded page. Obsolete/versioned task records cannot duplicate count.
3. QA: separate executed-criteria/required-criteria coverage and all-required-tests-passed criteria/denominator; use actual existing QA contract. Verification coverage is not pass rate or accepted progress. Distinguish excluded/unknown/omitted evidence visibly.
4. Open dependency bars: QA handoffs and BA clarifications separately, not a combined success score. Counts require permission-aware bounded aggregate and distinct record states; stale/revoked/missing authority are not open/closed evidence.

Accessible direct values + labels/units, textual/table equivalent, semantic warning colors and mobile stacked panels. Preserve UZ/RU/EN, keyboard focus, current shell, synthetic/AI-unavailable provenance. Filter changes cancel stale requests. No aggregation across unauthorized projects or hidden named employees. No workhour inference, employee ranking or attendance collection. No time series until a reviewed persisted history contract exists.

## Demo scenario

Names (all fictional): Azizbek Karimov(PM), Madina Rasulova(BA), Sardor Ismoilov(backend), Dilnoza Qodirova(QA), Jasur Tursunov(frontend), Shahnoza Ergasheva(designer), Behzod Rahimov(DevOps), Zilola Akbarova(QA), Otabek Mirzayev(backend), Nigora Saidova(BA), Diyorbek Abdullayev(frontend), Mohira Yusupova(technicalwriter). Roles descriptive; system purposes are explicit separate grants. Only catalog administrator creates; PM assigns existing roles only in authorized project, no implicit employee creation/membership grant. Team roles may overlap, never assume sum of role counts equals employee total.

Seed via native services with source provenance and bounded tokenless producers, audited versions and explicit effective periods. Do not bypass acceptance rules to make charts attractive. Include open/blocked/unverified/missing-data scenarios and only truly accepted demo evidence. Exact counts in design image are illustrative, not fixed implementation claims; record achievable native-service evidence during planning. New production migrations are additive only if aggregate contract needs schema; avoid schema work when bounded existing reads suffice.

## Security and activation

New manifest/service cannot reuse browser-demo-v1 mutable setup, hardcoded producer IDs or protected recovery receipts. Setup reviewed and tested on monitoring_ai_test first; root/operator expressly selects DEV invocation after manifest/grant matrix/output protection are concrete. User request authorizes creating demo content, not extra production privileges or expanding existing two operators. New fixture-specific synthetic operators may get only documented required scopes, bounded8hcredentials in protected ignored output. No source integration, external AI, SSO or server deployment. Replay/recovery cannot renew grants or alter conflicting existing IDs silently.

## Required acceptance

Review schema/query semantics and exact actor/project/filter-bound aggregation budget/cursors/coverage. Tests for tenant/project denial, partial visibility, archived/stale/revoked state, zero-vs-unknown, duplicate versions, seeded native constraints and replay/no-effect. Existing behavioral suites preserved; fresh SPA typecheck/build plus relevant PostgreSQL regressions. Browser proof on populated real DEV demo, desktop/mobile and locales, then independent UI/security review. Commit/push/merge remain separately authorized.

## Approval boundary

Before product code, show docs/design-mockups/charts-uzbek-demo-v1.png as liquid glass chart composition proposal and obtain explicit image approval required by AGENTS.md. The proposed new demo/setup data flow will be recorded as an image before backend implementation. Builder may settle rendering library/native SVG and component organization after evidence of installed packages; no library installation presumed. No product code or DB mutations performed during discovery.

Image notes: charts-uzbek-demo-v1.png is illustrative; exact labels/arithmetic will be deterministic in implementation (42accepted of60 leaves18unaccepted,30%, not the image generator repeated42). Use “Hali qabul qilinmagan” rather than assuming remaining work. Generated operator-chevron is not a new account menu requirement. Data-flow image charts-uzbek-demo-flow-v1.svg is part of approval proposal. Original-authority grants for new tokenless synthetic producers/reviewers must be explicitly reviewed in a pinned manifest; the prior two-producer exception is not inherited. Native acceptance requires verified reviewed evidence and applicable mandatory QA, never direct accepted-total writes.

## Builder review ruling

Read-only builder review identified producer-lifetime ambiguity. Proposed choice: separately reviewed narrowly scoped retained ORIGINAL authority for the new fixture's tokenless evidence producers/reviewers, independent of8hour interactive tokens. No login credentials for producers; only new tenant/projects, exact purpose/IDs in reviewed pinned manifest, no expansion of old exception or old principals. If user does not approve that concrete new manifest, DEV provisioning remains unexecuted; product can be tested in isolated TEST. Revocation must still invalidate dependent charts/evidence. This is an explicit proposed new demo exception, not a current grant or authorization claim. Exact matrix is an implementation-plan deliverable before selected DEV invocation.
