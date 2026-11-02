# Employees and roles acceptance — 2026-10-30

Approved images: employees-glass-v2b.png and roles-glass-v2.png, user confirmation before implementation. Earlier neutral-dark/sidebar proposal is superseded for these pages only. Implementation branch codex/employee-role-management; no commit, push or merge performed for this slice.

## Verified

- Full SPA:23 files,129 tests passed; TypeScript and production build passed. Existing ReUI compiler directives produce two nonfatal warnings.
- PostgreSQL/backend selected regression:154 tests,3833 assertions passed. Includes39 people/browser focused tests and three real concurrent-process cases: duplicate assignment, grant revocation while waiting, archive while waiting. Counts overlap; do not add them. Full platform suite was not rerun for this slice.
- Additive migrations054/055 applied to dedicated TEST and DEV; forced tenant RLS and nonowner runtime privilege checks included. No reset/fresh or legacy functional-role rewrite.
- Real browser: administrator created Release Engineer; PM has no create button and assigned it to SYNTHETIC John. Latest mobile build assigned DevOps Engineer to SYNTHETIC Ivan; refreshed row persisted and focus returned to main-content.
-375px viewport: document scroll width360px, table302px; no page overflow. Both directory/catalog use mobile cards. UZ/RU/EN labels verified. Independent security/source review and screenshot visual review closed without outstanding material findings.
- Browser screenshots in ../design-mockups/people-roles-running/: employees-final-uz.png, roles-final-uz.png, admin-roles-uz.png, pm-assignment-uz.png, mobile-employees-uz.png, mobile-assignment-uz.png. All records are synthetic.

## Boundaries and limitations

Tenant-only role_catalog.manage creates/edits/archives definitions. Assignment requires current project access, exact project employees.read/roles.manage grants, actor membership and functional PM responsibility; explicit catalog admin only bypasses PM responsibility. Target membership must cover the entire period. Every mutation rechecks authorization and role revision under locks, records bounded audit metadata, and binds idempotency to actor/project/operation/payload. Work-role labels do not create membership, access grants or eligibility for existing QA/BA workflows.

Catalog edit/archive API exists; corresponding UI and ending/removing assignments are not delivered. Ambiguous retries retain request UUID/payload only while the page remains mounted; no durable browser outbox across reload. Employee creation and automatic project membership are outside scope. No live source integration, AI inference or production activation is claimed.

## Local access

Open http://127.0.0.1:18080/app/employees or /app/roles. Protected ignored .local/people-roles/access-v1.json contains two NEW bounded synthetic admin/PM operators (expiry2026-10-30 23:36 Asia/Tashkent). Sign out old persona and sign in with the corresponding development token, then select the fictional F01 project. Existing principals/grants were not expanded. Operators, membership and functional PM responsibility are audited. Do not commit/share tokens. Startup does not automatically renew permissions. Expired access requires deliberate local provisioning under the same scoped policy.
