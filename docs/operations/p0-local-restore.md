# P0 lokal muhitni tiklash

Sana: 2026-10-29. Manba: `ba-workflow-frontend`, `86b8bc8`. Ishchi branch: `codex/local-p0-restore`.

## Ishga tushirish

Loyiha ildizidan PowerShell orqali:

```powershell
./scripts/start-local.ps1
```

Brauzer: http://127.0.0.1:18080/app. PostgreSQL loopback manzilida; `monitoring_ai_dev` va `monitoring_ai_test` ajratilgan. 2026-10-30 kuni eski 55432 port boshqa jarayonda band bo‘lgani sabab lokal port 55433 ga ko‘chirildi. Startup portni ignored `.local/db-config.json`dan oladi; `DB_PORT` va `QA_LOCAL_PORT` shu portga mos bo‘lishi kerak. Startup mavjud boshqa socketni to‘xtatmaydi, migratsiya yoki credential yaratmaydi. Demo fixture xizmatlari ham trusted QA portini hisobga oladi; haqiqiy server porti/rol/baza himoyalari saqlangan. Alohida F02 BA setup authority hali o'chiq va avtomatik yoqilmaydi. Windows avtomatik ishga tushirish sozlanmagan.

Runtime fayllari shu checkoutning `.local/tools/` katalogida: PHP 8.5.12, Composer 2.11.0, PostgreSQL 17.11, Node 22.23.4/npm 10.9.9. Dependencylar mavjud lock fayllaridan o‘rnatilgan. Model/inference o‘chiq. Boshqa loyiha bazasi va servislari o‘zgartirilmagan.

## Lokal kirish

Yangi dedicated bazada mavjud F01 demo komandasi orqali sun’iy olti rol workspace yaratildi. Oldingi kompyuterdagi tokenlar/journallar ko‘chirilmagan yoki qayta yozilmagan. Bu haqiqiy kompaniya ma’lumotlari emas.

Faqat Windows foydalanuvchisi o‘qiy oladigan `.local/browser-demo-p0/credentials-v1.json` ichidan kerakli `key` (`pm`, `qa`, `developer`, `ba`, `technical_head`, `ba_head`) uchun `token`ni lokal login maydoniga kiriting. Secretlarni Git, chat yoki logga ko‘chirmang. Token bir marta beriladi, keyingi so‘rovlar cookie sessiyasidan foydalanadi. Brauzer reloadidan keyin loyiha qayta tanlanadi.

Demo tokenlari yaratilganidan sakkiz soat amal qiladi; joriy expiry faylning `expires_at` maydonida. Sessiya ko‘pi bilan ikki soat. Startup expiry yoki vakolatlarni uzaytirmaydi. Muddati tugagach mavjud setupni majburan qaytarmang va journalni o‘chirmang; alohida tekshirilgan recovery kerak.

## Yangi tekshiruv dalillari

- DEV va TESTning barcha 53 migratsiyasi existing guarded `app:migrate-local` orqali muvaffaqiyatli qo‘llandi; reset/fresh bajarilmadi.
- Composer strict validate va locked platform requirements o‘tdi.
- Backend P0 tanlovi: 73 test, 1720 assertion; health, local auth, browser session/project/read/local guard/app va tenant isolation. Bu to‘liq backend suite natijasi emas.
- Frontend: 16 fayldagi 110 test, typecheck va build o‘tdi. Mavjud ReUI `use no memo` direktivalariga oid ikki build warning bloklovchi emas.
- Haqiqiy HTTP: olti rol bo‘yicha 33 allow/deny/health tekshiruvi o‘tdi; response body yoki credentiallar loglanmadi. Bu Bearer API dalili, cookie brauzer dalilidan alohida.
- Haqiqiy Chrome: olti rol login, loyiha tanlash, vakolatli ekran va logoutdan o‘tdi. PM progress, talablar, QA va BA yozuvlari ko‘rildi. QA foydalanuvchisining eski BA yo‘liga kirishi rad etildi.
- Monitoring PostgreSQL orderly stop/startdan keyin o‘zining portable runtime orqali qayta ishga tushdi; brauzer reloadida sessiya qayta tekshirildi va loyiha qayta tanlandi. Bu backupdan tiklash yoki crash testi emas.
- Startup skripti mavjud servislar bilan va klaster to‘xtatilgan holatda sinovdan o‘tdi. Mustaqil read-only skript reviewda actionable topilma yo‘q.

Mahalliy dalillar `.local/p0/`da: backend-p0-tests.log, frontend-*.log, frontend-results.json, http-results.json, browser-results.json va pm-progress.png. Runtime manbalari/hashlari `.local/tools/runtime-manifest.json`da. Ular ignored; secret konfiguratsiya va baza ham ignored.

## Chegaralar

Bu P0 lokal login/o‘qish natijasi. F02 yozish oqimlari, real connectorlar, lokal model, hisobot/prognoz, production SSO, backup/restore, real moliya va server deploy bajarilgan deb hisoblanmaydi. Sun’iy progress 0/100 va `human_acceptance_missing` kutilgan natija, tizim nosozligi emas. Test soni umumiy tayyorlik foizi emas.

O‘zgarishlar commit/push qilinmagan. Secretlar, dependencylar va generatsiya qilingan frontend assetlari Gitga kiritilmaydi.
