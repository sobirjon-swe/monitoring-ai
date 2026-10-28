# F02 website amallari — boshlang‘ich inventarizatsiya

2026-10-28. F01 faqat o‘qish interfeysi `89f7453` bilan commit/push qilingan. F02-01 BA context.add uchun tasdiqlangan dizayn doirasidagi browser attempt implementatsiyasi mavjud; yakuniy qabul hali ochiq. Qolgan yozish yo‘nalishlari inventarizatsiya va rejalashtirish holatida.

| Yo‘nalish | Mavjud backend oilasi | Frontenddan oldin hal qilinishi kerak |
| --- | --- | --- |
| E03 talablar | draft yaratish/tahrirlash/tasdiqlash, DoD, vazifa/bog‘liqlik, dalil/tekshiruv, baseline/qabul | Joriy loyiha a’zolari va aniq versiyalar uchun ruxsatli selectorlar; draftni topish/o‘qish; create operatsiyasining idempotentligi; reviziya va avlod konflikti. Mavjud requirements GET faqat tasdiqlangan versiyalarni qaytaradi. |
| QA | synthetic fixture, plan entry/plan/review, execution, defect/handoff va tarix | Test/criterion/task/source versiyalarining ruxsatli kataloglari, source revision va idempotency, rollar, xato/partial holat; production source yozuvi sifatida taqdim etmaslik. |
| BA | clarification, answer, event, review, navbat va tarix | Mavjud ko‘rinadigan yozuvdagi inson `context.add` birinchi nomzod; boshqa amallar uchun source identity/version, task/criterion va party selectorlari, BA/PM alohida vakolatlari va original proof. Navbat umumiy katalog emas. |
| Boshqaruv | action, event, support, review, navbat va tarix | Aniq QA/BA/source bog‘liqliklari va mas’ullar uchun vakolatli selectorlar, CAS/idempotency hamda mustaqil review; support bajarilishi asosiy blocker/qabulni avtomatik yopmaydi. |

Marshrutlarning haqiqiy manbasi [API routes](../../backend/routes/api.php); qat’iy kontraktlar [BA](../contracts/ba-evidence-v1.md), [QA](../contracts/qa-evidence-v1.md), [management](../contracts/management-evidence-v1.md) va [OpenAPI](../api/openapi.yaml). Joriy [browser routes](../../backend/routes/browser.php) F01 GET yo‘llari bilan birga F02-01 uchun to‘rtta typed attempt yo‘lini ham o‘z ichiga oladi. Browserga yangi amallar generic proxy orqali emas, har bir tasdiqlangan typed endpoint orqali qo‘shiladi; qo‘lda UUID kiritish tayyor selector hisoblanmaydi.

Tasdiqlangan birinchi slice [BA kontekst dizayni](../superpowers/specs/2026-10-28-f02-ba-context-design.md): mavjud ruxsatli aniqlashtirishga qo‘shimcha inson konteksti, joriy reviziya va idempotency bilan. Alohida synthetic workspace va writer original F01 journal hamda olti read-only foydalanuvchini o‘zgartirmaydi. Foydalanuvchi 2026-10-28 kuni faqat shu birinchi bo‘lak rasm/doirasini tasdiqladi; boshqa F02 yozish amallari o‘sha tasdiq bilan qamrab olinmaydi.

Haqiqiy Kanban/source tizimlari read-only; product AI faqat lokal yoki kompaniya infratuzilmasida. Production SSO, deployment, real mijoz tasdig‘i, financial va boshqa F02 amallari ushbu kichik bo‘lakdan tashqarida qoladi.


[Joriy kontrakt va dalillar](../acceptance/f02-01-ba-context.md); [cleanup runbook](f02-attempt-cleanup.md). Alohida synthetic writer workspace [token himoyasi qarori](../superpowers/specs/2026-10-28-f02-synthetic-token-protection-proposal.md) tasdiqlanib, texnik isbotlanmaguncha bloklangan.
