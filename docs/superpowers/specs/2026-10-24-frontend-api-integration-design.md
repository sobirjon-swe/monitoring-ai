# Local React website and authenticated API integration

2026-10-24. **F01 design and linked image set approved:** after presentation of this document and the access/workspace images plus session-flow link, the user answered “Tasdiqlayman”. The approved scope is React SPA, the listed package-version baseline, existing local development token once → server session, F01 authenticated read workspace, UZ/RU/EN, desktop/mobile and separate persistent synthetic six-role workspace. The six-task implementation plan was subsequently authorized with “Ishni boshla”; implementation, native DEV provisioning and final integrated verification are complete. One final whole-unit review approved the bounded local read handoff; see [F01 acceptance evidence](../../acceptance/frontend-f01.md). No password system, SSO, F02 forms, server deployment or external AI activation is included.

Task branch: `frontend-api-integration`, intended completed E05-03 base `85cb84c`. Preserve the standalone `frontend/` demo. All Codex agents use `gpt-6.1-sol`, reasoning `medium`; no complexity-based substitution. This is a design, not an implementation plan or a claim of running software.

## Purpose and acceptance boundary

Provide a usable local website over accepted Laravel business workflows: authenticate, discover permitted projects, inspect requirements/tasks/progress, and follow QA, BA and management evidence. A project chooser must work without manually entering UUIDs. Facts, human decisions, missing evidence and unavailable AI remain distinguishable.

The authoritative baseline is the repository requirements DOCX v1.0 dated 2026-10-02. FR-001 governs scoped views, FR-020 effective project roles, FR-030 requirement/task readiness, FR-040 source coverage, FR-050 timestamps and FR-200 authorization. Existing E03 and E05 acceptance contracts constrain this integration. AC-09 isolation checks contribute evidence; this website does not establish full production AC-09 or E09 readiness. FR-090/100 management semantics remain those of accepted E05-03: completed support does not resolve underlying QA/BA obligations.

Two independently reviewable milestones preserve the full frontend objective:

| Milestone | Deliverable | Boundary |
| --- | --- | --- |
| F01 authenticated workspace | Login/logout, project chooser, React shell, real E03 requirement/task/progress reads, QA verification/queue/history, BA and management queue/history; UZ/RU/EN system text; desktop/mobile states; separately guarded persistent synthetic six-persona workspace | A usable read milestone, not completion of every website workflow |
| F02 workflow forms | Bounded authorized selectors/catalogs and specifically inventoried E03/QA/BA/management mutation forms, each reviewed against its accepted contract | Must enumerate payloads, selectors, permissions, conflict/review states and image scope before implementation; no promise that an existing queue lists every entity |

F02 remains required for the broader website goal. Missing selector/read APIs must be designed and approved; free-form UUID entry is not an adequate final workflow. Reporting, forecasts, real integrations, local model activation and finance remain separate roadmap work.

## Architecture and technology

Use `frontend/spa/` for React source. Laravel serves the SPA at `/app` and its built assets from `backend/public/ui-assets`; the Vite build produces deployable local artifacts there. Preserve the demo and approved dashboard appearance as a reference. Do not add SSR, React Compiler, a styling framework, CDN fonts/scripts or runtime dependency downloads.

| Component | Proposed exact version / observed baseline |
| --- | --- |
| React / react-dom | 19.3.1 |
| TypeScript | 7.0.2 |
| Vite / @vitejs/plugin-react | 8.3.5 / 6.1.2 |
| @types/react / @types/react-dom | 19.3.0 / 19.3.0 |
| Node | Installed 24.19.0; satisfies observed Vite engine `^20.19.0 || >=22.12.0` |
| Backend | Existing Laravel 13.34.0 and PHP 8.5.11 |

