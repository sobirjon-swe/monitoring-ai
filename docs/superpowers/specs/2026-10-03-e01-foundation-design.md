# AI monitoring E01 poydevori dizayni

Sana: 2026-10-03
Holat: foydalanuvchi ko‘rib chiqishi uchun taklif; mahsulot kodi hali yozilmagan.
Manba: docs/requirements/AI_monitoring_Texnik_spetsifikatsiya_v1_0.docx, 3, 21, 22, 23, 24, 25 va 30.2-bo‘limlar.
Talab havolalari: FR-001, FR-020, FR-110, FR-160, FR-200; AC-09.

## Maqsad va yakuniy natija

E01 kompaniya, xodim, bo‘lim va loyiha yozuvlarini, tasdiqlangan tashqi identifikatorlarni, loyiha a’zoligini va vaqtga bog‘liq rollarni boshqaradi. Har bir operatsiya autentifikatsiya va tenant/loyiha doirasi bilan tekshiriladi. Bir tenant foydalanuvchisi boshqa tenant ma’lumotlarini o‘qiy yoki o‘zgartira olmaydi. Bu bosqich keyingi integratsiyalar uchun tekshiriladigan poydevor beradi.

## Arxitektura tanlovi

Tavsiya: modulli Laravel biznes ilovasi va PostgreSQL. Yopiq Python/FastAPI hamda lokal model servisi keyingi tahlil bosqichida qo‘shiladi; E01 ularning ishonchli doira va versiyalangan JSON shartnomalarini belgilaydi. Frontend texnologiyasi bu backend bosqichida tanlanmaydi.

Muqobil 1: faqat Laravel orqali keyingi AI ishlarini ham bajarish. Dastlab soddaroq, lekin Python analizatorlari va model baholashini birlashtirish qiyinlashadi.
Muqobil 2: barcha domenlarni mikroxizmatlarga ajratish. Boshlang‘ich poydevor uchun xizmatlararo ruxsat va tranzaksiya murakkabligini oshiradi.

Foydalanuvchining yangilangan qarori: avval ilova, PostgreSQL va AI hozirgi kompyuterda ishlaydi. Ishlaydigan lokal natija foydalanuvchi tomonidan tasdiqlangach kompaniyaning ichki serveriga ko‘chiriladi. GPU/VRAM/RAM imkoniyatlari lokal model tanlashdan oldin tekshiriladi. LLM, embedding va reranking tashqi tayyor APIlardan foydalanmaydi. AI uzilganda faktlar ochiq qoladi va tahlil holati ko‘rsatiladi. Model/hardware tanlovi E01 domen ishlarini to‘smaydi.

Servis manzillari, model saqlash yo‘llari, ulanishlar va maxfiy sozlamalar muhit konfiguratsiyasidan olinadi. Lokal ishga tushirish tashqi tarmoqqa servis ochishni talab qilmaydi. Serverga ko‘chirish alohida tasdiqlangan ish: konfiguratsiya, ma’lumot/model vaznlari, migratsiya va tekshirish bilan bajariladi; avtomatik deploy yoqilmaydi.

## Domen va ma’lumotlar

- tenants: kompaniya va holati.
- users/auth_subjects: login subyekti, tasdiqlangan issuer/subject va xodimga bog‘lanish.
- reporting_relationships: rahbar va xodim orasidagi sanali munosabat.
- access_grants/policy_versions: biznes rollaridan alohida tenant/loyiha/bo‘lim ruxsatlari.
- employees: tenantga tegishli xodim; tizim loginidan alohida domen yozuvi.
- departments va department_memberships: bo‘lim va sanali a’zolik.
- projects va project_memberships: loyiha va sanali a’zolik.
- role_assignments: xodimning loyiha doirasidagi roli va valid_from/valid_to.
- identities: integration/source_instance/external_user_id va xodim o‘rtasidagi tasdiqlangan moslik. Tasdiqlanmagan nomzod xodimlarni birlashtirmaydi.
- audit_events: bajaruvchi, tenant, operatsiya, resurs, vaqt va correlation_id. Sirlar yoki maxfiy mazmun auditga kiritilmaydi.

Davrlar [valid_from, valid_to) ko‘rinishida; tugash sanasi ochiq bo‘lishi mumkin. Bir shaxsda bir nechta loyiha roli mumkin; aynan bir rolning takror ustma-ust tayinlanishi rad etiladi. Tarixiy rol voqea vaqti uchun, joriy ruxsat esa so‘rov vaqti uchun tekshiriladi; eski rol bekor qilingan kirishni qaytarmaydi.

Ichki kalitlar UUID; tenantga tegishli aloqalar tarkibli cheklovlar bilan tenantlararo ulanishni rad etadi. Tashqi identifikator tenant/manba instansi doirasida noyob. Bo‘lim a’zoligi, loyiha a’zoligi va rol mustaqil; birini boshqasidan taxmin qilmaymiz.

