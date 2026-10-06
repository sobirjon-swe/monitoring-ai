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
