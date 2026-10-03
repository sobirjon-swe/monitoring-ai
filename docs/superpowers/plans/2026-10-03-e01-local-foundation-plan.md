# E01 Local Platform Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

Sana: 2026-10-03. Holat: T01 131880f main push; T02–T07 implementation/mustaqil review complete. T04 authorized unit commit36fc138. T08 docs/parity complete, final whole-branch review pending; qolgan commit/push/deploy ruxsati alohida.

**Goal:** Shu kompyuterda ishlaydigan, tenantlar ajratilgan xodim va loyiha boshqaruvi API poydevorini yaratish.

**Architecture:** Laravel biznes API va PostgreSQL. AI uchun ishonchli JSON kontrakt va ruxsatli kontekst mijozi yaratiladi; inference keyingi bosqichda shu kompyuterda bajariladi. Serverga ko‘chirish lokal natija tasdig‘idan keyin alohida ish.

**Tech Stack:** Laravel/PHP, PostgreSQL, PHPUnit. Aniq versiyalar T02da rasmiy moslik va mahalliy runtime tekshiruvlari bilan belgilanadi; frontend va model runtime bu reja doirasida tanlanmaydi.

**Spec:** docs/superpowers/specs/2026-10-03-e01-foundation-design.md

## Global Constraints

- AI: faqat lokal; tashqi tayyor AI APIlari va cloud fallback taqiqlangan.
- Tenant konteksti yo‘q bo‘lsa kirish rad etiladi; tenant_id/project_id ishonchli autentifikatsiya kontekstidan olinadi.
- PostgreSQL runtime roli himoyalangan jadvallar egasi, SUPERUSER yoki BYPASSRLS bo‘lmaydi.
- GitHub remote: https://github.com/sobirjon-swe/monitoring-ai.git. Boshlang‘ich hujjatlar main; mahsulot kodi monitoring-platform-foundation task branchida. Force push yo‘q.
- Source integratsiyalari, inference, UI, pul taqsimoti va server deploy bu E01 rejasiga kirmaydi.
- Har behavioral taskda failing test → minimal implementatsiya → passing test → mutaxassis review. Commit foydalanuvchi ruxsati doirasida; keyingi yangi unitlar uchun AGENTS.md qoidasiga amal qilinadi.
- Mahsulot implementatsiyasidan oldin ushbu yozma reja ko‘rib chiqiladi. Foydalanuvchining push topshirig‘i T01ni commit/push qilishga ruxsat beradi.

## Review Focus

1. Tenant konteksti yo‘q yoki oldingi so‘rovdan qolgan — T03 no-context va connection-reset testlari.
2. O‘tmishdagi rol joriy kirishni qaytarishi — T05 historical-role va revoked-access testlari.
3. Bir xil tashqi ID boshqa manbada yoki email to‘qnashuvi — T06 identity-collision testlari.
4. Parallel tahrir yoki rollback — T04/T05 optimistic revision va atomic audit testlari.
5. Tahlil ishida tenant/artefakt almashtirish — T07 scope-substitution testlari.

## Files and boundaries

- Root README.md, .gitignore, docs/: reja, spetsifikatsiya va operatsion yo‘riqnomalar.
- backend/: Laravel ilovasi; app/Domain/Organization, Projects, Identity, Access, Audit.
- backend/app/Http/Middleware/RequireTenantContext.php: request konteksti.
- backend/app/Support/TenantContext.php: transaction-scope tenant binding.
- backend/database/migrations/: jadval boshiga migratsiya; tenant invariantlari va RLS.
- backend/tests/Feature/: izolatsiyalangan PostgreSQL API/DB testlari.
- contracts/analysis-context-v1.schema.json: AI doirasi kontrakti.
- backend/app/Domain/Analysis/AuthorizedContextClient.php: fixture asosidagi ruxsatli kontekst olish.
- docs/api/, docs/data/, docs/operations/: OpenAPI, ma’lumotlar lug‘ati, ishga tushirish tartibi.

## T01 Repository va kuzatiladigan boshlang‘ich reja

Files: README.md, .gitignore, AGENTS.md, docs/README.md, requirements copy, design/image, this plan.
Interfaces: mavjud hujjatlardan Git boshlang‘ich commit va origin/main tracking yaratadi.

- [x] Fayllarni, source nusxasi SHA256ni va credential/generatsiya qilingan fayllar yo‘qligini tekshirish.
- [x] .env, dependency kataloglari, lokal DB/model vaznlari va loglarni .gitignorega kiritish.
- [x] Bo‘sh remote asosida Git main yaratish, berilgan originni ulash.
- [x] Faqat rejalashtirilgan fayllarni stage qilish; git diff --cached --check o‘tishi.
- [x] feat: monitoring loyihasining hujjat va rejasini boshlash commitini yaratish va git push -u origin main.
- [x] git ls-remote origin refs/heads/main commitga tengligini tekshirish; status clean.

