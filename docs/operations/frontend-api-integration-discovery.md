# Frontend–API ulanishi: boshlang‘ich tahlil

2026-10-24. Foydalanuvchi E05-03ni commit qilish va frontendni APIga ulashni boshlashni tasdiqladi. E05-03 commit: `85cb84cef843060fb44149e3615bb45455099ffe`, branch `management-support-tracking`. Keyingi ish branchi `frontend-api-integration`, aynan shu commitdan. Bu hujjat mavjud holat va ochiq qarorlarni qayd etadi; tasdiqlangan yangi dizayn yoki implementatsiya rejasi emas. Yangi product kod, dependency, provision yoki DB yozuvi qilinmadi.

## Tekshirilgan asos

Repository spetsifikatsiyasi v1.0 DOCX: FR-001/020/030/070/080/090/100/180/200, tegishli E01/E03/E05 va kelgusi E08 ko‘rinishlari; scoped AC-09/14/15. FR-130 umumiy xodim balli/reytingini taqiqlovchi chegarani saqlaydi. To‘liq E08/report/prognoz/AI qamrovi bu ulanish bosqichiga avtomatik kirmaydi.

Root va ikki mustaqil o‘qish mutaxassisi `gpt-6.1-sol`/`medium` bilan frontend/UIUX va Laravel/auth/API imkoniyatlarini tekshirdi. Mutaxassislar product/source, Git, DB yoki tarmoqni o‘zgartirmadi; child dispatch bo‘lmadi. `CLAUDE.md` yo‘q. `ui-ux-pro-max` va `grill-me` session katalogi hamda lokal skill qidiruvida topilmadi. Foydalanuvchi muqobil workflow o‘rniga avval shu skilllarni o‘rnatishni ko‘rib chiqishni tanladi. Shu sabab maket bosqichi oldidan muallif manbalari/o‘rnatish tarkibi tekshirilmoqda; hali o‘rnatish bajarilmadi. Yangi mustaqil skill-manba ko‘rik mutaxassisi ham explicit `gpt-6.1-sol`/`medium` bilan ishlaydi.

## Mavjud UI va haqiqiy API orasidagi farq

- `frontend/index.html`, `styles.css`, `app.js`: dependenciesiz HTML/CSS/vanilla JS demo. Raqamlar, a’zolar, signallar, grafiklar synthetic; fetch, haqiqiy login va haqiqiy AI yo‘q. Demo kartalarini shunchaki APIga almashtirish mumkin emas: portfolio/aktivlik/manba-salomatlik agregatlari uchun tegishli backend hali yo‘q.
- `docs/design-mockups/dashboard-ui-v1-approval.md`: tasdiq desktop umumiy kompozitsiya/rang/yon menyuga tegishli. Login, loyiha tanlash, modul ekranlari, responsive holatlar va xato/ruxsat holatlari uchun yangi rasmlar kerak. Eski role/i18n/report takliflari tasdiq deb olinmaydi; UZ/RU/EN talabi saqlanadi, birinchi yetkazish qamrovi aniqlanadi.
- `backend/bootstrap/app.php` API va `/up`ni mount qiladi; web route hali ulangan emas. `AuthenticateLocalToken` loopbackdan kelgan opaque Bearer tokenni tekshiradi. `LocalTokenAuthenticator` tenant/user/subject/token active/expiry/revocation/hash tekshiruvlarini bajaradi.
- `IdentityController::me` faqat id/tenant_id/employee_id/display_name qaytaradi. Brauzer login/logout/session/CSRF almashinuvi, ruxsatli loyihalar GET ro‘yxati va amal vakolatlarini ko‘rsatuvchi kontrakt yo‘q. Scaffold session/auth konfiguratsiyasi ishlaydigan login emas.
- `ProjectController` individual loyiha va sanali rolni beradi; barcha loyiha a’zolari yoki ruxsatli loyihalar pickerini bermaydi. Organization-wide employee/department APIlarini shu maqsadda ishlatish ruxsat doirasini kengaytirishi mumkin.
- Lokal bootstrap8soatli token/grant beradi; oddiy parol login/SSO yo‘q. Bootstrap lifecycle/QA/BA/management huquqlarini bermaydi. Oldingi oltita rol qabul ssenariysi rollback qilingan, saytda ishlatiladigan persistent account/project/grantlar emas. Bunday setup alohida explicit, least-privilege dizaynga muhtoj; signup/self-grant yoki client rol almashishi vakolat bermaydi.

## Ulash mumkin bo‘lgan modul yuzalari

| Ekran | Mavjud HTTP o‘qish | Muhim cheklov |
| --- | --- | --- |
| Talab/vazifa/progress | project requirements, lifecycle-tasks, progress | Baseline/versiya/maxraj ko‘rinadi; unavailable progress nol foizga aylantirilmaydi |
| QA | verification, queue, typed history | Build/muhit/reja va unknown/partial ajratiladi; queue barcha QA obyektlari ro‘yxati emas |
| BA | queue, clarification history | Exact versiya/dalil, scope obligation va synthetic/customer-authenticity cheklovlari saqlanadi |
| Boshqaruv | queue, action history | Ko‘mak completed holati underlying QA/BA bloklovchini yopmaydi; incomplete total null bo‘lib qoladi |

