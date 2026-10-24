# Lokal development

## 2026-10-05: ushbu checkoutdagi tekshirilgan muhit

Oldingi D:/Tools runtime va ignored konfiguratsiya bu kompyuterda mavjud emas edi. Mos runtime loyiha ichida tiklandi: `.local/tools/php85/php.exe` — PHP 8.5.11 NTS x64; rasmiy [PHP Windows yuklash sahifasi](https://www.php.net/downloads.php?os=windows&version=8.5) SHA256 `0ea96e0d2b9b737a6036f05cf4e95c49313faa6d0f27bd97edb2742503f0c043` bilan tekshirildi. Global PATHdagi PHP 8.4 loyihaning ^8.5 talabiga mos emas; quyidagi aniq executable ishlatiladi. Composer 2.10.2, locked Laravel 13.34.0 va PHPUnit 12.5.37 tiklandi; composer.lock yangilanmadi. Dependency yuklash AI inference emas; mahsulot ma’lumotlari yuborilmadi.

Mavjud PostgreSQL17 xizmatiga tegmasdan `.local/postgres-data`da alohida 17.10 klaster yaratildi. U **127.0.0.1:55432**, SCRAM autentifikatsiyasi bilan ishlaydi; dev/test bazalari va nonowner runtime/migrator rollari mavjud provisioning skripti bilan yaratildi. Bu klaster Windows service sifatida o‘rnatilmagan. Private credential/config va `.env`/`.env.testing` ignored va ACL bilan cheklangan. `.local` backup/Gitga kiritilmaydi; parollar chat/command linega yozilmaydi.

Repository ildizidan, klaster avval ishga tushganini `pg_isready -h 127.0.0.1 -p 55432` bilan tekshiring. PostgreSQL binari `C:/Program Files/PostgreSQL/17/bin/`da. To‘xtatilgan klasterni lokal terminaldan `pg_ctl -D <repo>/.local/postgres-data -l <repo>/.local/postgres-start.log -o "-h 127.0.0.1 -p 55432" start` bilan ishga tushirish mumkin. Agent fon jarayonini `Start-Process -WindowStyle Hidden` bilan ochadi; mavjud boshqa xizmatlarni to‘xtatmaydi. Klaster faqat shu loyiha uchun to‘xtatilishi kerak.

2026-10-24 tekshiruv: Codex tarmoq sandboxi ichida boshlangan server portni tinglashi va `ready` ko‘rsatishi mumkin, ammo sandbox tashqarisidagi klient ham unga ulana olmasligi kuzatildi. Agent mavjud klasterni **`sandbox_permissions=require_escalated` kontekstida** ishga tushirishi, `pg_isready`, runtime guard va DB testlarini ham shu kontekstda bajarishi kerak. Oddiy sandbox klientidagi `no response` server ishlamayotganini isbotlamaydi. Avval sandbox tashqarisida readiness va mavjud listener/PIDni tekshiring; ikkinchi serverni ishga tushirmang. Shu chegaradan tashqarida qayta ishga tushirishdan keyin readiness hamda marked TEST/nonowner runtime guard o‘tdi; firewall, parol va bazaning sxemasi o‘zgartirilmadi. Bu Codex jarayon muhiti talabi, ilovaning DB ruxsatlarini kengaytirish emas.

Backend katalogidan:

```powershell
& ../.local/tools/php85/php.exe artisan app:migrate-local --env=testing
& ../.local/tools/php85/php.exe artisan app:migrate-local
& ../.local/tools/php85/php.exe artisan test --colors=never
& ../.local/tools/php85/php.exe vendor/bin/pint --test
& ../.local/tools/php85/php.exe C:/php/php84/composer.phar validate --strict
& ../.local/tools/php85/php.exe C:/php/php84/composer.phar check-platform-reqs
```

Mavjud E01 baseline yangi muhitda 2026-10-05 tekshirildi: **97 passed / 1934 assertions / exit0 / 11.25s**; E02 yangi testlari baselinega kiritilmagan. Bu E02 yakuniy natijasi emas. Unit suite katalogi Gitga saqlanadi, shunda fresh checkoutda PHPUnit mavjud bo‘lmagan katalog sabab ishga tushmay qolmaydi.

E02 integratsiya tekshiruvi: **150 passed / 3154 assertions / 17.61s / exit0**; full Pint, strict Composer/platform va API route/contract parity o‘tdi. Reviewed 000007/000008 dev migrationlari inkremental qo‘llandi. Dev synthetic acceptance/replay/guarded CLI/projection smoke outer transaction bilan bajarildi va rollbackdan keyin synthetic tenant qolmagani tekshirildi. Durable lease/concurrency dalili alohida test bazada, bu rollback smoke bilan almashtirilmaydi.

Joriy API **http://127.0.0.1:18080**da ishlaydi. Windows port8000da mavjud boshqa listener aniqlandi; boshqa ilova saqlandi, faqat shu taskning kolliziya bergan serveri yopildi. `Get-NetTCPConnection`ning muvaffaqiyatsiz/no-output holati port bo‘shligi isboti emas; `netstat -ano -p tcp` ham tekshirilsin. Yangi serverni ishga tushirishda mavjud jarayonlarni yopmang:

```powershell
& ../.local/tools/php85/php.exe artisan serve --host=127.0.0.1 --port=18080 --tries=1
```

Fon ishga tushirish `Start-Process -WindowStyle Hidden` orqali bajarildi. HTTP `/up` 200 va server request ID, ruxsatsiz connector GET 401 tekshirildi; `/up` faqat liveness. [E02 qabul dalillari](../acceptance/e02.md) scope va cheklovlarni saqlaydi.

Quyidagi 2026-10-03 yozuvlar tarixiy muhit va dalillarni tavsiflaydi; ulardagi path, live server va tayyor local config holati bugungi checkout uchun avtomatik kafolat emas. Joriy E02 natijalari alohida qabul hujjatida qayd etiladi.

## 2026-10-03: tarixiy muhit

2026-10-03: PHP 8.5.5 NTS (D:/Tools/php/php.exe), Composer 2.9.5, Laravel 13.34.0, PHPUnit 12.5.37, PostgreSQL 17.10. composer.lock aniq dependencylarni, jumladan lokal Opis/json-schema 2.6.0ni belgilaydi. Frontend/npm va model runtime tanlanmagan.

## Konfiguratsiya

Hozir ignored .env/.env.testing/.local/db-config.json tayyor. Boshqa nusxada [PostgreSQL provisioning](postgresql-local.md) orqali credential tayyorlanadi; mavjud .env/key almashtirilmaydi. Yangi nusxada .env.example faqat yangi .envga ko‘chiriladi, key faqat birinchi marta generate qilinadi. Secretlar terminal/chat/Git/logga chiqarilmaydi. Runtime DB_CONNECTION=pgsql, loopback DB_HOST, monitoring_ai_app; migration connection monitoring_ai_migrator. DB_URL/read/write overrides yo‘q.

| Muhit | DB_DATABASE / DB_MIGRATION_DATABASE |
| --- | --- |
| local | monitoring_ai_dev |
| testing (.env.testing) | monitoring_ai_test |

Ignored local config runtime_username/password → DB_USERNAME/PASSWORD; migration_username/password → DB_MIGRATION_USERNAME/PASSWORD. .env.testing ham alohida tayyorlanadi. Endpoint/path/credential muhit konfiguratsiyasi; serverga avtomatik ko‘chirilmaydi.

## 2026-10-03 tarixiy buyruqlar (joriy runtime yuqorida)

Repository ildizidan Set-Location backend qilib:

```powershell
composer install --no-interaction --prefer-dist
php artisan app:migrate-local
php artisan app:migrate-local --env=testing
php artisan serve --host=127.0.0.1 --port=8000
```

000001–000006 migrationlar dedicated dev/testga inkremental qo‘llangan; reset/fresh/seed ishlatilmagan. Guard actual local database/login/marker va shu migration connectionda owner SET ROLEni tekshiradi; keyin owner connection purged. Install/dev DB/admin/npm/model downloadni avtomatik bajarmaydi. Session/cache faylda, queue sync — qimmat analysis durability mexanizmi emas.

2026-10-03 tarixiy server 127.0.0.1:8000da ishlagan; joriy endpoint18080. Port band bo‘lsa loyiha serverini qayta ishlating; duplicate server yoki server deploy avtomatik boshlanmaydi. [Explicit lokal login](local-authentication.md) faqat tanlangan user/subject/grantlarni yaratadi.

```powershell
composer validate --strict
composer check-platform-reqs
php artisan test --colors=never
php vendor/bin/pint --test
php artisan route:list --except-vendor
Invoke-WebRequest http://127.0.0.1:8000/up -UseBasicParsing
```

IdentityMappingTest va AuthorizedContextTest filterlari bilan tegishli behavior alohida tekshiriladi. Testlar connectiondan oldin exact loopback monitoring_ai_test va runtime rolini tekshiradi; SQLite yoki shared DB ishlatilmaydi. Bootstrap success testi dedicated dev bazada synthetic outer transaction rollback qiladi, persisted admin/secret qoldirmaydi.

2026-10-03 final fix fresh verification: 97 passed/1934 assertions, exit0, 12.53s; strict Composer/platform va full Pint pass. Live /up HTTP200 {"status":"ok"}, generated UUID X-Request-ID. Hujjat/parity va final whole-branch review kutilmoqda; [qabul dalillari](../acceptance/e01.md).

/up faqat liveness, DB/model/queue/connector readiness emas. / 404 JSON. [Product endpoints](../api/openapi.yaml) local bearer va current grant/membership talab qiladi. Errorlar debug bo‘lsa ham safe JSON/request_id; validation generic field xabar; Retry-After/Allow saqlanadi; log faqat correlation/type, raw SQL/DSN/prompt emas.

## Joriy ochiq scope

Production SSO/MFA, token rotation, FastAPI/model/production worker, real source adapters, RAG/exports/notifications, backup/restore/rollback, retention/deletion, latency/load va real AI secret scanning/offline inference sinovlari bu poydevorda bajarilmagan. PostgreSQL trust va boshqa DB PUBLIC CONNECT cheklovlari [alohida qayd etilgan](postgresql-local.md), o‘zgartirilmagan. NFR-005 yoki production isolation da’vosi yo‘q.

Barcha product AI lokal/company-controlled; external inference/telemetry/fallback yo‘q. T07 faqat synthetic JSON kontrakt, haqiqiy model yoki sifat o‘lchovi emas. Dashboard v1 image/demo approved; production frontend/API integration va yangi lifecycle UI tasdigi ochiq. Serverga ko‘chirish faqat lokal natija tasdig‘idan keyin alohida ruxsat bilan, automatic deploy yo‘q.

2026-10-06 E03 API/outbox implemented, R1–R5 reviewed; final full suite218/4690/50.13s, full Pint, strict Composer/platform pass. Whole-unit/scoped review and safe dev ten incrementals000009–000014/rollback smoke passed. Commit pending. [Handoff](local-lifecycle.md).
