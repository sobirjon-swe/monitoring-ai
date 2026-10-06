# E03 — versiyalangan talab va dalilli qabul dizayni

Sana: 2026-10-06. Holat: yozma dizayn foydalanuvchining Maqullayman, ishni boshla javobi bilan tasdiqlangan; implementation plan review uchun tayyorlanmoqda, mahsulot kodi boshlanmagan. Foydalanuvchi E03 v1 diagrammasi va brief doirasini “Maqullayman” deb tasdiqladi. Yozma dizayn tasdig‘i qayd etildi; hali taqdim qilinmagan implementation plan alohida review qilinadi.

## Maqsad, asos va chegara

Tasdiqlangan [diagramma v1](../../design-mockups/e03-requirements-evidence-flow-v1.png) va [brief](../../design-mockups/e03-requirements-evidence-flow-v1.md) asosida mavjud Laravel/PostgreSQL ichida synthetic/manual E03 poydevori quriladi. Natija: talab va mezon versiyalari, scope baseline, vazifa/dependency, explicit DoD, tekshirilgan dalil va inson qabuli orqali aniq progress. E02 asos commiti `a9ad71e`; task branch `requirements-evidence-baselines`.

Repository spetsifikatsiyasi FR-030/080/150, §12 provenance/invalidation, E03-BE/DATA/QA va AC-05/08/09 bu birlikka bog‘lanadi. AC-06 uchun dependency cycle/missing-input dalili beriladi; E07 simulation/forecast bajarilmaydi. E03-AI kontekst grafigining inference/impact proposals qismi, live adapter/backfill, object store, UI, moliya va production SSO ochiq qoladi. Kanban faqat read-only; avtomatik import yoki manbaga write yo‘q. Product AI lokal-only talabi saqlanadi, bu birlik inference ishlatmaydi.

Spetsifikatsiyadagi E03-DATA FR-051 citationi attendance/activity-minute chekloviga tegishli; selective invalidationning haqiqiy talabi §12/AC-08. Citation xatosi yangi FR sifatida talqin qilinmaydi. `grill-me` katalogda mavjud emas; mavjud talablar, specialist tahlili va ushbu explicit qarorlar bilan requirements aniqlashtirildi.

## Ishonch va vakolat

TrustedActor, DatabaseAccessGateway, TenantContext, bounded AuditWriter va dedicated PostgreSQL qayta ishlatiladi. Har scoped record tenant_id/project_id bilan composite FK/uniquenessga ega; FORCE RLS, nonowner/nonSUPERUSER/nonBYPASSRLS runtime va transaction-local context saqlanadi.

Taklif etilgan purpose nomlari:

| Purpose | Vazifa |
| --- | --- |
| `requirements.read` | Talab, mezon, baseline, policy va task tarixini scoped o‘qish |
| `requirements.manage` | Draft/task/dependency takliflarini yaratish va revision bilan tahrirlash |
| `requirements.approve` | Talab/criterion snapshotini explicit tasdiqlash |
| `policy.approve` | Fictional local DoD versiyasini tasdiqlash |
| `baseline.approve` | Tasdiqlangan scope vaznlari/baseline almashuvini tasdiqlash |
| `evidence.review` | Evidence link va synthetic QA natijasini tekshirish/qaror berish |
| `acceptance.review` | Pinned criterion/policy/evidence bo‘yicha inson qabuli |
| `progress.read` | Scoped denominator, numerator va coverage/statusni ko‘rish |

Barchasi explicit project grant va current project membership talab qiladi; source connector/manage yoki project/manage avtomatik vakolat bermaydi. Evidence review uchun E02 receipt metadata/allowlisted locatorni olishda `ingestion.read` ham tekshiriladi; bu umumiy raw receipt HTTP endpointini yaratmaydi. Progress outputdalil mazmunini yoki employee ma’lumotlarini qaytarmaydi. Detailed locator/source retrieval evidence.review + ingestion.read bilan qayta tekshiriladi. Grant/policy/membership revocation keyingi read/write/qaror oldidan va natija deliveryda tekshiriladi. Fictional test fixture grantlari production role/pilot siyosati emas.