## Kirish va ruxsatlar

Tenant konteksti autentifikatsiyalangan va tasdiqlangan a’zolikdan olinadi. So‘rov yoki model o‘zi tenantni almashtira olmaydi. Kontekst yo‘q bo‘lsa doiraviy o‘qish/yozish rad etiladi. Sana bo‘yicha rollar tekshiriladi. Xodim o‘z ruxsatli yozuvlarini, PM biriktirilgan loyiha doirasini, rahbar tegishli bo‘lim doirasini ko‘radi. Integratsiya administratori shaxsiy hisobot va moliyaga avtomatik kirish olmaydi.

PostgreSQL RLS va ilova siyosatlari birga qo‘llanadi. Ilova roli himoyalangan jadvallarning egasi bo‘lmaydi va SUPERUSER/BYPASSRLS vakolatiga ega bo‘lmaydi. Tenant sessiya konteksti tranzaksiya/ish yakunida tozalanadi. Admin ulanishi alohida. Fon ishlarida ham ishonchli tenant/loyiha konteksti shart. Testlar jadval egasining RLSga ta’sirini, rollback va kontekst tozalanishini tekshiradi.

## API va xatolar

/api/v1 ostida kompaniya doirasidagi xodim, bo‘lim, loyiha, a’zolik, rol va identifikator mosligi uchun versiyalangan endpointlar. Ro‘yxatlar kursorli sahifalanadi. Yozishlarda kutilgan reviziya tekshiriladi; moslik ziddiyati 409, yaroqsiz sanalar 422, ruxsatsiz so‘rov 403 yoki mavjudlikni yashirish kerak bo‘lsa 404 beradi. Xatolar request_id va xavfsiz xabarni saqlaydi.

AI kontrakti faqat ishonchli tenant_id/project_id, actor/purpose, ruxsatli artifact IDlari, snapshot hash va schema_versionni qabul qiladi. E01 ruxsatni hisobga oluvchi kontekst mijozini sun’iy ruxsatli snapshot testlari bilan tekshiradi: tenant/loyiha almashtirish va tasdiqlanmagan artifact ID rad etiladi. E01 model chaqirmaydi va soxta tahlil natijasini yaratmaydi.

## Tekshirish va qabul

1. Ikki tenantli testda API, query-builder va to‘g‘ridan-to‘g‘ri DB cheklovlari ma’lumot sizishini rad etadi.
2. Tenant konteksti yo‘q va bekor qilingan a’zolik bilan kirish rad etiladi; ketma-ket ishlar kontekstni meros olmaydi.
3. Amal qilish sanasi bo‘yicha rol tarixini olish mumkin; bo‘lim a’zoligi loyiha ruxsatiga aylanmaydi.
4. Bir xil email yoki ism ikki xodimni yashirin birlashtirmaydi; bir xil tashqi ID boshqa source_instance uchun alohida.
5. Ziddiyatli parallel tahrir 409 beradi, muvaffaqiyatsiz tranzaksiya qisman ma’lumot qoldirmaydi.
6. Audit bajaruvchi va operatsiyani ko‘rsatadi; credentials va prompt mazmunini saqlamaydi.
7. RLS testlari imtiyozsiz PostgreSQL application roli bilan alohida test bazasida bajariladi.

## Ushbu bosqichdan tashqarida

GitLab/Jira ma’lumotlarini yuklash, diff tahlili, embeddinglar, prognoz, pul taqsimoti, to‘lov va yakuniy UI ekranlari. Ular o‘z bosqichi va qabul shartlari bilan qo‘shiladi.

## Implementatsiyadan oldin hal qilinadigan qarorlar

- Dizayn va diagramma tasdig‘i.
- Dastlabki administratorni yaratish va ruxsat berish tartibi; oddiy CRUD orqali o‘z vakolatini oshirish taqiqlanadi.
- Lokal rivojlantirish uchun PostgreSQL va imtiyozsiz test ulanishini tayyorlash; real korporativ bazaga ulanish kerak emas.
- Pilot autentifikatsiyasi: mavjud SSO tafsilotlari yoki faqat lokal rivojlantirish uchun alohida vaqtinchalik autentifikatsiya. Lokal autentifikatsiya ishlab chiqarish SSOsi deb ko‘rsatilmaydi.
- Laravel/PHP/PostgreSQL versiyalarini moslik asosida qat’iy belgilash va Gitning boshlang‘ich branchini tayyorlash. Remote va deploy ushbu tasdiqqa kirmaydi.

DEC-01/03/05 pilotni yoqishdan oldin tasdiqlanadi; model resurslari va moliyaviy DEC-06/07/08 E01ni yozish uchun talab qilinmaydi. Keyingi yozma amalga oshirish rejasi dizayn tasdig‘idan so‘ng tayyorlanadi.
