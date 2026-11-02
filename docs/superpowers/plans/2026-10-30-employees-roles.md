# Employees and roles implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task after image approval.

**Goal:** Deliver project employees and dynamic administrator-owned work-role catalog pages in the approved glass/orange UI, with secure PM assignment.

**Architecture:** Add descriptive catalog and dated project bindings beside existing functional roles; preserve existing authority proofs and grants. Reuse Laravel scoped authorization/RLS and browser session infrastructure, then React typed adapters and drawers.

**Tech Stack:** Existing Laravel13/PHP8.5/PostgreSQL17 and React19/TypeScript/Vite. No new UI framework or external AI.

**Spec:** docs/superpowers/specs/2026-10-30-employees-roles-design.md

## Constraints

User confirms admin-only create, PM existing-role assignment. Glass v2b/v2 images approved by user before product code. No secrets, inferred grants, role-code rewrites, employee creation, automatic project membership, commits/push/deploy without scope-specific authorization. All agents gpt-6.1-sol/medium; no recursive delegation. Missing ui-ux-pro-max/grill-me uses the previously accepted Impeccable plus specialist discovery alternative.

## T1 — Catalog and scoped assignment foundation

- [x] Review approved images/spec and exact E01 FR-001/020/110/160/200,AC-09 requirements; map database/audit inventories.
- [x] Write failing PostgreSQL tests for tenant/admin purpose scope, catalog uniqueness/archive, composite FKs, forced RLS, overlapping period and target-membership boundaries. Observe failure.
- [x] Add additive role_catalog and project_catalog_role_assignments migration; implement dedicated domain service/policy using TrustedActor,TenantContext,AccessGateway and AuditWriter. Keep legacy role_assignments untouched.
- [x] Add new audit action/resource codes and explicit authority checks, including PM effective role + project grant and membership. Lock/recheck revoke/archive paths; use revision/idempotency to reconcile ambiguous writes.
- [x] Apply guarded migration to dedicated TEST then DEV; no reset/fresh. Run focused schema/service/security tests and independent DB/security review. Correct findings before T2.

## T2 — Browser contracts and capabilities

- [x] Write failing endpoint/session tests for strict GET/write request shapes, CSRF, admin create/PM denial, PM assignment, cross-project/tenant denial, revocation and stale versions. Observe failures.
- [x] Add dedicated BrowserPeopleRolesController and bounded domain readers; exact GET/POST/PATCH route allowlist and shared capability policy. No wrapping general admin endpoints without their security model.
- [x] Extend BrowserProjectReader capabilities/DTO, BrowserAppController route matching and typed client allowlists only for new paths. Preserve existing epoch/current-scope checks and server session behavior.
- [x] Verify read/write parity, concurrency duplicate/revoke cases, no partial writes and unchanged system roles/grants/proofs. Independent backend/API review.

## T3 — React pages and exact visual system

- [x] Read Impeccable craft floor; review approved home and new-page images. Add EmployeesWorkspace,RolesWorkspace and dedicated typed peopleRoles adapters with locale strings in existing UZ/RU/EN dictionaries.
- [x] Build real-data search/filter/table, employee assignment and catalog creation drawer, pending/error/conflict/empty states, capability navigation and route handling. Reuse neutral overview shell tokens for these pages only; preserve existing routes.
- [x] Add meaningful interaction tests for permission-visible actions, no privilege escalation, scope-change stale data, archive/error/retry, keyboard drawer behavior and localization. No static fake directory shipped.
- [x] Run targeted tests, full SPA test/typecheck/build. In actual browser verify admin creation, PM create denial and PM own-project assignment; desktop/mobile screenshots against approved images. Independent UI/accessibility review.

## T4 — Local activation and final verification

- [x] Prepare reviewed synthetic local admin/PM access using explicit bounded grants; do not widen existing demo principals silently or extend old expiry. Store access outputs ignored/protected.
- [x] Verify real HTTP/browser allow/deny matrix and persistence; record permission boundaries and remaining limitations. Re-run existing proof preservation and new-code regressions, Python only if affected.
- [x] Update PRODUCT/DESIGN, API contract and acceptance/runbook documents from implemented truth. Report exact tests, skipped prerequisites, screenshots and deviations. Ask about commit only after concrete work is reviewable unless already authorized for this scope.

## Current status

Implemented and independently reviewed. See docs/acceptance/employees-roles.md for exact tests, approved visual revision, real browser evidence and explicit limitations. Planned edit/archive/end-assignment UI remains deferred; acceptance record narrows the shipped slice.
