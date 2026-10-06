# Lokal PostgreSQL tayyorlash

2026-10-05 joriy checkout alohida PostgreSQL 17.10 SCRAM klasteridan `127.0.0.1:55432`da foydalanadi; `.local/postgres-data`, dev/test va runtime/migrator rollari [joriy lokal development](local-development.md)da qayd etilgan. Quyidagi 2026-10-03 port5432/trust kuzatuvlari tarixiy, joriy klasterning holati emas. Eski service yoki boshqa bazalar o‘zgartirilmagan.

Bu yo‘riqnoma E01 rejasining T02 lokal DB tayyorlash qismini qamraydi. Spetsifikatsiya 21.2, 22, 23 va 30.2-bo‘limlari hamda FR-200 uchun migration/runtime rollari ajratiladi. Provisioner faqat baza/rol tayyorlaydi; tenant RLS va audit/identity tarix cheklovlari hozir T03–T06 inkremental migratsiyalarida joriy qilingan. Ularning behavior dalillari ilova testlarida, provisioning bilan aralashtirilmaydi.

## Provisioning

2026-10-03 kuni mavjud lokal PostgreSQL 17.10 (`127.0.0.1:5432`) tekshirildi. Script PostgreSQL 17+ membership parametrlariga tayanadi. `psql` PATHda bo‘lishi va `postgres` administratori lokal ulanishga ega bo‘lishi kerak. Ulanish paroli zarur bo‘lsa PostgreSQLning mahalliy credential mexanizmidan foydalaniladi; administrator paroli script argumenti yoki tracked faylga yozilmaydi.

