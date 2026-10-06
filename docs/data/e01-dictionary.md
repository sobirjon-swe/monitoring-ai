# E01 lokal ma’lumotlar lug‘ati

2026-10-03: T03–T07 implementatsiya va mustaqil reviewdan o‘tgan. Quyidagi jadvallar yetkazilgan backend poydevoridir; barcha spetsifikatsiya obyektlari yoki ishlab chiqarish modeli tayyor deb ko‘rsatilmaydi.

| Guruh | Jadvallar / shartnoma | Batafsil manba |
| --- | --- | --- |
| Tenant | tenants: UUID/name/active-inactive/UTC timestamps; RLS id=transaction-local tenant. | [PostgreSQL operatsiyalari](../operations/postgresql-local.md) |
| Tashkilot | employees, departments, department_memberships, reporting_relationships, audit_events. | [Organization/audit](../contracts/organization-data-dictionary.md) |
| Login/ruxsat/loyiha | users, auth_subjects, auth_tokens, policy_versions, access_grants, projects, project_memberships, role_assignments. | [Access/project](../contracts/access-data-dictionary.md) |
| Tashqi identity | source_instances, identities, identity_mappings, identity_mapping_histories. | [Identity](../contracts/identity-data-dictionary.md) |
| Sun’iy tahlil chegarasi | TrustedAnalysisScope, AuthorizedSnapshot, ichki empty-default SyntheticArtifactRegistry va analysis-context/output.v1 sxemalari; DB artifact yoki analysis jadvallari yo‘q. | [Analysis](../contracts/analysis-data-dictionary.md) |

E02 davom etayotgan alohida modul: [lokal ingestion ma’lumotlar lug‘ati](../contracts/ingestion-data-dictionary.md); implementatsiya va tekshiruv chegaralari o‘sha hujjatda ko‘rsatilgan.

Ichki kalitlar UUID; tenant scoped parent-child aloqalar composite FKlarga ega. Project scoped yozuvlar project_idni ham saqlaydi. Project/department membership, reporting relationship, business role va explicit access grant mustaqil; birini boshqasidan taxmin qilmaymiz. Tarixiy davrlar [valid_from,valid_to), PostgreSQL sessiyalari UTC, loyiha displey default Asia/Tashkent.

Runtime monitoring_ai_app himoyalangan jadvallarning egasi, SUPERUSER yoki BYPASSRLS emas. Barcha scoped jadvallarda ENABLE/FORCE RLS; app.tenant_id faqat transaction-local, TenantContext savepoint/commit/rollbackdan keyin scope qiymatini tiklaydi. Kontekst yo‘q bo‘lsa scoped read bo‘sh, write rad etiladi. migrator→owner ulanishi runtime ulanishidan alohida; migrations ledger runtime uchun yopiq.

Audit mutatsiya bilan atomik; ID/reviziya/actor/action/reason_code/correlation/UTC vaqtni yozadi. Xom kod, prompt, email, attendance va moliyaviy miqdor yo‘q. Audit hamda identity history UPDATE/DELETEga yopiq; history INSERT faqat qat’iy SECURITY DEFINER trigger, pg_catalog search_path/qualified objects va FORCE RLS bilan. Runtime to‘g‘ridan-to‘g‘ri history yozolmaydi. Bu DB/application immutability; tashqi tamper-proof archive, signed/hash-chain audit va retention/deletion rejasi hali joriy etilmagan.

Employee/login alohida. Manba instansi/external_user_id immutable/noyob; email/ism hech kimni yashirin merge qilmaydi. Candidate mapping attribution bermaydi; approved davrlar bir identity uchun overlap qilmaydi. Reviewer trusted actor, sabab va eski reviziyalar saqlanadi. Stale optimistic update 409; keyingi analysis pin qilingan snapshot/reviziya bilan ishlashi kerak.

E02 kanonik events/outbox/artifacts, E03 requirement/baseline/criteria, Python jobs/local models, RAG/vectors, forecasts/reports va E10 moliya bu dictionarydagi tayyor jadvallar emas. Relationship cycle va barcha business-overlap qoidalari, historical export va retention siyosati keyingi tegishli modul ishidir. NFR tezlik/backup yoki barcha AC-09 delivery yo‘llari bajarilgan deb ko‘rsatilmaydi.

Timestamp precision: PostgreSQL timestamptz(6), UTC DateTime bindings and microsecond-aware comparisons preserve up to six fractional digits. [from,to) boundaries apply precisely to mapping/history, roles/memberships/reporting, grant/token expiry and audit instants. Incremental000006 was applied to dev/test; previously rounded/truncated timestamps cannot be recovered. Its down migration refuses lossy rounding.