Root discovery checked official documentation and npm latest metadata on 2026-10-24; T3 subsequently installed and locked the approved baseline with reviewed engine/peer/integrity checks. Verify exact peer/engine requirements and the resolved lockfile before installation. Sources: [React versions](https://react.dev/versions), [Vite guide](https://vite.dev/guide/), [TypeScript download](https://www.typescriptlang.org/download/), and registry metadata for [React](https://registry.npmjs.org/react/latest), [React DOM](https://registry.npmjs.org/react-dom/latest), [TypeScript](https://registry.npmjs.org/typescript/latest), [Vite](https://registry.npmjs.org/vite/latest), [React plugin](https://registry.npmjs.org/@vitejs/plugin-react/latest), [React types](https://registry.npmjs.org/@types/react/latest), [React DOM types](https://registry.npmjs.org/@types/react-dom/latest).

The minimal vanilla/Laravel option would reuse more demo code, but the user selected React. Vue was an alternative, not selected. All options require the same authentication and authorization boundary.

Use one origin, `http://127.0.0.1:18080`, for HTML, assets and browser requests. Initial acceptance uses a local build, without cross-origin Vite development/HMR. Bind to loopback and allow only the configured local host; reject unexpected Host values. Environment-specific endpoints remain configurable. Private services and PostgreSQL stay private.

## Token-once server session

Proposed browser contracts under `/browser/v1` are separate from existing Bearer `/api/v1` contracts: pre-auth session/CSRF bootstrap, POST login, GET session identity, POST logout, permitted-project pagination and selected-project capability hints. Exact paths and OpenAPI responses are reviewed before implementation.

1. Login obtains a pre-auth session and CSRF context. Submit the existing opaque token in the body, never a URL. Validate local/testing environment, loopback client and the current token/subject/user/tenant chain under RLS.
2. On success, destroy the previous session ID, rotate CSRF, and persist a server-only binding containing tenant/token/subject/user identifiers and a keyed credential fingerprint. Discard the raw token. Never retain it in browser storage, session payload, logs, flashed input, error context, analytics or responses.
3. Each browser request reloads the exact chain, validates issuer, current token expiry, token/subject revocation, active user/tenant, identity binding and credential fingerprint. Reconstruct `TrustedActor` only from that validated result. Share the current-token validation predicates with `LocalTokenAuthenticator`; do not implement a divergent copy.
4. Proposed maximum idle lifetime is 120 minutes, always capped by underlying token expiry. Activity cannot extend token validity. Invalid sessions return 401 and clear sensitive UI state.
5. Logout invalidates this browser session and rotates CSRF. Other tabs sharing it become unauthenticated. The underlying Bearer token and independent sessions are not revoked. No automatic token renewal, password or SSO is introduced.

Use server-side session storage and an application-specific host-only HttpOnly cookie, SameSite=Lax, with no wildcard domain. Explicit Secure=false is confined to this loopback HTTP setup; HTTPS environments require Secure=true. Cookie scope does not isolate ports, so host validation and origin protection remain necessary. No cross-origin credential/CORS relaxation is required. Sensitive browser responses use no-store; client errors expose safe request IDs, not secrets or raw evidence.

The currently mounted API middleware clears trusted attributes and requires Bearer authentication. Browser routes need an intentional web/session resolver, not attributes set before that middleware and subsequently overwritten. Never fall back between conflicting Bearer and session identities. Existing Bearer behavior remains independently covered. The management pre-auth database guard must cover both API and browser aliases before credential lookup.

Installed Laravel 13.34 web middleware uses `PreventRequestForgery`: safe methods, valid same-origin Fetch Metadata, or matching CSRF tokens can pass. The UI sends CSRF tokens; login/logout and mutations receive no protection exclusions. Cross-site mutation without a valid token must fail. Do not claim every missing-token request fails: same-origin metadata is intentionally accepted. Default console unit tests bypass this middleware; exercise real middleware and actual browser requests as acceptance evidence.

## Projects, permissions and real data

Project discovery runs within verified `TenantContext` using bounded set-based queries: active user/tenant, linked employee, current project membership and active `project.read` grant/policy with tenant or matching project scope. Proposed local persona policy explicitly includes `project.read` alongside permitted module purposes; do not infer it from any arbitrary module grant. A valid token with no employee, membership or grant sees an honest no-project state.

Reuse authorization predicates with `DatabaseAccessGateway`; do not fetch all projects then authorize individually. Stable-ID pagination has a bounded page size, scoped continuation and no hidden-project totals. Capture precise UTC time consistently; preserve inclusive starts/exclusive ends and microsecond expiry semantics. Runtime credentials remain nonowner and RLS-enforced; no cross-tenant lookup or administrative connection is needed.

For a selected project, return only a fixed allowlist of capability hints in bounded queries. Hints control presentation, not authority. Each endpoint still checks current purpose, membership, policy, source and object-specific rules; department views additionally require the department grant and membership. Revocation between rendering and clicking must deny the request. Clear project-specific client state and cancel/discard old responses when changing projects.

| Area | F01 existing reads | F02 existing operations to inventory |
| --- | --- | --- |
| E03 | requirements, lifecycle-tasks, progress | Draft/revision/approval, DoD, task/dependency, evidence/review, baseline/close, acceptance |
| QA | verification, handoff queue, typed subject history | Fixtures, plans/entries/reviews, selections, executions, defects/events, handoffs/events |
| BA | clarification queue/history | Open request, answer/correct/retract, events and reviews |
| Management | action queue/history | Open action, events, support record/correct/retract/decline and outcome review |

All browser adapters reuse accepted controllers/services and strict contracts. Preserve source/series revisions, generation/revision CAS, idempotency keys, immutable references and distinct reviewers. UI changes do not authorize writes to source Kanban; source integrations remain read-only. F01 does not expose source fixture endpoints as unrestricted data-entry forms.

Render server coverage and reason codes faithfully. Unknown/unavailable counts and percentages remain null; empty authorized data is distinct from denied or incomplete data. Preserve synthetic labels, unverified customer authenticity and unavailable AI. Unsupported global activity, employee rankings, finance and aggregate dashboard metrics show unavailable or are omitted. No external AI or automatic cloud fallback; all product inference remains local-only and separately gated.

## Interaction and images

System text supports Uzbek, Russian and English using local dictionaries; user evidence is not silently translated. Persist only harmless preferences such as language. Desktop and mobile retain keyboard navigation, visible focus, meaningful labels, accessible contrast, responsive tables/details and explicit loading/error/empty/permission/partial states. No inaccessible color-only status. Authentication expiry returns to login without preserving the token.

Created, visually inspected and explicitly approved on 2026-10-24: [access](../../design-mockups/frontend-api-access-v1.png), [workspace](../../design-mockups/frontend-api-workspace-v1.png), [session flow](../../design-mockups/frontend-api-session-flow-v1.png). [Image scope, hashes and prompt provenance](../../design-mockups/frontend-api-images-v1.md) bind this design to those exact files. All visible example records are labelled sample/synthetic; raster browser chrome is not a TLS/security guarantee. Session rotation occurs only after successful credential verification, as specified above; the flow boxes group responsibilities rather than defining internal statement order. The exact image approval is recorded in the linked index. Material new F02 interactions require corresponding approval.

## Selected synthetic workspace provisioning

The user explicitly selected a separate persistent local synthetic workspace with six role accounts after the independent builder check identified missing acceptance data. Include its guarded CLI/provisioning unit within F01, before the browser role acceptance and handoff. Use fictional PM, QA, developer, BA and two department-head personas, a clearly labelled project and nonempty synthetic E03/QA/BA/management records. This supplies actual local website data rather than relying on rolled-back smoke fixtures. No real source or employee data is imported.

The operator explicitly selects an exact reviewed manifest, isolated synthetic tenant/project and credential output file; nothing provisions on application start or through public HTTP. Never attach these grants to an unrelated existing tenant or silently merge identities by name. No self-grants, shared default secret or broad bootstrap grants copied to every role. Model employee/role/grant boundaries individually, including explicit project discovery permission, role-appropriate source purposes and distinct review actors. F01 browser access is read-only; four temporary lifecycle fixture-production grants are separately bounded and revoked before issuing website credentials. **Approved later exception (2026-10-24):** the user answered “Tasdiqlayman — ikkita tokensiz manba subyekti” for exact proposal SHA256 `04242dc29c6d07b3e84591e8df9d99b08777a1c4792a1588f4fa25e06a51790d`. Exactly two tokenless source/assignment principals retain only its narrow project/department production grants; neither ever receives an auth subject, token, password or browser credential. Six interactive personas remain read-only. Current-proof revocation/invalidation semantics are unchanged. The exact matrix and recovery limits are in [the provisioning runbook](../../operations/local-browser-demo.md). This supersedes the original remove-every-production-grant wording only for those two principals. Provisioning must preserve existing DEV records, refuse an incompatible existing fixture identity and handle retries without duplicate accounts/facts. No reset/down.

Tokens go only to an operator-owned protected local file, never chat/Git/logs; display only safe file locators. Existing token holders retain their actual current rights. Exact manifest, permission matrix, local database/role guards, file ACL handling and recovery checks are reviewed before execution. The selected scope authorized preparation; reviewed T5 and guarded root DEV publication have now run. This does not establish final browser acceptance. Migrations remain additive/forward-only.

## Completion evidence and remaining approval

F01 acceptance requires real browser login/reload/logout, permitted-project selection, all specified reads and language/mobile states against approved images. Verify expiry/revocation for token/subject/user/tenant/policy/grant/membership/source, tenant/department denial, precise boundaries, session fixation, fingerprint changes, CSRF origin/token behavior, safe errors, GET/HEAD behavior, bounded pagination/query counts and no raw-token persistence. Test actual authenticated HTTP and PostgreSQL RLS; previous HTTP-kernel smoke alone is insufficient.

F02 acceptance adds enumerated form workflows, authorized selectors, validation, safe retries/idempotency, stale 409 handling and independent review constraints. Report F01 as F01, not the full website. Each milestone needs independent review, integrated verification and commit authorization; push/merge/deploy remain separate.

User approved this written F01 design and its exact image set on 2026-10-24. React SPA, existing-token-once authentication and persistent synthetic persona scope need no repeated question. F02 action inventory/design remains the following required milestone, not an approved hidden extension of F01. Routine middleware ordering, shared predicate extraction, exact browser paths/DTOs, provisioning manifest and test mechanics are engineering work for the plan and reviews. The authorized T6 browser/tool verification and one whole-unit review are complete. Source/packages are installed; no F01 commit permission is inferred.

Independent builder check returned one material question: persistent synthetic data versus temporary acceptance fixtures. The user answered persistent synthetic workspace; the section above records the resolution. No other material F01 planning question was reported. Design/image approval was subsequently obtained as recorded above; implementation, scoped task verification and bounded integrated acceptance are complete.
