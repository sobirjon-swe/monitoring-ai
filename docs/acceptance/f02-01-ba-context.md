# F02-01 BA konteksti — holat va qabul chegarasi

2026-10-28. [Tasdiqlangan v2 dizayn va doira](../superpowers/specs/2026-10-28-f02-ba-context-design.md) mavjud ruxsatli BA aniqlashtirishiga inson konteksti qo‘shishni qamraydi (FR-080/090/100/200, E05-02, AC-08/09/10). Browser attempt koordinatsiyasi amalga oshirilgan. Yakuniy qabul va to‘liq E05/F02 ochiq.

## Browser kontrakti

Yo‘llar asosiy `/browser/v1/projects/{project}/ba/clarifications/{clarification}/attempt` ga nisbatan; path IDlar UUID. Server sessiya, joriy vakolat, loyiha aʼzoligi va original source proofni tekshiradi. Browser Authorization headerini rad etadi; POST same-origin/CSRF himoyasidan o‘tadi. Query va BA control headerlari, jumladan Idempotency-Key va Expected-Revision taqiqlangan; idempotency kaliti serverda yaratiladi.

| Amal | Tana | Natija |
| --- | --- | --- |
| POST asosiy yo‘l (claim) | Aynan clarification_id, integer expected_revision (1–9007199254740991), kind: context.add, context | Yangi claim 201, ayni saqlangan tana qayta claim 200; data ichida attempt_id, state, replayed. Boshqa tana yoki yaroqsiz slot 409. |
| GET asosiy yo‘l | Tana va query yo‘q | 200: data.attempt_id va data.state; mavjud bo‘lmagan, yopilgan, muddati tugagan yoki tombstone slot 404. |
| POST .../execute | Aynan {"attempt_id":"UUID"} | 200: data ichida BA event natijasi; completed natija takror qaytariladi. Ziddiyat 409 attempt_conflict, rad etilgan event 422 attempt_rejected; slot yopiladi. |
| POST .../ack | Aynan {"attempt_id":"UUID"} | Completed attempt yopiladi, ciphertext tozalanadi; 204. Completed bo‘lmagan slot 409. |

Claim ID path IDga teng; context bo‘sh bo‘lmagan, ko‘pi bilan 4000 UTF-8 bayt va secret gate tekshiruvidan o‘tgan matn. Claim JSON ko‘pi bilan 65536 bayt; execute/ack ko‘pi bilan 1024 bayt. Strict JSON duplicate va ortiqcha maydonlarni rad etadi. Umumiy xatolarga 401, 403, 404, 409, 413, 419, 422, 503 va boshqa server xatolari kiradi. Shifrlangan urinish sessiyaga bog‘lanadi, muddati sessiya tugashi yoki 120 daqiqaning oldingisida tugaydi. Noaniq natijada ayni attemptni qayta tekshirish/execute qilish mumkin; sessiya tugagandan keyin kafolatli retry daʼvo qilinmaydi.

## Dalillar va cheklovlar

TEST PostgreSQL fizik commit dalili: 3 test, 40 assertion; mustaqil ulanishda claim ko‘rinishi va qayta ulanish, saqlangan natijani qayta olish va stale execution tekshirilgan. Bu fizik crash yoki haqiqiy parallel poyga sinovi isboti emas. [Cleanup runbook](../operations/f02-attempt-cleanup.md): scheduler local/testing muhitida har daqiqada ishlaydi, faqat scheduler ishlasa va tenant aniq sozlansa; default allowlist bo‘sh. Global fence yozuvlari saqlanadi; bounded fence cleanup ochiq.

ReUI requirement grid [desktop](../design-mockups/reui-running/requirements-desktop-synthetic.png) va [mobil](../design-mockups/reui-running/requirements-mobile-synthetic.png) tasvirlari izolyatsiyalangan Vite komponentida sunʼiy maʼlumotlar bilan olingan. Tasdiqlangan maketdan farq: mavjud to‘q F01 qobig‘i saqlangan, API bermagan sarlavha/status/criterion matnlari to‘qib chiqarilmagan, mobil kartochkalar oralig‘i kengroq. Bu sessiya, API yoki sahifalash UAT emas. [ReUI dizayn qaydi](../superpowers/specs/2026-10-28-reui-lifecycle-grid-proposal.md).

Alohida synthetic writer workspace [token himoyasi taklifi](../superpowers/specs/2026-10-28-f02-synthetic-token-protection-proposal.md) bo‘yicha qaror va texnik isbot kutilgani sabab bloklangan. DEV browser/API UAT, provisioning/recovery va yakuniy qabul isbotlanmagan. Production deployment, live source va AI bu dalillardan kelib chiqmaydi.