## T02 Lokal backend ishga tushirish

Files: backend/ scaffold, composer.json/lock, .env.example, tests/Feature/HealthTest.php, docs/operations/local-development.md.
Consumes: T01 repository; produces: Laravel lokal health endpoint va tekshirilgan dependency lock.

- [x] PHP kengaytmalari va rasmiy Laravel/PHP mosligini tekshirish; pinned variantni qaror reyestriga yozish.
- [x] Alohida test PostgreSQL server/role yaratish usulini belgilash; mavjud real bazani o‘zgartirmaslik.
- [x] Lokal development autentifikatsiyasi va birinchi administrator bootstrapini hujjatlashtirish; production SSO deb ko‘rsatmaslik.
- [x] Lokal autentifikatsiya va minimal scope/policy substratini tayyorlash: keyingi T04 APIlari ham login va explicit ruxsatni talab qiladi. Himoyasiz vaqtinchalik data endpointlari yaratilmaydi; T05 bu substratni loyiha rollari bilan kengaytiradi.
- [x] Scaffoldni backend/ga qo‘shish; inference uchun SDK yoki tashqi AI kaliti qo‘shmaslik.
- [x] HealthTest::test_health_returns_ok orqali GET /up => 200 va xatoda maxfiy config chiqmasligini tekshirish.
- [x] composer validate --strict va php artisan test --filter=HealthTest --colors=never; lokal serverda /upni tekshirish.
- [x] Implementation and independent review complete.
- [ ] Commit approval for this unit remains pending; no push/merge/deploy implied.

## T03 Tenant DB poydevori va RLS

Files: migrations/tenants va runtime security migrations, TenantContext.php, RequireTenantContext.php, tests/Feature/TenantIsolationTest.php.
Consumes: T02 test DB; produces: TenantContext::run(string $tenantId, Closure $work): mixed, no-context deny.

- [x] test_missing_context_is_denied, test_cross_tenant_read_and_fk_write_are_denied, test_context_resets_after_rollback testlarini yozish; imtiyozsiz runtime rolida failing holatni qayd etish.
- [x] UUID tenantlar, composite FK/index shartlari, RLS va transaction-local contextni amalga oshirish; migration admini alohida.
- [x] Runtime roli table-owner/SUPERUSER/BYPASSRLS emasligini assert qilish; pooled connection qayta ishlatilganda oldingi tenant qolmasligi.
- [x] php artisan test --filter=TenantIsolationTest --colors=never => barcha testlar pass.
- [x] Implementation and independent review complete.
- [ ] Commit approval for this unit remains pending; no push/merge/deploy implied.

## T04 Xodimlar, bo‘limlar va audit

Files: Organization/Audit domain, employees/departments/department_memberships/reporting_relationships/audit_events migrations, OrganizationTest.php, AuditTest.php.
Consumes: T03 TenantContext; produces: xodim/bo‘lim lookup va ruxsatli boshqaruv APIlari, audit writer.

- [x] test_employee_is_separate_from_login, test_department_membership_does_not_grant_project_access, test_mutation_and_audit_rollback_together, test_audit_cannot_be_overwritten testlari.
- [x] Domain modellar va /api/v1/employees, /departments endpointlarini bounded pagination bilan yaratish.
- [x] AuditWriter::record(actor, scope, action, resource, revision, reason): void; sensitive payload/secret yozmaslik.
- [x] OrganizationTest va AuditTestni PostgreSQLda o‘tkazish; ochiq doiraviy endpoint bo‘lmasligi.
- [x] Independent review and authorized T04 unit commit 36fc138 complete.

## T05 Login, loyiha a’zoligi va rol ruxsatlari

Files: Identity/Access/Projects domains; users/auth_subjects/access_grants/policy_versions/projects/project_memberships/role_assignments migrations; ProjectAccessTest.php, RoleHistoryTest.php.
Consumes: T03/T04; produces: authenticated GET /api/v1/me, project lookup, effective roles, explicit grants.

- [x] Development login usulini faqat lokal muhitga chegaralash; source issuer/subject noyobligini tekshirish.
- [x] test_revoked_membership_denies_next_read, test_past_role_does_not_restore_current_access, test_concurrent_roles_are_allowed, test_duplicate_overlapping_role_is_rejected, test_stale_revision_returns_409 testlarini yozish.
- [x] [valid_from, valid_to) davrlari, ko‘p rollar, scope policies va optimistik reviziyalarni yaratish; joriy/historical tekshiruvni alohida qilish.
- [x] Oddiy CRUD orqali access_grant/self-admin escalation rad etilishini test qilish; bootstrap foydalanuvchisi faqat explicit lokal setup orqali.
- [x] Implementation and independent review complete.
- [ ] Commit approval for this unit remains pending; no push/merge/deploy implied.

## T06 Tashqi identifikatorlarni tasdiqlangan moslashtirish

