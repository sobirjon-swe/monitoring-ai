# Qarorlar reyestri

Yangilangan sana: 2026-10-05. Quyidagi mavjud E01 qarorlari 2026-10-03dan; yangi lokal muhit va E02 doirasi alohida qayd etilgan.

| Qaror | Holat va asos |
| --- | --- |
| AI joylashuvi | Barcha inference, embedding va reranking lokal. Tashqi AI APIlari taqiqlangan. |
| Boshlang‘ich muhit | Ilova, baza va AI hozirgi kompyuterda; natija tasdig‘idan keyin ichki serverga ko‘chiriladi. |
| E01 scope | API poydevori va ruxsatli AI kontrakt. UI, live integratsiya, inference va moliya keyingi bosqichlarda. |
| Dizayn/reja tasdig‘i | Foydalanuvchi ko‘rsatilgan dizayn/rejadan so‘ng qolgan ishlarni AGENTS.md bo‘yicha boshlashni buyurdi. |
| Backend runtime | Laravel 13.34.0, PHP 8.5.5, Composer 2.9.5, PHPUnit 12.5.37 tekshirilgan; PHP/Laravel mosligi rasmiy release jadvaliga tayanadi. composer.lock aniq dependencylarni belgilaydi. |
| Lokal PostgreSQL | 17.10; monitoring_ai_dev/test alohida bazalar. Migratsiya va runtime rollari ajratiladi. |
| Development login | Faqat lokal muhitda CLI orqali yaratiladigan subyekt va maxfiy token; production SSO bilan tenglashtirilmaydi. HTTP orqali o‘zini admin qilish yoki umumiy default parol yo‘q. |
| Frontend/UI | dashboard-ui-v1.png tasdiqlangan; mustaqil HTML/CSS/JS demo va UI review mavjud. Production texnologiyasi/login/API ochiq. Yangi rol, lifecycle, UZ/RU/EN va davriy hisobot maketlari tasdiq kutadi. |
| Task branch | monitoring-platform-foundation; E01 final review va fix re-review qabul hujjatida qayd etilgan. E02 shu davom etayotgan task branchida, o‘zgarishlar saqlanadi. |
| Commit/push | Gitda 131880f, 36fc138, E01 yakunlash e06bf29 va demo/maket 804f620 mavjudligi 2026-10-05da tekshirildi. Joriy E02 commit/push/merge/deploy avtomatik bajarilmaydi. Tarixiy push haqidagi qayd joriy remote parity tekshiruvi emas. |
| Tenant va vaqt | PostgreSQL FORCE RLS, tenant composite FK, transaction-local app.tenant_id va scope cleanup. Runtime/migration sessiyalari UTC; project timezone Asia/Tashkent. |
| Identity review | Manba instansi + o‘zgarmas tashqi ID; email/ism faqat nomzod. Authenticated reviewer, sabab, davr/reviziya va DB-trigger append-only tarix; to‘g‘ridan-to‘g‘ri runtime history INSERT rad etiladi. |
| E01-AI kontrakt | Faqat ichki synthetic registry va actual JSON Schema. Opis/json-schema 2.6.0/string 2.1.0/uri 1.1.0 lokal dependency; remote resolver/inference yo‘q. Joriy grant/membership olish va output deliveryda qayta tekshiriladi; pending-only findings. |

