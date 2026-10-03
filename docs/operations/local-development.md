# Lokal development

2026-10-03: PHP 8.5.5 NTS (D:/Tools/php/php.exe), Composer 2.9.5, Laravel 13.34.0, PHPUnit 12.5.37, PostgreSQL 17.10. composer.lock aniq dependencylarni, jumladan lokal Opis/json-schema 2.6.0ni belgilaydi. Frontend/npm va model runtime tanlanmagan.

## Konfiguratsiya

Hozir ignored .env/.env.testing/.local/db-config.json tayyor. Boshqa nusxada [PostgreSQL provisioning](postgresql-local.md) orqali credential tayyorlanadi; mavjud .env/key almashtirilmaydi. Yangi nusxada .env.example faqat yangi .envga ko‘chiriladi, key faqat birinchi marta generate qilinadi. Secretlar terminal/chat/Git/logga chiqarilmaydi. Runtime DB_CONNECTION=pgsql, loopback DB_HOST, monitoring_ai_app; migration connection monitoring_ai_migrator. DB_URL/read/write overrides yo‘q.

| Muhit | DB_DATABASE / DB_MIGRATION_DATABASE |
| --- | --- |
| local | monitoring_ai_dev |
| testing (.env.testing) | monitoring_ai_test |

Ignored local config runtime_username/password → DB_USERNAME/PASSWORD; migration_username/password → DB_MIGRATION_USERNAME/PASSWORD. .env.testing ham alohida tayyorlanadi. Endpoint/path/credential muhit konfiguratsiyasi; serverga avtomatik ko‘chirilmaydi.

## Real buyruqlar

Repository ildizidan Set-Location backend qilib:

```powershell
composer install --no-interaction --prefer-dist
php artisan app:migrate-local
php artisan app:migrate-local --env=testing
php artisan serve --host=127.0.0.1 --port=8000
```

000001–000006 migrationlar dedicated dev/testga inkremental qo‘llangan; reset/fresh/seed ishlatilmagan. Guard actual local database/login/marker va shu migration connectionda owner SET ROLEni tekshiradi; keyin owner connection purged. Install/dev DB/admin/npm/model downloadni avtomatik bajarmaydi. Session/cache faylda, queue sync — qimmat analysis durability mexanizmi emas.

Joriy root-owned server 127.0.0.1:8000da ishlaydi. Port band bo‘lsa loyiha serverini qayta ishlating; duplicate server yoki server deploy avtomatik boshlanmaydi. [Explicit lokal login](local-authentication.md) faqat tanlangan user/subject/grantlarni yaratadi.

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

## Ochiq scope

Production SSO/MFA, token rotation, FastAPI/model/worker, connectors/outbox, RAG/exports/notifications, backup/restore/rollback, retention/deletion, latency/load va real AI secret scanning/offline inference sinovlari bu poydevorda bajarilmagan. PostgreSQL trust va boshqa DB PUBLIC CONNECT cheklovlari [alohida qayd etilgan](postgresql-local.md), o‘zgartirilmagan. NFR-005 yoki production isolation da’vosi yo‘q.

Barcha product AI lokal/company-controlled; external inference/telemetry/fallback yo‘q. T07 faqat synthetic JSON kontrakt, haqiqiy model yoki sifat o‘lchovi emas. UI tasdiqlanmagan/frontend tanlanmagan. Serverga ko‘chirish faqat lokal natija tasdig‘idan keyin alohida ruxsat bilan, automatic deploy yo‘q.
