# Frontend API — UI/UX brief v1

> Historical pre-mockup exploration, preserved as provenance. Current status2026-10-24: the F01 written design, exact images and execution plan were subsequently approved; React read workspace, guarded DEV synthetic publication and T1–T6 verification are complete. Statements below that authentication/project endpoints, packages, image approval or product code are absent describe that earlier discovery point. [Current bounded acceptance](../acceptance/frontend-f01.md) records passed browser/code gates and approved final review. F02 mutations/selectors still need their own inventory/design/image approval.


2026-10-24. **Pre-mockup investigation record; the subsequently presented F01 design and exact image set are now user-approved.** UI/UX specialist: `gpt-6.1-sol`, reasoning `medium`. No child agents. Existing desktop dashboard approval is preserved. React SPA/version baseline approval is recorded in the written design and image index; implementation plan review is pending, and product code has not begun.

## Confirmed direction and boundaries

- User selected entering an existing local development token once, then using a server session. The brief describes that flow; cookie/session/CSRF, token lifetime/revocation and logout engineering still belong in the backend design. No real credential appears in mockups or screenshots.
- Preserve the approved navy sidebar, light workspace, blue accents, neutral cards and inline SVG language. Keep system fonts and local assets; add no Google Fonts/CDN or animation dependencies. The approval covers desktop overview composition, not new login, mobile or module screens (`dashboard-ui-v1-approval.md:7–11`).
- F01 is the first reviewable milestone: sign-in → authorized project selection → real local E03 requirements/tasks/progress → QA, BA and management read queues with history, UZ/RU/EN interface text, desktop and mobile. F02 separately tracks mutation/approval forms and their authorized reference selectors; it is not dropped. Delivering only F01 does not complete the user's full website/workflow goal. Dedicated role dashboards and periodic reports remain tracked later goals.
- React SPA is selected. Proposed exact toolchain from root's registry-metadata checks: React/react-dom 19.3.1, TypeScript 7.0.2, Vite 8.3.5, React plugin 6.1.2, React types 19.3.0 and Node 24.19.0. These versions and the serving/session architecture need written design approval; the specialist did not independently verify or install packages.
- Current data is synthetic; label it near the project header and each interpretation-sensitive result. AI remains unavailable; no synthetic signal, chart or connector total becomes a real inference/health result. Local-only AI and read-only Kanban remain fixed requirements.

## Actual skill guidance and fit check

Read installed `C:/Users/sobirjon.qurbonov/.codex/skills/ui-ux-pro-max/SKILL.md` and executed its local stdlib search with native Python 3.13.14, `-B`, ordinary network-restricted sandbox. Queries contained only generic public terms. No `--persist`/`--force`, network, package installation or product inference was used.

| Query | Actual top identity | Decision |
| --- | --- | --- |
| `project monitoring dashboard --design-system --json` | Smart Home/IoT Dashboard; glassmorphism; real-time/energy controls | Rejected: wrong domain and contradicts the approved shell. |
| Single narrower retry: `project management SaaS --design-system --json` | Micro SaaS; marketing conversion; motion-driven style | Rejected: marketing page guidance does not fit an internal evidence workflow. No verified product design-system match found. |
| `accessible authentication --domain ux --json` | Security / Accessibility — Accessible Authentication (Minimum) | Verified relevant: permit paste/password managers; avoid cognitive-only authentication. Apply paste and accessible field/error behavior to the chosen token flow. Passkeys/OAuth suggestions do not change the selected authentication scope. |
| `async project state --stack react --json` | Concurrency — Use Actions with async startTransition | Top match not specific enough for project-switch privacy; do not adopt its snippet or introduce optimistic approvals. Its separate “Handle async errors” result fits visible request-failure feedback. |
| Single narrower stack retry: `effect cleanup race --stack react --json` | Effects — Clean up effects; next match Specify dependencies correctly | Verified relevant as general React effect lifecycle guidance: clean up subscriptions/timers and include reactive dependencies. It is not a complete authorization/race solution; project-switch clearing, request cancellation/late-result guards and server reauthorization remain explicit design requirements. |

No unverified result was persisted. Visual/layout recommendations below are **general-default fallback** from the skill priority table, adapted to existing approved project assets; they are not a successful database design-system match. Use visible keyboard focus, text/semantic status labels, readable contrast, 44px touch controls, clear loading feedback, nearby field errors and reduced motion. The skill's generic no-horizontal-scroll rule applies to the page; a contained, labelled data-table scroll region is appropriate where table relationships need preservation. React results identify `react 19.2.x`, not the proposed 19.3.1; accept their general error/cleanup/dependency principles only, and verify version-specific APIs during implementation. Do not adopt remote fonts, glassmorphism, marketing heroes, videos, GSAP, telemetry, optimistic approval state or automated live-update claims from search output.

## Screenshot compositions to prepare after remaining decisions

