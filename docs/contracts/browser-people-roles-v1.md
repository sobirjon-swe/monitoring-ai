# Browser people and roles contract

All paths below start /browser/v1 and require the existing authenticated cookie session; writes require CSRF. Strict body allowlists and current authority apply. Pages are25 records with bounded search and actor/tenant/project/filter-bound cursors.

- GET projects/{project}/employees: search,role_id,cursor. Returns items(id,display_name,membership,roles,system_roles), next_cursor,visible_record_count.
- GET projects/{project}/role-catalog: search,status(active|archived|all),cursor. Returns items(id,name,description,status,revision),next_cursor,visible_record_count. Catalog definitions are organization-wide; project route remains access-scoped.
- POST role-catalog: name,description(optional/null/blank),request_id(UUID).201 created/200 same request replay.
- PATCH role-catalog/{id}: name,description,status,expected_revision,request_id. Admin only; stale revision409.
- POST projects/{project}/catalog-role-assignments: employee_id,role_catalog_id,expected_role_revision,request_id,valid_from(optional),valid_to(optional).201 created/200 replay. Half-open dates normalized UTC; membership coverage required. Duplicate overlap/stale/archive409; unauthorized403.

Catalog authority is tenant role_catalog.manage, never existing roles.manage. Assignment requires project.read, exact project employees.read/roles.manage, current actor membership, and functional pm or tenant catalog-admin authority. Capabilities are hints only; write service rechecks under locks. See ../acceptance/employees-roles.md for activation and limitations.