## Ma’lumotlar modeli va versiya qoidalari

Aniq migration/table/API nomlari keyingi rejada belgilanadi; quyidagi domain obyektlari binding va invariantlarni belgilaydi.

- **Requirement identity va mutable draft:** owner, stakeholder, business outcome, NFR constraints, dependencies, readiness va expected_revision. Draft tahriri compare-and-swap bilan, stale revision 409. Draft yaratish yoki tahrirlash current acceptancega ta’sir qilmaydi.
- **Approved requirement/criterion versions:** o‘zgarmas content/hash, stable requirement/criterion identity, monotonic version, tasdiqlovchi, sabab va server UTC microsecond vaqti. Approved snapshot UPDATE/DELETE taqiqlanadi. Criterion replacement bir xil stable criterion identityning yangi versiyasi sifatida bog‘lanadi; eski versiya saqlanadi. Approval readiness required fields/traceabilityni tekshiradi, talabning mazmunan yaxshi ekanini yoki AI qualityni isbotlamaydi.
- **DoD policy versions:** explicit immutable fictional policy, kamida mandatory QA va human acceptance talablari. Hidden default yoki avtomatik kompaniya policy yo‘q. Approved policy yo‘q bo‘lsa acceptance/progress unavailable. Production MR/CI/QA provider assertions ushbu synthetic policy bilan tenglashtirilmaydi.
- **Baseline va items:** har item stable criterion identityning bitta approved versioniga bog‘lanadi, policy versionini pin qiladi va oldindan tasdiqlangan positive integer scope weight saqlaydi. Fractional weights bu birlikda qabul qilinmaydi; bu production weighting policy tanlovi emas. Checked integer sums/exact ratio ishlatiladi, rounding faqat displayda. Negative, zero item weight, duplicate criterion identity va unapproved version rad etiladi. Empty approved scope uchun progress unavailable; accepted/approved denominator alohida chiqadi.
- **Tasks va dependencies:** scoped identity/version, requirement/criterion links, assignee, estimate basis va remaining-effort coverage, expected tests, recorded status. Dependency edge self/cross-project linkka yo‘l qo‘ymaydi. Cycle aniqlanadi va schedule coverage limited deb ko‘rsatiladi; task holati progressni bevosita oshirmaydi. Source Done, implemented-awaiting-verification, in-progress, blocked va unstarted qabuldan alohida saqlanadi.
- **Evidence link va QA observations:** immutable receipt/artifact binding, hash, exact allowlisted locator, source version, retrieval instant va effective relationship. Proposed/rejected/reviewed state transition append-only review qarori bilan saqlanadi. Link correction yangi record/review yaratadi, original receiptga tegmaydi. QA observation explicit synthetic passed/failed/missing va criterion/test versionini pin qiladi; latest event time/hash lineage bo‘lmasa applicability unknown.
- **Acceptance decision va applicability:** append-only inson actor/sabab/vaqt, criterion version, baseline/policy version, reviewed link IDs va QA snapshotga pin qilingan qaror. Current applicability alohida valid/stale/revoked projection; tarixiy inson qarori ustiga yozilmaydi.
- **Invalidation va durable work:** accepted change bilan bir transactionda bounded invalidation/outbox record. Immutable source IDs + reason/version idempotency key; retry/fencing bilan affected applicability qayta hisoblanadi. Pending recomputation paytida tegishli numerator konservativ unavailable/stale, old cached favorable natija yetkazilmaydi. E02 raw source outbox qayta maqsadlashtirilmaydi; E03 processing purpose/handler alohida versioned internal boundary bo‘ladi.

## Approval va current baseline tranzaksiyasi

Baseline approval project-level row/advisory lock va expected_current_baseline_id bilan serializatsiya qilinadi. Approved requirement/policy versiyalarigina item sifatida olinadi. Server approval vaqti effective time bo‘ladi; retrospective/future scheduling bu birinchi birlikda yo‘q. Late source event yoki draft eski current pointerga qaytarmaydi.