| Proposed image | Composition and states |
| --- | --- |
| Sign-in desktop + mobile | Compact branded card; visible “Local development token” label; masked empty/example field, paste allowed, explanatory local-session text, submit loading, safe generic invalid/expired message, keyboard focus. No invented signup, password reset, SSO or real token. |
| Project chooser desktop + mobile | Verified signed-in display name and logout; authorized project names; clear selected project; loading and “no projects available” state with configured administrator guidance. No global project count, private project discovery or client role switch. |
| Project workspace desktop | Existing shell plus project name/synthetic/AI-unavailable context; requirements/tasks tabs; exact version/state; approved baseline and accepted-weight numerator/denominator; awaiting verification, blocked and unknown states. Detail shows authorized evidence/version history without conflating completion and acceptance. |
| Evidence workspace desktop | QA / BA / Management navigation with project context. A bounded queue beside selected-record history. QA shows build/environment/plan and failed/blocked/missing/stale separately; BA shows question/answer/waiting/scope and confirmation; management shows action/support/review/current outcome separately from underlying dependency. Each module must be legible in its own detail image; do not imply one merged acceptance score. |
| Mobile workspace + failure states | One-column queue, then detail with explicit Back; project/module selector remains accessible. Show loading, permitted empty, partial/unknown coverage and session/access-revoked screens. Narrow tables scroll internally; long IDs/hashes wrap or use labelled disclosure. No hidden totals when permission filtering withholds data. |

Root will prepare two UI boards: access (sign-in/project chooser and degraded states) and read workspace (module compositions and desktop/mobile). Sample names and numbers must be explicitly fictional. Mockup annotations distinguish existing API facts, proposed missing endpoints and unavailable capabilities. F01 includes UZ/RU/EN interface text; show the language control and representative translated text without implying machine translation of source evidence. Full six-role dashboards, periodic/multilingual report content and forecasting remain tracked future goals, separate from these first image compositions and F01. F02 mutation/selectors remain explicitly tracked for subsequent designs and implementation units.

## API facts that constrain the interaction

- `/api/v1/me` currently returns identity only, not a capability list (`backend/app/Http/Controllers/IdentityController.php:15`). Browser authentication/session/logout and a GET project collection are absent (`backend/routes/web.php:3`, `backend/routes/api.php:28–39`). Authorized project discovery and UI action capabilities therefore need deliberate backend contracts. Never derive authority from profile text or role title.
- Existing reads: requirements, lifecycle-tasks, progress (`backend/routes/api.php:46,51,60`); QA verification/queue/typed history (`:74–77`); BA queue/history (`:85–86`); management queue/history (`:94–95`). Existing endpoints do not supply the demo's global member counts, activity chart, project risk labels or source-health totals.
- QA execution coverage is not pass rate, release approval or E03 accepted progress. A missing plan/denominator differs from approved zero scope (`docs/contracts/qa-evidence-v1.md`, Read DTO and paging).
- BA resolution does not approve scope or change E03/QA. Customer authenticity remains `not_verified`; customer wait is a dependency, not blame (`docs/contracts/ba-evidence-v1.md`, Identity/replay and Reads).
- Management completion does not resolve underlying QA/BA/E03 dependencies. Current action, support, historical review, applicability and outcome remain separate (`docs/contracts/management-evidence-v1.md:32–38`).
- All queue ages are elapsed UTC; business age remains unavailable. Preserve exact versions and server-provided reason codes with understandable labels. Permission-filtered unknown is not a favorable empty result.

## Interaction and review criteria

On project switch, clear old project records immediately and cancel/ignore late responses for the previous scope. On expired session or revoked access, remove protected content and guide the user to the appropriate permitted next step. Distinguish loading, empty, partial/unknown, denied, unavailable and conflict states; never leave stale success visible as current. Pagination says “Load more” without inventing a global total.

Keep existing skip link, table captions, status announcements, visible focus and dialog focus restoration. Add visible form labels and inline validation linked to each field; announce errors and move focus to a useful error target. Native keyboard controls must work without hover. History retains provenance, timestamps and version identifiers; display timestamps with explicit project timezone. Mutation units additionally need reviewable exact version/CAS context, duplicate-submit handling, safe conflict recovery and explicit confirmation boundaries; no automatic approval or source-system writes.

After approved images and implementation, compare running desktop/mobile screenshots to those images and independently review keyboard, focus, contrast, permission visibility and degraded states. Static source review or historical demo screenshots cannot substitute for that verification.

## Remaining material decisions

1. React SPA is selected; approve exact proposed versions and serving arrangement through the written design. Do not reopen the vanilla-versus-SPA choice.
2. Backend details for the selected token-to-session flow and authorized project list/capability delivery are in the written design. The user selected a separate persistent local synthetic six-role workspace; exact least-privilege manifest/guards/credential output are engineering review items, not permission to clone broad bootstrap grants. Existing bootstrap alone lacks the module authority.
3. F01 read workflow and F02 named creation/review/mutation/reference-selector units are tracked separately. Full workflow remains incomplete until all approved units are delivered; approvals for new images/designs still precede each material implementation scope.
4. UZ/RU/EN interface text and desktop/mobile scope are established requirements, not unresolved preference questions. Image/design review should verify translation coverage, visible language state, long-label layout and mobile usability; it must not silently reduce them to Uzbek desktop only.

This brief satisfies UI/UX specialist exploration before mockups. React SPA is already selected; proposed package pins, new images and written design still need approval. No product code, real source access, production policy or deployment is approved by this brief.
