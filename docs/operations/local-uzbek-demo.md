# Local Uzbek team demo v1

This isolated fictional workspace was explicitly requested/approved2026-11-02. It does not alter old F01 fixture, grants, receipts or names. All model/source integration is local-only and unavailable; canonical synthetic source data is not a real customer or real employee fact.

## Access

App http://127.0.0.1:18080/app. Protected operator credentials: .local/uzbek-demo-v1/credentials-v1.json. Never commit or expose via website. Entries pm=AzizbekKarimov and admin=BehzodRahimov; use Developmenttokeni once, then cookie session. Three permitted projects: DEMO—SavdoCRM, DEMO—Omborboshqaruvi, DEMO—Mijozlarportali. Eight-hour token/grant expiry is fixed in journal; startup/replay never extends it. An old token signs into the old tenant and cannot see newdemo; signout then use newdemo credential.

Admin creates catalog roles; PM assigns existing roles within three authorizeddemo projects. Catalog labels grant no system permissions. Neither demo interactive user gets BA writers, fixture producers, financial or analysis grants. Original tokenless producer/reviewer/assigner authority remains confined to the new fixture to preserve native evidence; users have no authentication subjects/tokens. Matrix and review: backend/app/Domain/Browser/UzbekDemo/REVIEW.md. Native source revocation still invalidates evidence.

## Data

12fictionalemployees,3projects,36project memberships/catalog assignments,12tasks,3approved requirements,3QA handoffs/BA questions/completed support outcomes. First2projects have weight100baseline,40accepted through source receipt→evidence proposal/review→mandatoryQA observation→humanacceptance. Native QA plan has3included criteria,2executed,1all-requiredpassed. Eachprojecttasks:1done,1in_progress,1blocked,1unstarted (awaitingverification0 is realzero). Thirdprojectmissingbaseline/plan deliberately gives unavailable/unknown, while authorized task/dependency counts remain valid. No employee performance score/workhours/trend fabricated.

Names: AzizbekKarimov, MadinaRasulova, SardorIsmoilov, DilnozaQodirova, JasurTursunov, ShahnozaErgasheva, BehzodRahimov, ZilolaAkbarova, OtabekMirzayev, NigoraSaidova, DiyorbekAbdullayev, MohiraYusupova. Database/UI retain natural spaces.

## Setup/recovery

Pinned manifest backend/tests/Fixtures/uzbek-demo-v1.json SHA256bbd0d458fb183a84528d9da7049d7bdc3c98f3923b9b72d6fd3044300f6affe9. Tenant f1200000-0000-4000-8000-000000000001; projects ...010/011/012. Explicit root-reviewed command app:setup-local-uzbek-demo --manifest=<absolute pinned path> --credentials-file=<new absolute owner-only NTFS output>. DEVlocalguard/marker/runtime role/searchpath/port required; refusesTESTCLI/production. TESTservice is isolated. No migration introduced. Owner-only DACL output uses existing protected pending journal primitive; never delete/rewrite journal to bypass refusal. Exact canonical snapshot and credentials checked for replay; expired/conflicting/changed state refuses without renewal. Real user writes can legitimately make original setup replay refuse.

Selected DEV setup succeeded after independent security/matrix review. Before/after old F01 canonical fingerprints across113tenant tables were identical immediately after setup. Later normal signout can change old browser session records; that is outside seed preservation comparison. No oldpermission or credential renewal occurred in this task. No server deployment/commit/push/merge performed.