Yozish endpointlari mavjud, lekin formalarni oddiy CRUD deb loyihalash noto‘g‘ri: exact operation schemas, idempotency/CAS/source revision, alohida mustaqil tasdiqlovchi va manba ruxsatlari bor. Birinchi foydali oqim login → ruxsatli loyiha → modul/dalil → ruxsatli amal bo‘lishi taklif qilinadi. O‘qish navigatsiyasi va har bir yozish oqimi alohida tekshiriladigan qismlarga ajratiladi; birinchi read slice butun website tayyor degani emas. Kanban source integratsiyasi read-only qoladi.

## Ochiq qarorlar va takliflar

1. **Foydalanuvchi tanlovi:** mavjud lokal development tokenini bir marta kiritish va server sessiyasi. Ushbu bosqich yangi parol bazasi yoki production SSO yaratmaydi. Token expiry/revocation server sessiyasi orqali chetlab o‘tilmasligi, brauzer saqlashi/loglar, login rotation/CSRF/logout va ruxsatlar dizaynda aniqlanadi. Tanlov batafsil dizayn yoki ko‘rilmagan rasm tasdig‘i emas.
2. **Foydalanuvchi tanlovi:** React asosidagi SPA, yangi build/dependencylar bilan. Mavjud vanilla/Laravel va Vue variantlari tanlanmadi. Exact React/TypeScript/Vite versiyalari hamda auth tafsilotlari yozma dizaynda taklif etiladi; frontend paketlari hali o‘rnatilmagan.
3. Sessiya/token muddati, logout/revocation, CSRF va session fixation; ruxsatli project discovery/pagination hamda capability-hint kontrakti dizaynda aniq bo‘lishi kerak. UI hint serverning har bir so‘rovdagi authorization tekshiruvini almashtirmaydi.
4. **Foydalanuvchi tanlovi:** F01 uchun alohida persistent lokal synthetic workspace va oltita rol akkaunti. Exact manifest/least-privilege grants/provisioning guard/token-output fayllari reja va ko‘rikda aniqlanadi; hali DEV yozuvlari yaratilmagan. UZ/RU/EN talabi va desktop/mobile holatlari saqlanadi. F01 o‘qish oqimidan keyin F02 yozish/selector formalarining qamrovi alohida rasm/dizayn bilan davom etadi. Ism/email asosida yashirin identity merge bo‘lmaydi.

## Qabul mezonlari uchun boshlang‘ich ro‘yxat

- Texnologiya/auth qarori, yozma dizayn va yangi rasmlar explicit tasdiqlangandan keyin kodlash.
- Brauzerda kirish/chiqish, UUID qo‘lda kiritmasdan faqat ruxsatli loyihani tanlash; no-project/employee-unlinked/expired/revoked holatlari.
- Haqiqiy HTTP orqali E03/QA/BA/management o‘qish va keyin aniq tanlangan yozish oqimlari; demo raqamlar yashirincha haqiqiy deb chiqmasligi.
- 401/403/cross-tenant/project/department, grant/membership revocation, loyiha almashganda eski data/cache/requestlarni tozalash; kech kelgan response yangi scopega qo‘shilmasligi.
- Cookie mutation uchun CSRF, login session rotation, logout server-side invalidation; existing Bearer API alohida regressiya bilan saqlanishi.
- Loading/error/empty/partial/unknown/unavailable farqlari, null total, timezone va baseline denominator; keyboard/focus, mobil uzun ID/tarix/jadval holatlari.
- Tasdiqlangan rasmlar bilan haqiqiy running screenshot, mustaqil UI/UX/security ko‘rigi va role browser UAT. HTTP200/401 yoki kernel CRM brauzer UAT o‘rnini bosmaydi.

Mahsulot AI lokal-only; hozir unavailable. Hech qanday tashqi inference, jonli source yozuvi, kompaniya server deployi yoki yangi commit avtomatik tasdiqlanmaydi.

Skill manbalari va [o‘rnatish tekshiruvi](frontend-skill-installation-review.md) qayd etildi: uchala skill o‘rnatilib,77 upstream fayl hashi va litsenziyalar tasdiqlandi. [Rasmlar](../design-mockups/frontend-api-images-v1.md) va [React dizayni](../superpowers/specs/2026-10-24-frontend-api-integration-design.md) foydalanuvchi tomonidan tasdiqlandi. [Implementatsiya rejasi](../superpowers/plans/2026-10-24-frontend-api-integration-plan.md) tayyor va reviewni kutmoqda; product kodi, frontend paketlari va DEV demo hali yaratilmagan. Lokal token + server sessiyasi tanlovi saqlanadi.
