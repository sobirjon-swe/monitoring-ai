# Universal liquid glass implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or subagent-driven-development to execute this approved plan.

**Goal:** Apply the approved liquid glass world throughout the existing React UI without changing features or access.
**Architecture:** Universal shared shell and semantic glass tokens; existing domain components retain data, state and permission ownership.
**Tech Stack:** Existing React19/TypeScript/Vite, CSS, Vitest. No dependencies/backend changes.
**Spec:** docs/superpowers/specs/2026-11-02-universal-liquid-glass-design.md

## Constraints
User approved universal-liquid-glass-board-v1.png on2026-11-02. Generated QA search/download/operator dropdown are excluded; no new data/controls. Preserve pending/conflict/history/session/CSRF/capability behavior. Preserve earlier uncommitted people-role work. No commit/push/merge authorized. All agents gpt-6.1-sol/medium.

## Review focus
Eight long RU navigation labels; mobile375px cards and fields; capability-denied/empty states; dialog and inline history focus; opaque fallback and primary contrast.

## T1 Shared shell and tokens
- [x] Inspect current tests; add meaningful navigation/auth regression where shell behavior changes, observe failing test.
- [x] Replace three shell variants with universal glass shell in layout/WorkspaceShell.tsx and shared CSS; retain auth/project route semantics and keyboard accessibility.
- [x] Restyle overview, login/picker, shared record/queue/history/forms and people surfaces using scoped tokens. Leave API/session/domain behavior untouched. No extra mock controls.
- [x] Verify full SPA tests/typecheck/build and independent source review; fix findings.

## T2 Browser and acceptance
- [x] Render local anonymous/login and representative available authenticated screens, desktop/mobile; inspect contrast/overflow/keyboard and translated navigation. Do not silently renew expired principals.
- [x] Compare real screenshots with approved board and people reference; independent visual review. Disclose unavailable data/access prerequisites.
- [x] Update DESIGN/PRODUCT and acceptance documentation, retain screenshots. Report exact checks and remaining limits, request commit separately.

## Outcome
See docs/acceptance/universal-liquid-glass.md for exact fresh131tests, reviewed resize/hierarchy fixes, actual-vs-preview screenshot boundaries and capture limitations. User separately approved two-operator same-scope8hour access renewal. No commit/push/merge.
