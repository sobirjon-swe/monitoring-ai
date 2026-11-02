# Universal liquid glass design — 2026-11-02

## Intent and scope

User requests the full existing Monitoring AI UI adapted to liquid glass, expanding the approved employees/roles world. Eight workspace routes: overview, requirements, tasks, qa, ba, management, employees, roles. Include login, authenticated project picker, capability/loading/denied/error boundaries, evidence queues, record history, BA context forms/receipts, catalog creation and assignment dialogs. This is a visual migration, not feature expansion.

## Approach

Recommend one universal shared shell and semantic token/component system derived from people glass. Alternative: per-route CSS overrides are quicker initially but perpetuate three competing visual systems and state drift. A wholesale feature rewrite costs more and risks authorization/receipt behavior without visual benefit. Builder may organize CSS/components but must preserve existing route components and session/data boundaries.

## Visual system

Dark #151516 base, broad low saturation burgundy/olive/steel-blue/amber backdrop. Glass panels use translucent smoky fills, subtle1px pale borders and limited inner highlights; blur applies to major surfaces, not every nested row. Primary/active orange #ff6a14. Readable white primary and warm-gray secondary labels. Muted warning/error/success semantic colors with text, independent of accent. Offline Segoe UI; body16px, dense labels14px, page headings32–36px; typography and spacing hierarchy over decoration. Baseline8px spacing,24–28px page gutter,20px panel radius,12–13px controls, minimum44px hit areas. No external font/assets/runtime dependencies.

## Shell and content

Same two-row horizontal glass shell for all authenticated routes. First row brand, locale, identity/logout. Second row current section and capability-filtered navigation, with project context/change affordance. Eight long translated tabs must not squeeze: use a dedicated scrollable nav region at constrained widths, keep selected tab visible using native navigation/focus behavior. At mobile stack title/context and nav; no page overflow. Login and picker use reduced same-brand shell without inaccessible navigation. Keep deep links and browser back/forward intact.

Overview preserves actual weights/progress/coverage and truthful unavailable time series; no invented chart. Requirements/tasks retain all record fields and pagination; QA retains coverage, signals and source evidence; BA retains form/receipt/history behavior; management retains bounded evidence. Display long IDs in disclosures, human reasons remain visible. Do not hide warnings to simplify layout. Employees/roles retain approved create/assign interactions and mobile labeled cards.

## Accessibility and performance

Readable panel opacity even without backdrop-filter; no text directly over bright background. Reduced transparency support or opaque fallback; reduced-motion respected. Visible keyboard focus, skip link, proper heading hierarchy, native label/field semantics, escape/focus return and dialog traps. Cards/tables cannot drop fields on mobile; long code/translated text wraps. Do not animate background or blur. Use limited static effects rather than expensive stacked filters.

## Product invariants and non-goals

No changes to API/DB/grants, session epochs, CSRF, permission checks, bounded queries or role meanings. Admin-only catalog creation and PM own-project assignment remain. Preserve explicit pending/conflict/retry/ambiguous-write states. No fake global search, notifications, profile menus, data, employee scores, AI output or production readiness. Fixtures in images must say synthetic. Existing tokens expired since previous work: visual redesign does not authorize extending access; browser activation must use separately scoped existing provisioning flow if required.

## Acceptance and handoff

Before product edits, show a saved image board for overview, evidence and login plus existing approved people designs, and get approval for this expanded scope per AGENTS.md image gate. Generated images are design proposals only. Then write implementation plan, migrate shared shell/tokens first, adapt feature surfaces, run SPA tests/typecheck/build, inspect real desktop/mobile375px and long RU labels, independently review visual/accessibility and behavior. Record actual screenshots and deviations. Backend tests only if backend behavior changes; none planned. Preserve uncommitted people/roles work; no reset/stash or automatic commit/push/merge. All agent work gpt-6.1-sol/medium.

## Image proposal notes

Board universal-liquid-glass-board-v1.png illustrates material, hierarchy and consistency, not new capabilities or exact record composition. Generated generic QA search/file-download and operator-chevron decorations are excluded from implementation; overview keeps its existing single project snapshot rather than invented grouped weights. All eight actual localized routes including management remain in implementation, even where the condensed board omits one. Primary orange controls use dark text when needed for measured contrast. Existing semantic record/history layouts are retained. Approval sought for shared visual system, not generated sample dates/values/actions.

## Expanded UI approval — 2026-11-02

User: “Tasdiqlayman. Shu liquid glass dizaynini butun React interfeysiga qo‘lla, mavjud funksiyalar va ruxsatlarni saqlab qol.” This approves board-v1 shared visual system including existing record/history/form composition, not illustrative extra controls. Implementation omits generated QA search/download/operator menu and invented metrics.
