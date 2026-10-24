# F01 browser data dictionary

2026-10-24. Browser transport adds no database tables or migrations. It reuses canonical E03/QA/BA/management DTOs and existing tenant isolation. [Session contract](../contracts/browser-session-v1.md), [OpenAPI](../api/openapi.yaml) and [acceptance draft](../acceptance/frontend-f01.md) define the bounded read milestone.

| Item | Meaning / boundary |
| --- | --- |
| `browser_credential` | Server-side session binding v1: `version`, `tenant_id`, `token_id`, `subject_id`, `user_id`, keyed `fingerprint`, precise `last_seen_at`. No raw token, token secret or secret hash. Revalidated through the shared current-credential verifier on each request. |
| Cookie | Observed `monitoring-ai-session`; encrypted identifier for server-only binding, host-only, HttpOnly, SameSite=Lax. File-backed server session is not claimed encrypted at rest. Local approved HTTP uses Secure=false. |
| Session DTO | `authenticated`, session `csrf_token`; authenticated variant adds verified user `id`, `tenant_id`, nullable `employee_id`, `display_name`, and `expires_at`. No global role switch or grant list. |
| `expires_at` | Precise offset timestamp: current underlying token expiry capped by a refreshed 120-minute server idle deadline. It is a display hint, never client authorization. Exact endpoint is expired. |
| Project summary/page | UUID `id`, `name`, integer `revision`; fixed maximum25 `items`, nullable actor/tenant-bound signed `next_cursor`. No hidden total. Every continuation rechecks current `project.read` and membership. |
| Capabilities | Selected `project_id`, current fixed allowlist `purposes`, precise `observed_at`. Presentation hints only; object/source/department checks remain server-side. |
| Requirements/tasks | Existing versioned lifecycle list DTOs. A queue is not a general entity catalog. Preserve canonical identifiers/revisions for history. |
| Progress | Accepted criterion weight / approved in-scope weight, with baseline/policy versions. Null/missing denominator is unknown; implemented or management-completed work is not accepted progress. |
| QA | Verification coverage, plan/build/environment, handoff queue and typed history remain separate. Missing verification is not a passing test. |
| BA | Clarification, unverified customer authenticity, waiting party, scope/review state and history remain separate. Customer wait is not employee blame. |
| Management | Action, assignment, support, review, applicability, outcome and underlying dependencies remain distinct. Completed support does not close QA/BA/E03 obligations. |
| Coverage/reasons | Permission-filtered totals may be null. Authorized complete empty0, partial, unknown, denied and unavailable are distinct. Known system codes have local UZ/RU/EN labels; unknown codes retain a visible fallback and original code. Source text remains original. |
| Client scope | Auth transition/session epoch fences stale auth effects; project/view generation fences late private responses. Switching/logout clears private state before later responses can render. Only harmless `monitoring.locale` is persisted. |
| Synthetic workspace | Exact manifest creates six read-only interactive identities and two explicitly approved tokenless source principals, scoped to one fictional tenant/project. Four temporary lifecycle production grants are revoked before credential publication. See [operator matrix](../operations/local-browser-demo.md). |

All timestamps normalize to UTC; workspace presentation uses Asia/Tashkent. No new global activity, source health, employee rankings, actual hours, AI result or financial totals are inferred. F02 selectors and mutation forms remain pending.

Existing lifecycle evidence source access may additionally require project-scoped `ingestion.read`. This authoritative check is independent of the unchanged nine-purpose browser capability hints. The independently reviewed service/test correction and selected DEV repair added six read grants and six audits after root found the initial omission; the other105 canonical inventory entries, credentials, expiry and manifest JSON hash are unchanged. All six native progress reads and PM browser now show partial0/100 with `human_acceptance_missing`. Missing/revoked source access still produces `source_access_unavailable`, never fabricated zero/complete progress.