Files: Identity/source_instances, identities, identity_mappings va histories; IdentityMappingTest.php.
Consumes: T04 employees/T05 authorization; produces: approved identity mapping API, source_instance-scoped uniqueness.

- [x] test_same_email_does_not_merge_employees, test_same_external_id_on_different_source_is_distinct, test_unapproved_mapping_has_no_attribution, test_stale_mapping_update_returns_409 testlari.
- [x] Immutable external ID, explicit reviewer/reason/effective dates va revision historyni saqlash.
- [x] Mapping APIlarini permissions/audit bilan bog‘lash; GitLabga so‘rov hali yubormaslik.
- [x] Implementation and independent review complete.
- [ ] Commit approval for this unit remains pending; no push/merge/deploy implied.

## T07 AI ishonchli kontekst kontrakti

Files: contracts/analysis-context-v1.schema.json, contracts/analysis-output-v1.schema.json, AuthorizedContextClient.php, AuthorizedContextTest.php, synthetic fixtures.
Consumes: T03/T05 access policies; produces: getSnapshot(trustedScope, artifactId): AuthorizedSnapshot; tashqi inference yo‘q.

- [x] test_tenant_substitution_is_rejected, test_unapproved_artifact_is_rejected, test_unknown_schema_version_is_rejected, test_output_contract_rejects_invalid_evidence, test_revoked_access_blocks_context_retrieval, test_allowed_snapshot_hash_is_stable testlari.
- [x] Versiyalangan trusted scope va snapshot kontraktini belgilash; client faqat autorizatsiyalangan sun’iy fixturelarni oladi.
- [x] Chiqish sxemasida finding_id, analysis_run_id, category, evidence_ids, coverage, confidence/reasons va review_state belgilanadi; model chaqirmasdan sun’iy natijani schema/version va authorized evidence bo‘yicha tekshirish.
- [x] Model endpointi, embedding hisoblash yoki hosted SDK qo‘shmaslik; hozircha real artefakt retrieval yo‘qligini hujjatlashtirish.
- [x] Implementation and independent review complete.
- [ ] Commit approval for this unit remains pending; no push/merge/deploy implied.

## T08 E01 lokal poydevor qabul sinovi va topshirish

Files: docs/api/openapi.yaml, docs/data/e01-dictionary.md, docs/operations/local-development.md, docs/decisions.md, docs/acceptance/e01.md.
Consumes: T02–T07; produces: lokal ishlaydigan E01 poydevor qismi va tekshirish dalillari. Bu to‘liq E01-OPS yoki ishlab chiqarish/pilot tayyorligi degani emas.

- [x] OpenAPI endpointlari implementatsiyaga tengligini tekshirish; dependency/install/start/test buyruqlarini real natija bilan yozish.
- [x] Lead fresh php artisan test --colors=never, composer validate --strict, platform va Pint natijalarini qayd etish; to‘liq branch review quyidagi alohida ochiq gate.
- [x] Lokal health/login/tenant/rol scenario natijasini qayd etish; haqiqiy SSO/backup/inference tayyor deb da’vo qilmaslik.
- [x] FR-001/020/110/160/200 va AC-09ning shu bosqichda yetkazilgan yo‘llarini jadvalda dalillarga bog‘lash; RAG/exports/notifications keyingi bosqich.
- [ ] Lokal natijani foydalanuvchiga ko‘rsatish; keyingi E02 yoki lokal model vazifalari alohida reja. Serverga ko‘chirish avtomatik emas.

- [ ] Final independent whole-branch review and user handoff pending. Lead prior90/1690 and live /up200; final fix fresh97 tests/1934 assertions, Composer/Pint/platform pass on2026-10-03. Scoped final re-review remains pending.

## Keyingi kichik bosqichlar

E01-OPSning qolgan ishlari alohida kuzatiladi: haqiqiy SSO integratsiyasi, ajratilgan muhitlar, token rotation, rollback namoyishi, zaxira/tiklash siyosati va yopiq FastAPI servisining operatsion tekshiruvi. T08da bu ishlar bajarilmagan bo‘lsa ochiq qoladi; NFR-005 o‘lchangan deb ko‘rsatilmaydi.

E02: source connector reyestri → GitLab read-only client → webhook/outbox → retry/reconciliation → attendance/QA import.
E03: requirement version → criteria/task links → baselines → acceptance-weighted progress.
E04/E05: local model hardware probe/weights/runtime → diff classification → reviewed role findings.
E06/E08/E07/E09/E10: har biri alohida yozma dizayn/reja bilan; moliya avval sandbox.

## Execution handoff

T01 bajarildi. Foydalanuvchi qolgan ishlarni AGENTS.md bo‘yicha boshlashni buyurdi; T02–T08 agentlar orqali task bo‘yicha implementatsiya va mustaqil review bilan bajariladi. Yangi meaningful unitlarning commit/push ruxsati AGENTS.md tartibida so‘raladi, ishni davom ettirish uchun qayta tasdiq so‘ralmaydi.
