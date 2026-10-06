# E03 oqim diagrammasi — ko‘rib chiqish uchun brief

Sana: 2026-10-06. Holat: diagramma v1 va taklif doirasi 2026-10-06 foydalanuvchining Maqullayman javobi bilan tasdiqlangan; mahsulot kodi yozilmagan. E02 `a9ad71e` commitida saqlandi; yangi `requirements-evidence-baselines` branch shu commitdan ochildi. Push, merge va server deploy bajarilmadi.

Rasm: [E03 v1](e03-requirements-evidence-flow-v1.png). Bu E02 tasdig‘ini yangi scopega kengaytirmaydi. Rasm deterministik chizildi, inference yoki tashqi AI API ishlatilmadi.

## Maqsad va taklif etilgan doira

Mavjud Laravel/PostgreSQL ichida synthetic/manual E03 poydevori: o‘zgarmas talab va mezon versiyalari, inson tasdiqlagan scope baseline, task/dependency va DoD policy versiyalari, ko‘rib chiqilgan dalil bog‘lanishlari, acceptance qarorlari va faqat bog‘liq joriy natijalarni stale qilish. Foydalanuvchi kutgan natija: Done bilan qabul qilingan natijani ajratish, vazn/denominatorni ko‘rsatish va scope o‘zgarganda tarixni yo‘qotmaslik.

Talab manbasi repository DOCX v1.0: FR-030 (§4), FR-080 (§9), FR-150 (§16), §12 provenance/invalidation; E03 §30.4; AC-05/06/08/09. XML paragraph locatorlar zero-based: 112–113, 202–206, 236–237, 302–303, 591–597, 659/660/662. Ular page raqami emas.

Spetsifikatsiyada E03-DATA FR-051ni ham keltiradi; FR-051 aslida attendance/activity-minute cheklovidir. Ushbu citation saqlanadi, ammo selective invalidation §12/AC-08ga bog‘lanadi; talab ma’nosi almashtirilmaydi.

## Yondashuvlar

1. **Tavsiya:** deterministik version/baseline/link/acceptance poydevori. Mavjud E01/E02 himoyalarini qayta ishlatadi, AC-05/08ni synthetic fixture orqali tekshiradi.
2. Faqat requirement/baseline storage. Kichikroq, lekin QA/acceptance/invalidation invariantlarini tekshirmaydi; keyinchalik modelni qayta ishlash ehtimoli yuqori.
3. To‘liq context graph va lokal AI impact proposals. Model/runtime/quality qarorlari hali ochiq; katta scope. Birinchi birlikka tavsiya qilinmaydi.

## Oqim va invariantlar

- Draft/readiness tasdiqlangan versiya emas. Approved snapshots o‘zgarmas; actor, sabab, effective time va revision lineage saqlanadi.
- Baseline aniq criterion/policy versiyalarini va oldindan tasdiqlangan vaznlarni pin qiladi. Late hodisa yangi current pointer o‘rniga o‘tmaydi; eski va joriy denominator alohida saqlanadi.
- Evidence link tenant/project-consistent immutable receipt/artifact, hash va allowlisted locatorga bog‘lanadi. Proposed yoki taxminiy link mezonni qabul qilmaydi. Payload instructionlari privilege yaratmaydi.
- Acceptance inson qarori; amaldagi DoD va required QA dalili bilan bog‘lanadi. Done + failed/missing mandatory QA unresolved criterionga nol vazn beradi. Policy tasdiqlanmagan bo‘lsa acceptance unavailable.
- Tasdiqlangan/effective criterion yoki DoD policy almashtirilishi, dalil correction/revocation faqat bog‘liq current applicabilityni stale qiladi. Draft yaratish joriy qabulni stale qilmaydi. Eski inson qarori, contribution va report snapshot qayta yozilmaydi; joriy access revocation tarixiy o‘qishga ham tatbiq etiladi.
- Dependency self/cross-project linklar rad etiladi; sikl va missing estimate ochiq ko‘rsatiladi. E07 forecast/probability/simulation bu birlikka kirmaydi.

## Vakolat va ochiq qarorlar

Tavsiya etilgan local synthetic purpose guruhlari: talabni tahrirlash, talab/policy/baselineni tasdiqlash, evidence linkni tekshirish, criterion acceptance va scoped progress read. Har biri explicit project grant + current membership bilan tekshiriladi; source connector admin bu grantlarni avtomatik olmaydi. Aniq purpose nomlari, concurrent approval va stale draft semantics keyingi yozma reja/specda belgilanadi.

DEC-02 production DoD, calendar/break/resource policy tasdiqlanmagan. Sinovdagi explicit fictional DoD/QA policy kompaniya siyosati deb o‘rnatilmaydi. `grill-me` skill katalogda yo‘q; uning o‘rniga mavjud spetsifikatsiya, specialist tahlili, ushbu decision brief va faqat hal qilinmagan savollar ishlatilmoqda.

## Tekshiriladigan misollar

- Approved 100, accepted 67 → 67%; task Done bo‘lsa ham failed required QA mezoni qo‘shilmaydi.
- Scope100 → scope120: old/current denominator va pin qilingan versionlar alohida.
- Duplicate reject → merge criterion versiyasi: faqat bog‘liq acceptance/test applicability stale; unrelated va historical dalillar saqlanadi.
- No tenant, cross-project FK, revoked purpose/membership, invalid locator, stale approval revision, simultaneous baseline approval, dependency cycle va zero denominator rad/unavailable natija beradi.

AC-06 bu bosqichda faqat dependency va input to‘liqligi tekshiruvi doirasida; forecast/simulation acceptance E07da. Failed required QA — tegishli criterion uchun nol vazn; approved project policy yo‘qligi — acceptance/progress unavailable. Bu ikki holat bir xil natija sifatida berilmaydi.

Real Kanban API/import/write, hosted yoki lokal inference, yangi UI, moliya, forecast va production SSO bu dizayn scopeiga kirmaydi. Ushbu diagramma/dizayn tasdiqlangach written design spec, so‘ng alohida implementation plan ko‘rib chiqiladi. Boshlash ko‘rsatmasi hali ko‘rsatilmagan dizaynni avtomatik tasdiqlamaydi.

## 2026-10-06 tasdiq va bajarilish qaydi

Foydalanuvchi shu v1 image, synthetic backend scope va written design/execution planini tasdiqladi. R1–R5 independently reviewed; R6 final review/dev migrations/rollback smoke passed; synthetic code unit verified, commit pending. Tasdiq real integration/AI/UI/production DoD yoki deploymentga kengaytirilmaydi.