Repository ildizidan:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/provision-local-postgres.ps1
```

Muqobil lokal port yoki `psql` joyi `-DbPort` / `-PsqlPath` bilan belgilanadi. Host faqat loopback bo‘lishi mumkin. Script faqat quyidagi dedicated nomlar bilan ishlaydi:

| Obyekt | Vazifa |
| --- | --- |
| `monitoring_ai_dev` | Lokal ilova ma’lumotlari |
| `monitoring_ai_test` | Ajratilgan avtomatik test bazasi |
| `monitoring_ai_owner` | NOLOGIN, database/schema va kelajak migration jadvallari egasi |
| `monitoring_ai_migrator` | LOGIN, NOINHERIT; owner roliga faqat explicit SET ROLE |
| `monitoring_ai_app` | LOGIN, owner membership yo‘q; runtime va behavior testlari |

Uchala rol NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOREPLICATION, NOBYPASSRLS. Dedicated bazalar `template0`, UTF8 bilan yaratiladi. Faqat shu bazalardagi PUBLIC database/schema vakolatlari bekor qilinadi. Runtime CONNECT va public USAGE oladi; yangi owner jadvallariga SELECT/INSERT/UPDATE/DELETE va identity sequence uchun USAGE/SELECT default grant beriladi. CREATE, ALTER, TRUNCATE va owner roliga o‘tish berilmaydi. Audit kabi maxsus jadvallarda migration umumiy DML defaultini tegishlicha toraytirishi kerak.

## Maxfiy konfiguratsiya

Tasodifiy 32-byte migration/runtime parollari faqat ignored `.local/db-config.json` fayliga yoziladi. Fayl ACLi repository egasi, joriy provisioning identitysi, SYSTEM va Windows administratorlariga cheklanadi. Script parollarni yoki raw SQL/xato matnlarini terminalga chiqarmaydi. SQL stdin orqali uzatiladi; parollar process command linega kirmaydi. Password DDL oldidan statement, duration va sampling loglari faqat shu sessiyada o‘chiriladi. Maxsus audit/proxy extensionlari bu sozlamalardan mustaqil log yuritishi mumkin: ular bu script orqali sozlanmagan, shuning uchun bunday extensionli muhitda credential DDL log siyosati avval alohida tekshiriladi.

JSON maydonlari: `host`, `port`, `development_database`, `test_database`, `owner_role`, `migration_username`, `migration_password`, `runtime_username`, `runtime_password`; `version` va `installation_id` provisioning provenance uchun. Fayl mazmunini issue, chat yoki logga ko‘chirmang. `.env`ga uzatish lokal setup orqali, qiymatlarni konsolga chiqarmasdan bajariladi.

Har retry Git ignore qoidasini va mavjud obyektlarning aynan shu lokal installation markerini tekshiradi. Bir xil nomli boshqa obyekt, owner/role flag yoki membership ziddiyati aniqlansa yozishdan oldin to‘xtaydi. Retry credential fayli/parollarini almashtirmaydi; jadvallar va ma’lumotlarni reset/drop qilmaydi. Rollar va ularning markerlari bir transactionda yaratiladi.

`CREATE DATABASE` transaction ichida bajarilmaydi: database yaratilishi bilan keyingi COMMENT orasida uzilish bo‘lsa, marker qo‘yilmagan baza qolishi mumkin. Retry bunday bazani avtomatik qabul qilmaydi. Administrator oldingi provisioning dalillari va lokal installation recordini tekshirib, haqiqatan shu ishda yaratilganini mustaqil tasdiqlagach markerni qo‘lda tiklashi mumkin; ishonchli tasdiq bo‘lmasa obyekt o‘zgarishsiz qoldiriladi. Credential fayli yo‘qolsa ham script mavjud obyektlarni avtomatik adopt qilmaydi yoki parollarini almashtirmaydi.

## Migration va runtime protokoli

Laravel runtime ulanishi `monitoring_ai_app` bo‘ladi. Alohida migration connection `monitoring_ai_migrator` credentiallarini oladi. Har yangi migration sessiyasida, migration repository/jadval DDLdan oldin **shu ulanishda**:

```sql
SET ROLE monitoring_ai_owner;
SELECT session_user, current_user;
```

Natijada `session_user=monitoring_ai_migrator`, `current_user=monitoring_ai_owner`; barcha migration jadvallari ownerga tegishli yaratiladi. PDO/Laravel reconnect yangi sessiya yaratadi: yana role o‘rnatilishi shart. Migration connectionni alohida command/connector orqali tanlash va role bindingni qayta qo‘llash kerak; boshqa runtime connectionda SET ROLE bajarish bu talabni bajarmaydi. Migrator NOINHERIT sabab role o‘rnatilmasa DDL rad etiladi. Runtime so‘rovlari migration credentiallarini ishlatmaydi.

Avtomatik testlar faqat `127.0.0.1`/kelishilgan loopback va **aynan** `monitoring_ai_test` nomini allowlist qilib tekshiradi. Setup/migration connection ajratilgan, assertionlar `monitoring_ai_app` orqali yuradi. Test reset/deletion buyruqlari `monitoring_ai_dev`, `postgres` yoki boshqa baza bilan ishga tushmasligi kerak. Bu scriptning o‘zi reset/delete bajarmaydi.

## Tekshirilgan natija va chegaralar

2026-10-03da provisioner Windows PowerShell va PowerShell Coreda qayta ishga tushirildi: exit 0 va credential fayli hash qiymati o‘zgarmadi. Credential ACL regression tekshiruvida Everyone uchun faqat metadata `ReadAttributes` ACE vaqtincha qo‘shildi (fayl mazmunini o‘qish vakolati berilmadi), retry uni olib tashladi va faqat to‘rtta explicit allowlist ACE qoldi; credential mazmuni o‘zgarmadi. Role flags/membership, database owner, runtime schema USAGE/CREATE va database CREATE/TEMP vakolatlari tekshirildi. Izolyatsiyalangan test transactionida owner yaratgan identity jadvalida runtime SELECT/INSERT/UPDATE/DELETE ishladi; CREATE/ALTER/TRUNCATE va owner escalation rad etildi. Transaction rollback qilindi va probe jadvallari qolmagani tasdiqlandi. Haqiqiy migrator sessiyasida SET ROLE orqali owner DDL bajarilib rollback qilindi; ikkala dedicated bazada runtimega tegishli public jadval soni 0 edi.

Mavjud cluster loopback `pg_hba.conf` qoidalari **trust**. Script ularni, administrator credentiallarini, global sozlamalarni yoki boshqa bazalarni o‘zgartirmadi. Random parollar mavjudligi bu kompyuterda password authentication ishlayotganini anglatmaydi: trust credential tekshiruvini chetlab o‘tadi. Shu sabab bu faqat lokal development setup; autentifikatsiya va yopiq tarmoq pilot/production oldidan alohida tayyorlanadi.

Dedicated bazalarda PUBLIC CONNECT/TEMP bekor qilingan. Biroq mavjud boshqa bazalarning PUBLIC CONNECT huquqi o‘zgartirilmagan: `monitoring_ai_app` bilan ma’muriy `postgres` bazasiga ulanish mumkinligi faqat `current_database()` orqali tekshirildi. Script **faqat dedicated bazalarga cluster-wide ulanish mumkin** degan kafolat bermaydi. Boshqa ilova ma’lumotlari o‘qilmadi. Role/schema himoyasi ilova bazalarida amal qiladi, lekin trust orqali boshqa identity bilan ulanishga qarshi himoya bermaydi.

Bu tekshiruv NFR-005 backup/restore, RLS, korporativ SSO yoki production isolation qabul sinovi hisoblanmaydi.

## T08 joriy migration holati

000001–000005 dev/testga inkremental qo‘llangan. app:migrate-local actual marker/login va owner bindingni tekshiradi. Audit runtime UPDATE/DELETE/TRUNCATEga yopiq; historyda runtime INSERT ham bekor qilingan. public.snapshot_identity_mapping faqat public.identity_mappings triggeri uchun SECURITY DEFINER, fixed pg_catalog search_path/qualified objects va FORCE RLS ostida snapshot yozadi; PUBLIC/runtime EXECUTE bekor qilingan. 2026-10-03 full suite90/1690 pass bu provisioningdagi trust/global CONNECT cheklovlarini bekor qilmaydi. Backup/restore va NFR-005 o‘lchanmagan.

Timestamp precision: PostgreSQL timestamptz(6), UTC DateTime bindings and microsecond-aware comparisons preserve up to six fractional digits. [from,to) boundaries apply precisely to mapping/history, roles/memberships/reporting, grant/token expiry and audit instants. Incremental000006 was applied to dev/test; previously rounded/truncated timestamps cannot be recovered. Its down migration refuses lossy rounding.