Manba: [Laravel releases](https://laravel.com/framework/docs/releases).

## 2026-10-05: E02 lokal boshlanish

- Foydalanuvchi E02 diagramma/brief va saqlangan implementatsiya rejasidan keyin “Ishni boshla” dedi. [Tasdiqlangan lokal slice rejasi](superpowers/plans/2026-10-05-e02-local-ingestion-plan.md): synthetic connector, bounded receipt, PostgreSQL outbox va normalized projection. Bu barcha E02 yoki E03–E10 dizaynlarini tasdiqlamaydi.
- `kanban.mo.local` task tracker sifatida faqat read-only ko‘rib chiqildi. API/protokol/auth/history/pagination noma’lum; [imkoniyat matritsasi](contracts/source-capability-matrix.md). Manbadagi hech qanday ticket/status/sozlama o‘zgartirilmaydi. Token/webhook o‘rnatish ruxsati olinmagan.
- Eski D:/Tools PHP va ignored credentiallar ushbu checkoutda yo‘q. Mos portable PHP 8.5.11 NTS rasmiy checksum bilan `.local`ga provision qilindi; Composer 2.10.2 locked dependencylarni yangilamasdan tikladi. Global PHP8.4 ishlatilmaydi.
- Mavjud PostgreSQL17 service/bazalar saqlanib, loyiha ichida alohida PostgreSQL17.10 klaster yaratildi: 127.0.0.1:55432, SCRAM; dedicated monitoring_ai_dev/test, migration/runtime rollari. Bu yangi lokal development konfiguratsiyasi; production authentication yoki server deployment qarori emas. [Joriy buyruqlar](operations/local-development.md).
- Lokal synthetic slice max64KiB envelope, max100 batch, 60s lease, max5 attempts va 5/30/120/600s retry farazlari bilan tekshiriladi. Bular ishlab chiqarish limiti/SLA/retention tasdig‘i emas.
- Mustaqil reviewerlar task va whole-unit tekshiruvlarini bajaradi. Real AI, real source import, notification va to‘liq reconciliation ushbu slice natijasi deb ko‘rsatilmaydi.

## Pilot/ishlab chiqarishdan oldin ochiq

Haqiqiy SSO, korporativ ma’lumotga kirish siyosati DEC-01/03/05, zaxira/tiklash, token rotation, muhitlar ajratilishi va server operatsiyalari alohida qabul qilinadi. Lokal PostgreSQL klasterining mavjud umumiy autentifikatsiya siyosati avtomatik o‘zgartirilmaydi; alohida bazalar yaratish butun klaster xavfsizligi tekshiruvi degani emas.

GPU: RTX 3060 Laptop 6 GiB VRAM aniqlangan; RAM va modelning latency/sifati hali o‘lchanmagan. Lokal model vaznlari/runtime tanlanmagan va inference ishga tushmagan. Tashqi providerga almashtirish ruxsat etilmaydi.

2026-10-03 tarixiy klaster uchun loopback pg_hba trust va boshqa DB PUBLIC CONNECT cheklovlari qayd etilgan edi. Bu bugungi `127.0.0.1:55432` dedicated SCRAM klasterining autentifikatsiya holati emas. Eski xizmat/bazalar o‘zgartirilmagan; yangi development klasteri ham production isolation/security qabul natijasi emas.

Timestamp precision: PostgreSQL timestamptz(6), UTC DateTime bindings and microsecond-aware comparisons preserve up to six fractional digits. [from,to) boundaries apply precisely to mapping/history, roles/memberships/reporting, grant/token expiry and audit instants. Incremental000006 was applied to dev/test; previously rounded/truncated timestamps cannot be recovered. Its down migration refuses lossy rounding.

## 2026-10-06: E03 dizayn bosqichi

E02 a9ad71e commitida saqlandi. requirements-evidence-baselines shu asosdan ochildi. Foydalanuvchi E03 v1 diagrammasi, deterministic synthetic doirasi va yozma dizaynni “Maqullayman, ishni boshla” bilan tasdiqladi. Yozma dizayn docs/superpowers/specs/2026-10-06-e03-versioned-evidence-design.md; yangi implementatsiya rejasi docs/superpowers/plans/2026-10-06-e03-versioned-evidence-plan.md review uchun tayyorlandi. Avvalgi subagent-driven execution tartibi saqlanadi; foydalanuvchi E03 kodini yozishni buyurib, yangi rejani execution uchun tasdiqladi (2026-10-06). DEC-02 production DoD/weighting siyosati tasdiqlanmagan; real Kanban yozuvlari, inference, push/merge/deploy doiraga kirmaydi.

## 2026-10-06: E03 implementatsiya va qabul chegarasi

R1–R5 tasdiqlangan v1 diagramma/dizayn/reja doirasida implemented va independently reviewed; R6 final whole-unit/scoped review, ten dev incrementals000009–000014 and rollback smoke verified. Commit approved by user on 2026-10-06. Branch requirements-evidence-baselines, base a9ad71e. Draft/unused policy current acceptancega ta'sir qilmaydi; carry explicit, changed pins/QA uchun yangi human decision kerak. Synthetic fictional DoD production policy tasdig'i emas. Source-history100 overflow unknown; legacy null authority unknown, original proof fabricated qilinmaydi. Mutable same-row revocation wholly between observations reconstruct qilinmaydi; observed revocation terminal projectionda qoladi. Full E03/AC06, real CI/adapter/AI/forecast/production OPS claim yo'q. [Dictionary](contracts/lifecycle-data-dictionary.md), [handoff](operations/local-lifecycle.md), [evidence](acceptance/e03.md). E03 commit/push/merge/deploy alohida ruxsat talab qiladi.

2026-10-06 final delivery:218 tests/4690 assertions/50.13s; full Pint and strict Composer/platform passed. I1 independent source authority and redacted-progress rejection before new immutable history, and I2 prospective six action-clock defaults corrected and independently re-reviewed; no Critical/Important findings. No immutable timestamps rewritten. Local18080 /up200 and unauthenticated progress401 are liveness/denial only. E03 code unit complete within approved synthetic scope; full E03 AI/production OPS/AC06 remain open, commit/push/merge/deploy not performed.

2026-10-06: foydalanuvchi E03 commitini va keyingi E04 talab/dizayn bosqichini boshlashni buyurdi. E03 precommit fresh verification218/4690,68.30s; full Pint pass. Push/merge/deploy tasdiqlanmagan. E04 yangi ko‘rsatilmagan dizaynini bu topshiriq avtomatik tasdiqlamaydi.

## 2026-10-06: E03 commit va E04 boshlanish

Foydalanuvchining commit qil va E04 ga o‘t ko‘rsatmasi bajarildi: E03 `32c4e24` (`feat: talab versiyalari va dalilli qabul progressini qo‘shish`),61files7756insertions14deletions. Fresh precommit218/4690,68.30s/fullPintpass; testDB53synthetic tenants/nonSynthetic0, aggregate E03 qabul hujjatida. Push/merge/deploy bajarilmadi. E04 `developer-evidence-analysis` shu commitdan ochildi; FR-060/061/120 va §25 tahlil qilindi. [E04 v1 brief](design-mockups/e04-developer-evidence-flow-v1.md) va diagramma taklif, hali tasdiqlanmagan. Python/parser activation, model/runtime/sandbox/egress va real source integratsiyasi approved deb qabul qilinmaydi.

2026-10-06 keyingi qaror: foydalanuvchi “Ishni boshla” bilan ko‘rsatilgan E04 diagramma v1 va synthetic deterministic doirani tasdiqladi. [Yozma dizayn v1](superpowers/specs/2026-10-06-e04-developer-evidence-design.md) tayyorlanmoqda; uning yangi API/runtime/limit tanlovlari hali tasdiqlanmagan. Product code yoki AppContainer provisioning bajarilmadi. Diagramma tasdig‘i yangi yozma spec/implementation planni avtomatik tasdiqlamaydi.

2026-10-06 yozma dizayn qarori: foydalanuvchi design tasvirini ko‘rgach “Maqullayman, tasdiqlayman, ishni boshla” dedi. Yozma E04 v1 dizayn tasdiqlandi; [implementation plan](superpowers/plans/2026-10-06-e04-developer-evidence-plan.md) yangi reviewable artefakt sifatida tayyorlanadi. Mavjud router tekshiruviga ko‘ra dizayndagi `/api/...` shorthand `/api/v1/...`ga normalizatsiya qilinadi, unversioned alias yo‘q. Product/provisioning hali boshlanmagan; subagent-driven execution afzalligi saqlanadi. E04 commit/push/merge/deploy ruxsati chiqarilmaydi.

2026-10-06 execution qarori: foydalanuvchi reviewdan o‘tgan E04 implementation planni “Maqullayman, ishni boshla” bilan tasdiqladi. Subagent-driven R1–R6 ketma-ket implementatsiya/review boshlanadi; fresh baseline218/4690,68.71s. Rejadagi scoped local AppContainer/owned-runtime provisioning doirasi tasdiqlangan, haqiqiy activation hali tekshirilmagan; xavfsiz gate o‘tmasdan runner yoqilmaydi. E04 commit/push/merge/deploy avtomatik ruxsat etilmaydi.

2026-10-06 partial E04 delivery state: R1/R2/R4/R5 independently reviewed; R3 safe-disabled code and scoped re-review passed with Important findings closed. Actual activation remains BLOCKED by KERNELBASE/0xc0000142 Python startup and missing controlled independent non-loopback endpoint. Default503 preserved; no unsafe/local bare-parser or remote fallback. R6 review/history implemented with focused PostgreSQL evidence, independent R6/final whole-unit review pending. Model/inference absent; full FR-061 attribution/accepted-release aggregate, FR-120/AC-04 and NFR/production gates remain open. API shorthand clarified to existing `/api/v1`; OpenAPI1.3.0, schemas/analyzer/rules remain v1. Human verified_findings are current verified analysis facts only, not E03 acceptance. Same approved diagram/scope reused; PNG unchanged. [Current evidence/limits](acceptance/e04.md) and [runbook](operations/local-developer-analysis.md) distinguish behavioral test fixtures from actual runtime. New E04 commit needs completed-unit approval; no push/merge/deploy.
