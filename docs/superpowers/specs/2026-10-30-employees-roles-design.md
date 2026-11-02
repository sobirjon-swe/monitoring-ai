# Employees and role catalog — proposed implementation design

Latest visual proposal,2026-10-30: user requested the supplied SEDELA glass/orange reference. Review candidates are employees-glass-v2b.png and roles-glass-v2.png, documented in docs/design-mockups/employees-roles-glass-v2.md. They supersede earlier neutral-dark page concepts for visual review; the permission/data model below is unchanged. Current shipping React design remains unchanged; image approval is pending.

Status: investigation and new-page mockups prepared; image approval pending. User requested matching the approved home dark composition and confirmed administrator-only role creation, project-manager assignment of existing roles. This document does not claim implemented screens or capabilities. Branch: codex/employee-role-management, base main9f36ef8.

## Confirmed product intent

IT-team MVP first. Create an extensible catalog of work roles, not another fixed six-role menu. Administrator creates/edits/archives catalog entries. PM assigns active catalog roles to current employees inside their own projects. No implicit employee invitation, project membership creation, system permission grant, AI inference activation or employee ranking. Existing display_name is the employee label; no fabricated email field. UZ/RU/EN and the existing browser cookie/CSRF/session-epoch fences remain.

## Existing-code findings

`ProjectController` roles endpoints and `ProjectService::assignRole` manage existing functional role assignments, not definitions. The six constrained codes developer/qa/ba/pm/department_head/reviewer are pinned in historical QA/BA/management proofs. Do not rename them, broaden the constraint or replace their IDs/periods. Existing roles.manage can be project-scoped and does not independently establish PM eligibility. An administrator is an explicit tenant authority, not a currently implemented literal admin role.

## Chosen model

Add tenant-scoped `role_catalog` (id,tenant_id,key,name,description,status,revision,timestamps). Immutable key; per-tenant normalized-name/key uniqueness. Archive instead of delete; assigned historical names/definition revisions remain attributable. Add `project_catalog_role_assignments` (id,tenant_id,project_id,employee_id,role_catalog_id,definition_revision,valid_from,valid_to,revision) with half-open periods and a composite exclusion preventing duplicate overlapping assignment of the same role. Require composite tenant FKs and FORCE RLS; runtime remains nonowner/nonbypass. Catalog roles describe work responsibilities and never create access_grants or functional role_assignments. Existing functional roles remain separately visible in detail when already authorized; assigning a custom role named Admin/PM/Reviewer does not change functional authority.

Seed recognizable catalog definitions explicitly/idempotently. Any migration from existing roles is descriptive only and must retain source IDs/periods; no historical proof rewriting. Prefer no automatic assignment backfill until a reviewed mapping is recorded; show the old functional responsibility separately so an empty new catalog assignment is not mistaken for lack of existing responsibility.

## Authority and API boundary

- Tenant-only `role_catalog.manage` establishes catalog-administrator authority; a project-scoped grant of that purpose is refused. `role_catalog.read` governs tenant catalog viewing. Existing roles.manage is not catalog-create authority.
- Project `employees.read` and current actor project membership allow listing current project members. PM catalog selection uses project roles.read. Employee collection is project-scoped by default; tenant employee CRUD remains organization.read/manage and is outside this UI slice.
- Assignment/end requires project roles.manage AND current functional PM responsibility AND current actor membership. Explicit tenant catalog-administrator override is allowed only with project/employee read and assignment authority, not merely catalog editing authority. Target must already be an effective project member for the full proposed assignment period. A role assignment never creates membership.
- Read capabilities are hints derived from the same effective policy as mutation; backend rechecks grant, functional PM responsibility, actor/target membership, catalog active state, project and tenant under locks inside the transaction. Recheck authority at write boundary; expire/revoke fail closed. Scope changes/unmounts hide stale reads/writes. No update after an ambiguous timeout without reconciliation/idempotency lookup.
- Proposed browser endpoints: GET projects/{project}/employees; GET projects/{project}/role-catalog; POST/PATCH projects/{project}/catalog-role-assignments; GET/POST/PATCH role-catalog. Strict request shapes, bounded cursor pagination/search, UUID validation, UTC storage with Tashkent display, exact revision conflicts. Current read-only API client allowlists must be extended explicitly for these routes/methods.
- Extend access-purpose and audit allowlists, project capability DTOs, RLS table inventory and migration guard inventories. Audit only bounded action/resource/reason metadata, never free-text descriptions or credentials.

## UI composition and states

Reuse approved home: #101010 sidebar, #151515 background, #1b1b1b panels, #303030 boundaries, #f1f1f1 primary / #aaaaaa secondary text; 256px sidebar,32px page padding,16/24px gaps,14px panels and normal500 headings. Only existing routes plus new Employees/Roles links are shown when authorized.

Employees: project heading, search and role filter, quiet directory table and assignment drawer. PM has no role-create or employee-create button. Catalog: search/status filter, definition table and admin-only creation drawer. Name/description and active/archive status; no system-permission matrix. Count is actual authorized data, never fixed6. Sample data in images is explicitly marked; shipping empty states never manufacture employees or roles.

Drawer: focus trap, Escape/Cancel, return to opener, labelled controls, pending duplicate-submit protection, inline errors preserving inputs, explicit conflict/reload handling. Clear-search action, no-results, empty project/catalog, forbidden, unavailable, session-expired and revoked-while-open states. Mobile stacked rows and full-width bounded drawer,44px controls, no viewport horizontal overflow.

## Acceptance

Admin can create custom work role; PM cannot create through UI OR raw API. PM can assign an existing active role only inside their own effective project scope. Cross-tenant IDs, other-project employees, expired PM/membership/grants, archived roles, period gaps, overlapping duplicates and stale revisions reject with no partial writes. No extra grants or functional roles after catalog creation/assignment. Existing proof IDs/revisions and historic QA/BA/management outcomes remain unchanged. Concurrent revoke/archive/assign and response lost/retry are tested. Real DB RLS/FK behavior plus browser CSRF/route fences and keyboard/mobile/localization review required. Implementation will be checked against the new approved mockups, not the rejected light v2.

## Approval boundary

Repository AGENTS.md requires design images and explicit approval before product implementation, and permits reuse only for the same design and scope. Home approval was home-only; these new pages and catalog mutation are a new scope. Present concrete images and this design before React/API/schema changes. No new permission policy decision is invented: administrator creates, PM assigns existing roles within own project, role labels do not grant system access.

## Approved implementation revision, 2026-10-30

User confirmed glass employees-v2b and roles-v2 images; these supersede the sidebar composition above for the two new pages. Delivered API paths and scope are documented in ../../contracts/browser-people-roles-v1.md. In particular no PATCH/end-assignment browser UI ships in this slice; catalog PATCH API exists. Actual permission policy, tests and remaining limitations: ../../acceptance/employees-roles.md.