Baseline almashtirilganda bitta transaction: eski baseline progress snapshotini uning o‘sha vaqtdagi approved scope va qarorlari bilan muzlatish → yangi approved baseline/items/hash → current pointer CAS → affected applicabilityni stale/coverage-pending qilish → invalidation outbox/audit. Ikki concurrent approvaldan faqat expected pointeri mos biri succeeds; boshqa409. Old baseline yoki weightlar tahrirlanmaydi. Closure immutable scope/progress snapshot yaratadi; qayta ochish explicit yangi baseline/state event bo‘ladi, eski closure o‘zgarmaydi.

Policy replacement ham alohida explicit approval/effective activation bilan bog‘liq current applicabilityni stale qiladi. Policy draft creation yoki bir loyiha uchun boshqa ishlatilmayotgan policy approvalidan unrelated acceptance stale bo‘lmaydi.

## Dalil va inson qabuli

Birinchi source locator grammar E02 synthetic ticket receiptining allowlisted JSON fieldlari bilan chegaralanadi. Schema-validated canonical snapshot/hash va full tenant/project/connector/object binding tekshiriladi; arbitrary URL fetch, arbitrary JSON path, source metadata/credentials yoki code retrieval yo‘q. Locatordagi ko‘rsatma tool/privilege yaratmaydi. Artifact/MR/code line locatorlari live integration bilan keyin qo‘shiladi, hozir implemented deb berilmaydi.

Acceptance bitta transactionda joriy authorized actor, expected baseline/policy/criterion versiya, reviewed nonstale evidence va barcha mandatory synthetic QA passingligini qayta tekshiradi. Proposed link, failed/missing QA, stale hash/version yoki pending invalidation qabulni oshirmaydi. Failed/missing required QA unresolved criterion uchun zero accepted weight; approved project policy yo‘qligi esa project acceptance/progress unavailable. Qisman credit faqat oldindan tasdiqlangan criterion decomposition orqali; arbitrary percentage qabul qilinmaydi.

Acceptance bilan bir paytdagi baseline/policy activation, effective criterion replacement, evidence correction/revocation va QA observation update bitta project coordination lock yordamida serializatsiya qilinadi yoki ekvivalent atomic input-generation CAS bilan tekshiriladi. Acceptance commit qilguncha barcha pinned generationlar bir xil bo‘lishi shart; superseded versionga yangi current acceptance yozilmaydi. Baseline approval uchun ishlatiladigan versiyalar approved va o‘sha vaqtda effective bo‘lishi kerak; eski superseded rule joriy baselinega yashirin qayta kiritilmaydi. Permission yoki input o‘zgarsa qayta qabul talab qilinadi. E03 decisions source release/QA system o‘rnini bosmaydi.

## Selective invalidation va tarix

Approved/effective criterion yoki policy replacement, explicit evidence correction/revocation va authorized input/hash change invalidation triggerlari. Author revoke yoki source access change authorized retrieval/deliveryni darhol deny qiladi; background jobni kutib privilege saqlanmaydi.

Dependency graph input version → reviewed link → acceptance applicability edgesdan iborat. Faqat transitively affected IDs stale bo‘ladi; unrelated acceptance valid qoladi. Idempotent jobs current generation/hashni tekshiradi; eski job yangi generationni qayta valid qilolmaydi. Human approval oldingi actor/ruxsatini tiklamaydi. Revocation sababli umumiy progress uchun yetarli authorized input yo‘q bo‘lsa coverage incomplete/unavailable; yashirin dalil tafsiloti chiqmaydi.

Recompute worker faqat dependency/coverage projectionni qayta hisoblaydi; o‘zgargan criterion/policy/evidence/QA generationiga eski human acceptance qarorini valid qilib qaytara olmaydi. Replacement QA passed bo‘lsa ham yangi pinned inputlar uchun vakolatli insonning yangi acceptance qarori talab qilinadi. Unchanged inputs bo‘yicha idempotent verification coverage-pendingni yechishi mumkin, lekin tarixiy yoki stale inson qarorini o‘zgartirmaydi.

