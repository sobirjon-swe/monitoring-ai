# Browser session contract v1

F01 local read workspace. Routes are served by the same Laravel origin `http://127.0.0.1:18080` as `/app`; `/ui-assets` contains the verified local build. This contract describes reviewed F01 implementation; integrated acceptance remains a separate [draft gate](../acceptance/frontend-f01.md). Existing `/api/v1` remains Bearer-only and ignores browser cookies. Browser routes reject any Authorization header, so credentials cannot silently fall back between identities.

## Session protocol

| Request | Result |
| --- | --- |
| GET/HEAD `/browser/v1/session` | No prior binding:200 anonymous DTO. Valid current binding:200 authenticated DTO. Previously bound invalid credential:401 and binding removed; subsequent bootstrap may return anonymous200. HEAD has empty body. |
| POST `/browser/v1/login` | JSON exactly one token string; raw JSON at most1024 bytes, token at most512 bytes. Valid credential200 authenticated DTO; invalid401; malformed422; oversized413; sixth attempt within configured local client's minute429 with Retry-After. No query parameters or credential echo. |
| POST `/browser/v1/logout` | Origin-or-token CSRF protected,204; invalidate only this browser session, never the underlying valid Bearer. Bootstrap again for current CSRF. |

Anonymous DTO: `data:{authenticated:false,csrf_token:string}`.
Authenticated DTO: `data:{authenticated:true,csrf_token:string,user:{id,tenant_id,employee_id,display_name},expires_at}`. Identity is verified, not role-derived. Expiry is precise offset timestamp, capped by the underlying token deadline and sliding120-minute server-enforced idle deadline; the client does not authorize itself from this timestamp.

Raw token enters only the login JSON. Shared canonical verifier rechecks current token/subject/user/tenant and credential identity each request under TenantContext/RLS. Server binding version1 stores verified identifiers, keyed fingerprint and last_seen_at, never raw token/secret/hash. Successful credential proof precedes ID/CSRF rotation; failed login removes old binding. Revocation/expiry/rebinding/credential replacement invalidate the next request.

File-backed local session and encrypted host-only HttpOnly SameSite=Lax cookie, observed name `monitoring-ai-session` (configurable `SESSION_COOKIE`). The encrypted cookie identifies the server-only binding; this is not a claim that server session files are encrypted. Secure=false is explicitly limited to this approved loopback HTTP setup. Other hosts, forwarded identity, unsafe session/runtime overrides and malformed browser paths are refused by pre-auth guards before credential/session access. These are local-development restrictions, not deployment support. Sensitive responses are no-store.

Installed Laravel13 origin-or-token CSRF permits safe reads, valid same-origin Fetch Metadata or a matching session token; frontend sends X-CSRF-TOKEN. Login/logout have no exemption. Tests disable PHPUnit's default console bypass for middleware proof; final real HTTP/browser evidence is separate.

## Authorized project discovery

GET `/browser/v1/projects?cursor=<opaque>` returns `data:{items:[{id,name,revision}],next_cursor:string|null}`. Fixed25 UUID-ordered rows, no global total, no GET body or unknown query fields. Cursor is signed and actor/tenant-bound, not a grant; every continuation rechecks current project.read authority. Invalid cursor422. Nil linked employee/no eligible membership produces an honest empty page.

GET `/browser/v1/projects/{project}/capabilities` requires project.read and returns `data:{project_id,purposes:string[],observed_at}`. Fixed allowlist: project.read, requirements.read, progress.read, qa.read, qa.source.read, ba.read, ba.source.read, management.read, management.source.read. Only authorized scope hints are shown; endpoint object/source/department checks remain mandatory.

## Read aliases

All paths below are relative to `/browser/v1/projects/{project}` and allow GET/HEAD only. They invoke accepted controllers directly, without HTTP proxying or business-write aliases. Same data envelope/validation/defaults and source/privacy semantics as existing Bearer endpoints.

| Suffix | Query / behavior |
| --- | --- |
| `/requirements`, `/lifecycle-tasks` | limit1..100 default20, UUID cursor; actual plain-array listing DTO, no invented totals. |
| `/progress` | optional UUID baseline_id; preserve baseline/policy, accepted criterion weight denominator, null/unavailable/partial. |
| `/qa/verification` | optional department; execution coverage is not pass rate or accepted progress. |
| `/qa/queue`, `/qa/history/{kind}/{subject}` | limit1..100 default50, existing QA cursor, department; UUID subject, canonical kind validation. Queue represents handoffs. |
| `/ba/queue`, `/ba/history/{clarification}` | limit1..50 default20, signed cursor<=4096, department; UUID clarification. Authenticity/scope/wait states remain separate. |
| `/management/queue`, `/management/history/{action}` | limit1..50 default20, signed cursor<=4096, department_id; UUID action. Completed support never resolves underlying QA/BA/E03 dependencies. |

Permission-filtered totals may be null; hidden-empty complete0 and unavailable/null have distinct meanings. BA/management ages use PostgreSQL delivery clock; sequential responses may advance only age scalars. History links use currently delivered typed identifiers/version locators, never arbitrary evidence URLs. Errors retain safe code/status/request ID and never render raw server bodies or submitted credentials.

Lifecycle evidence delivery also requires authoritative project-scoped `ingestion.read` where existing EvidenceService source access applies. It is not an added capability hint: the fixed nine-purpose allowlist above remains unchanged. Root UAT found the initial six-persona omission; the independently reviewed service/test correction and selected DEV repair added six read grants and six native audits, preserving the other105 canonical inventory entries. All six native progress reads and PM browser now show partial0/100 with `human_acceptance_missing`. Manifest JSON hash, credentials, expiry and journal are unchanged. Missing or revoked source authority still yields `source_access_unavailable`, never fabricated zero/complete progress.

## Browser ownership and limits

Client serializes login/logout/bootstrap/recovery through provider authTransition. Private views clear immediately; login waits for logout and fresh CSRF. Session epoch fences delayed old-session401 side effects, while separate view scopeGeneration fences old project/history successes. Current-session401 still clears identity even after a project switch. Capability hints are presentation only.

UZ/RU/EN system labels are local. Source text stays original. Persist only harmless monitoring.locale; no token in browser storage, URLs or logs. No external assets/inference/translation. F01 is a read milestone; F02 mutation/reference-selector workflows and wider platform release remain pending.

See [OpenAPI](../api/openapi.yaml), [dictionary](../data/browser-f01-dictionary.md), [local workspace runbook](../operations/local-browser-workspace.md) and [F01 acceptance draft](../acceptance/frontend-f01.md). Contract/source checks do not replace real six-role browser acceptance.
