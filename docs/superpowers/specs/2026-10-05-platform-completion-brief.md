# Lokal monitoring platformasini yakunlash — ishchi brief

Sana: 2026-10-05. Holat: E02 diagrammasi/briefi ko‘rsatilgach foydalanuvchi ishni boshlashni buyurdi; saqlangan E02 rejasini ko‘rsatgach yana “Ishni boshla” dedi. E02 lokal slice implementatsiyasi tasdiqlangan. Boshqa yangi UI/modul rasmlari va real integratsiya qarorlari uchun tasdiq avtomatik qo‘llanmaydi.

## Vakolat va maqsad

Foydalanuvchi qolgan ishlarni superpowers va AGENTS.md bo‘yicha boshlash va tugatishni buyurdi. Maqsad: xodim, loyiha va bo‘lim bo‘yicha dalilli lokal monitoring, inson tekshiruvi va cheklovlari ko‘rinadigan hisobotlar. Ushbu topshiriq hali ko‘rsatilmagan rasmlarni tasdiqlamaydi; push, merge, server deploy va haqiqiy moliya yoqilishini tasdiqlamaydi. Commit uchun birlik yakunida mavjud scope vakolati tekshiriladi.

Manba: docs/requirements/AI_monitoring_Texnik_spetsifikatsiya_v1_0.docx; FR/NFR/AC va E01–E10. Lokal-only AI qarori spetsifikatsiyadagi tashqi provider variantini bekor qiladi.

## Tekshirilgan boshlang‘ich holat

Branch monitoring-platform-foundation; boshlang‘ich ishchi daraxt toza. Backend yakunlash commiti e06bf29, dashboard/demo commiti 804f620. Laravel/PostgreSQL tenant, identity, organization/project/access/audit poydevori mavjud. AI faqat synthetic JSON kontrakt; frontend mustaqil fixture demo. 97 test/1934 assertion 2026-10-03 tarixiy dalili, bugungi run emas.

## Yetkazish birliklari

| Birlik | Natija | Talab/qabul |
| --- | --- | --- |
| E01 qolgan lokal poydevor | Pilot policy qarorlari, token lifecycle va readiness; SSO/backup/restore alohida tekshirilgan scope | FR-200, AC-09, NFR-005 |
| E02 | Read-only connector registry, durably accepted events/outbox, idempotency, worker recovery, quarantine, freshness/backfill/reconciliation | FR-040/050/220; AC-01/02/10 |
| E03 | Versioned requirements/criteria/baselines, tasks/dependencies/DoD, evidence links/invalidation | FR-030/080/150; AC-05/06/08 |
| E04/E05 | Developer analysis and QA/BA/PM/department workflows | FR-060/070/090/100/120; AC-03/04/07/11 |
| E06/E08 | Role profiles/effort, authorized knowledge, daily/weekly reports and user-requested monthly reports, review/dispute/export/deletion | FR-130/140/170/180/210; AC-09/12/14/15 |
| E07 | Accepted progress, scenario forecast, deterministic risk lifecycle/notifications | FR-150/190; AC-05/06/13 |
| E09 | Representative role/language quality gates, offline/security/load/recovery tests, six-role UAT and release evidence | FR-230; AC-16/17/18; NFR-001–006/010 |
| E10 sandbox | Fictional pools, exact allocation, independent approval/dispute/finalization | FR-300–304; AC-19–24; real activation requires DEC-06/07/08 |

Frontend production stack is an open project decision. Six role dashboards, UZ/RU/EN, project responsibility/lifecycle views and daily/weekly/monthly reports need new image approval. Existing overview approval is reused only for its original scope.

## Arxitektura yondashuvlari

Tavsiya: mavjud modular Laravel/PostgreSQL asosini kengaytirish; private FastAPI va durable Python workers bilan explicit versioned JSON contracts. DB source of truth; outbox DB transaction ichida, Redis durability o‘rnini bosmaydi. Synthetic fixtures lokal sinov uchun; real connector natijasi deb ko‘rsatilmaydi.

Muqobil: barcha analyticsni Laravelga joylash — komponent kamroq, ammo lokal model/parser/evaluation chegaralarini qiyinlashtiradi. Keng mikroservisga bo‘lish — mustaqil deploy beradi, lekin hozirgi lokal pilot uchun operatsion va transaction xarajatlari ortadi. Spetsifikatsiya baseline va mavjud kod sabab modular variant tavsiya qilinadi.

## Ochiq qarorlar va chegaralar

- DEC-02: calendars, breaks, resource allocation, DoD. Taxminlar tasdiqlangan policy deb ishlatilmaydi.
- DEC-03: GitLab edition/version/endpoint, tracker, QA/attendance systems, read-only scopes and data owner permission. Sirlar chatga yoki Gitga kiritilmaydi.
- DEC-04: production frontend, Python/runtime/pgvector, local models/dimensions/budgets and measured hardware capacity. Existing backend versions are preserved pending compatible need.
- DEC-05: permitted personal views, employee notice, review/dispute ownership and retention decisions.
- DEC-01: infrastructure/data handling policy remains relevant, external inference prohibited regardless of later provider approval.
- DEC-06/07/08: real financial activation separately gated; sandbox uses fictional values.

grill-me and ui-ux-pro-max are absent from the available skill catalog. Focused requirements questions and a written decision record are the proposed grill-me alternative. For UI, a specialist-led brief, deterministic mockup images and screenshot review are the proposed alternative; user agreement is required before that dependent UI stage.

## Birinchi dizayn birligi: E02 ishonchli qabul

Roles: connector administrator configures sources without receiving employee/code/report access; authorized application actor sees scoped facts; workers have least privilege and trusted tenant/project context. Source systems remain read-only.

Flow: verify authentication and payload bounds → store immutable payload reference → atomic canonical event/idempotency/outbox transaction → acknowledge → dispatch durable job → normalize approved identities/time/version → reconcile and publish scoped facts. Unresolved identities and invalid payloads remain quarantined with reason. Source-instance/object/version keys distinguish installations and duplicates. Old/late events preserve history and cannot overwrite newer state. Acknowledgement is after durable storage; delivery is at least once with idempotent effects, never an exactly-once claim.

Acceptance: replay one webhook three times plus backfill yields one logical event; stop worker after commit and recover without lost events; forged request rejected; missing identity, source outage and truncated diff remain visible. Tests use isolated PostgreSQL and synthetic payloads until real source decisions arrive. Real integrations must be verified against selected official source capabilities, not guessed.

## Tasdiq va keyingi ish

First image: docs/design-mockups/e02-ingestion-flow-v1.png. Tasdiq doirasi: docs/superpowers/plans/2026-10-05-e02-local-ingestion-plan.md bo‘yicha synthetic lokal connector/acceptance/outbox/projection slice. Foydalanuvchining ko‘rsatilgan dizayn/rejadan keyingi “Ishni boshla” ko‘rsatmasi bilan subagent-driven execution boshlandi. Real Kanban faqat read-only ko‘rikka ruxsatli; API tekshirilmagan. Bu E02–E10 to‘liq implementatsiya qilindi degani emas.
