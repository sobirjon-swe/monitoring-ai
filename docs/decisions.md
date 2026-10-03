# Qarorlar reyestri

Sana: 2026-10-03

| Qaror | Holat va asos |
| --- | --- |
| AI joylashuvi | Barcha inference, embedding va reranking lokal. Tashqi AI APIlari taqiqlangan. |
| Boshlang‘ich muhit | Ilova, baza va AI hozirgi kompyuterda; natija tasdig‘idan keyin ichki serverga ko‘chiriladi. |
| E01 scope | API poydevori va ruxsatli AI kontrakt. UI, live integratsiya, inference va moliya keyingi bosqichlarda. |
| Dizayn/reja tasdig‘i | Foydalanuvchi ko‘rsatilgan dizayn/rejadan so‘ng qolgan ishlarni AGENTS.md bo‘yicha boshlashni buyurdi. |
| Backend runtime | Laravel 13.34.0, PHP 8.5.5, Composer 2.9.5, PHPUnit 12.5.37 tekshirilgan; PHP/Laravel mosligi rasmiy release jadvaliga tayanadi. composer.lock aniq dependencylarni belgilaydi. |
| Lokal PostgreSQL | 17.10; monitoring_ai_dev/test alohida bazalar. Migratsiya va runtime rollari ajratiladi. |
| Development login | Faqat lokal muhitda CLI orqali yaratiladigan subyekt va maxfiy token; production SSO bilan tenglashtirilmaydi. HTTP orqali o‘zini admin qilish yoki umumiy default parol yo‘q. |
| Frontend/UI | 2026-10-03 kuni dashboard-ui-v1.png desktop maketi foydalanuvchi tomonidan tasdiqlandi; [tasdiq va tasklar](design-mockups/dashboard-ui-v1-approval.md). Frontend texnologiyasi tanlanmagan, UI implementatsiya qilinmagan. |
| Task branch | monitoring-platform-foundation; T02–T07 mustaqil reviewdan o‘tgan, T08 final branch reviewi kutilmoqda. Ishchi o‘zgarishlar mavjud; clean checkout deb ko‘rsatilmaydi. |
| Commit/push | Boshlang‘ich main commit 131880f push qilingan; T04 xodim/bo‘lim/audit unitining 36fc138 commiti mavjud. Qolgan commitlar uchun ruxsat kutiladi. Commit push/merge/deploy ruxsati emas. |
| Tenant va vaqt | PostgreSQL FORCE RLS, tenant composite FK, transaction-local app.tenant_id va scope cleanup. Runtime/migration sessiyalari UTC; project timezone Asia/Tashkent. |
| Identity review | Manba instansi + o‘zgarmas tashqi ID; email/ism faqat nomzod. Authenticated reviewer, sabab, davr/reviziya va DB-trigger append-only tarix; to‘g‘ridan-to‘g‘ri runtime history INSERT rad etiladi. |
| E01-AI kontrakt | Faqat ichki synthetic registry va actual JSON Schema. Opis/json-schema 2.6.0/string 2.1.0/uri 1.1.0 lokal dependency; remote resolver/inference yo‘q. Joriy grant/membership olish va output deliveryda qayta tekshiriladi; pending-only findings. |

Manba: [Laravel releases](https://laravel.com/framework/docs/releases).

## Pilot/ishlab chiqarishdan oldin ochiq

Haqiqiy SSO, korporativ ma’lumotga kirish siyosati DEC-01/03/05, zaxira/tiklash, token rotation, muhitlar ajratilishi va server operatsiyalari alohida qabul qilinadi. Lokal PostgreSQL klasterining mavjud umumiy autentifikatsiya siyosati avtomatik o‘zgartirilmaydi; alohida bazalar yaratish butun klaster xavfsizligi tekshiruvi degani emas.

GPU: RTX 3060 Laptop 6 GiB VRAM aniqlangan; RAM va modelning latency/sifati hali o‘lchanmagan. Lokal model vaznlari/runtime tanlanmagan va inference ishga tushmagan. Tashqi providerga almashtirish ruxsat etilmaydi.

Mavjud lokal PostgreSQL loopback pg_hba qoidalari trust ekanligi provisioningda qayd etilgan; random credentiallar password authentication ishlayotganini isbotlamaydi. Boshqa bazalarning PUBLIC CONNECT holati o‘zgartirilmagan. Ushbu cheklovlar avtomatik tuzatilmagan va production isolation/security qabul natijasi emas.

Timestamp precision: PostgreSQL timestamptz(6), UTC DateTime bindings and microsecond-aware comparisons preserve up to six fractional digits. [from,to) boundaries apply precisely to mapping/history, roles/memberships/reporting, grant/token expiry and audit instants. Incremental000006 was applied to dev/test; previously rounded/truncated timestamps cannot be recovered. Its down migration refuses lossy rounding.