Old baseline closure/replacement snapshots tarixiy denominator/numerator/as_of/versionlarni saqlaydi. Ular current-valid progress bilan aralashmaydi; historical view ham current access checksga bo‘ysunadi. E06/E08 report engine bu birlikda yo‘q, lekin kelajak report pinned historical snapshotni qayta yozmasdan ishlata oladi.

## API va operatsion yo‘nalish

Faqat mavjud local bearer protected Laravel API. Bounded list/cursor, revision/hash-aware mutation, sanitized error/request ID, generic validation va fixed audit reasons. GET/HEAD mutatsiya qilmaydi. New internal schemas/OpenAPI/data dictionary izchil versiyalanadi. Aniq route/limit/commandlar yozma implementation plan bilan ko‘rib chiqiladi; yangi dependency yoki frontend texnologiyasi talab qilinmaydi.

Migrationlar incremental, dedicated PostgreSQL dev/test va existing safe app:migrate-local. No SQLite/reset/migrate:fresh. Approved snapshots runtime UPDATE/DELETEdan himoyalanadi; mutable projections faqat generation-aware updates. Durable invalidation dispatcher local explicit operator doirasida, tenant/project/purpose va original decision/input eligibility recheck bilan; production service principal keyin alohida.

## Acceptance va tekshiruvlar

| Holat | Kutiladigan dalil |
| --- | --- |
| Approved weight100 / accepted67 | Exact67%, numerator/denominator va baseline/policy versiyasi chiqadi |
| Done + failed/missing required QA | Unresolved criterion accepted weight0, task Done alohida |
| Policy yo‘q / denominator0 | unavailable reason; favorable progress yo‘q |
| Scope100 →120 | Old snapshot denominator100, current denominator120, as_of va versions alohida |
| Reject →merge approved criterion | Bog‘liq acceptance/test applicability stale, unrelated valid, old snapshot saqlanadi |
| Draft/new unrelated policy | Current applicability o‘zgarmaydi |
| Concurrent approvals/acceptance | CAS/generation locks,409; wrong version qabul qilinmaydi |
| Acceptance va evidence/QA correction race | Bir xil coordination lock/generation CAS; changed inputdan stale qaror commit bo‘lmaydi |
| Invalidated qaror + replacement QA passed | Worker acceptance valid qilmaydi; yangi human decision kerak |
| Cycle/missing remaining effort | Input coverage limited/unknown, forecast sanasi/ehtimoli berilmaydi |
| Missing tenant/cross-scope/invalid locator/revoked grant | Deny/no write/no privileged retrieval |
| Worker crash/replay/stale generation | Durable retry, idempotent selective recompute, current generationni bosmaydi |

PostgreSQL behavior/TDD, database/security/backend independent reviews, fixed reason/secret-output checks, full existing regressions va local synthetic smoke talab qilinadi. Representative live data/AI/UAT yoki full E03/AC06 production completion da’vosi yo‘q.

## Keyingi review bosqichi

Ushbu yozma dizayn foydalanuvchi tomonidan 2026-10-06da “Maqullayman, ishni boshla” bilan tasdiqlandi. `writing-plans` orqali mustaqil review qilinadigan tasklar, aniq schema/API interfaces va red/green komandalar bilan implementation plan tayyorlandi. Yangi reja review/tasdig‘idan keyin avvalgi subagent-driven tartibda mahsulot kodi boshlanadi.

## 2026-10-06 approval and implementation status

User approved image/written design/execution plan; R1–R5 independently reviewed implementation follows that synthetic scope. R6 final whole-unit/scoped I1/I2 review and verification, ten dev incrementals000009–000014 and rollback smoke passed. Approved synthetic code unit complete, commit pending. No production policy, full E03/AC06 or commit/push/merge/deploy approval inferred.
