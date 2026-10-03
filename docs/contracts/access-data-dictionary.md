# E01 T05 access ma’lumotlar lug‘ati

FR-001/020/200, AC-09, E01-BE/DATA. Barcha yangi jadvallar tenant_id, ENABLE/FORCE RLS va transaction-local tenant siyosatini ishlatadi; runtime owner/BYPASSRLS emas.

| Jadval | Maqsad va invariant |
| --- | --- |
| users | Login account UUID, tenant, display_name, active/inactive, revision; optional employee composite FK. Xodimdan alohida, email/name merge yo‘q. |
| auth_subjects | User composite FK; tenant/issuer/subject unique; revoked_at. Lokal issuer local-development. |
| auth_tokens | Subject composite FK, token UUID, SHA256 secret_hash, expires_at/revoked_at. Raw token saqlanmaydi. |
| policy_versions | Tenant/version unique, active/retired. Grant shu versiyaga composite FK orqali bog‘lanadi. |
| access_grants | User/policy/project/department composite FKlarga ega. Purpose, tenant/project/department scope_type, valid_from/to, revoked_at, revision. Scope check aynan bitta doira yoki tenant-wide NULL scopes talab qiladi. |
| projects | Tenant/id unique; name, timezone=Asia/Tashkent, optimistic revision. |
| project_memberships | Project/employee composite FK; [valid_from,valid_to), revision. Joriy membership har project accessda tekshiriladi. |
| role_assignments | Project/employee composite FK; allowlisted business role, [valid_from,valid_to), revision. Tenant/project/employee/role + tstzrange exclusion bir xil overlapni concurrent writerlar uchun ham rad etadi. |
| audit_events amendment | Nullable project_id tenant/project composite FK; T05 amallari actor_idga autentifikatsiyalangan user UUID beradi. Legacy T04 actor_id uchun blanket users FK qo‘shilmagan. |

Policy versiya holati va explicit grantlar vakolat beradi; business role o‘zi grant yaratmaydi. Tarixiy rolesAt hodisa vaqtini oladi, HTTP kirish esa so‘rov vaqtidagi grant/membership bilan tekshiriladi. T05 joriy caller status va tenantni qayta tekshiruvchi AccessGateway interfeysini saqlaydi; xizmatlar trusted orchestration va tenant kontekstini talab qiladi. Auditga sirlar/payload emas, ID va allowlisted kodlar kiradi.

Timestamp precision: PostgreSQL timestamptz(6), UTC DateTime bindings and microsecond-aware comparisons preserve up to six fractional digits. [from,to) boundaries apply precisely to mapping/history, roles/memberships/reporting, grant/token expiry and audit instants. Incremental000006 was applied to dev/test; previously rounded/truncated timestamps cannot be recovered. Its down migration refuses lossy rounding.
