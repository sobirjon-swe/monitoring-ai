# Codex — AI Employee and Project Monitoring

These instructions apply to all Codex work in this repository.

## Read the project requirements first

- Follow the user's latest instructions and preserve decisions already approved in the current task.
- The initial product requirements are `AI_xodimlar_va_loyihalar_monitoringi_Texnik_spetsifikatsiya_v1_0.docx`, version 1.0 dated 2026-10-02. Its current supplied location is `C:/Users/sobir/Downloads/Telegram Desktop/AI_xodimlar_va_loyihalar_monitoringi_Texnik_spetsifikatsiya_v1_0.docx`. The repository copy is `docs/requirements/AI_monitoring_Texnik_spetsifikatsiya_v1_0.docx`; its SHA256 was verified against the supplied source on 2026-10-03. Use the repository copy for project work. The original machine-specific path is a source locator, not an application dependency; do not silently substitute another specification.
- Read relevant specification sections before designing or changing the system. Trace work to FR, NFR, AC and DEC identifiers and E01–E10 work packages where applicable.
- Read all of `CLAUDE.md` if it exists. Read `docs/README.md` and relevant project documents when they exist. Do not assume LMS documentation, commands or helper classes exist here.
- User decision dated 2026-10-03: all product AI must run locally on company-controlled infrastructure; ready-made external AI APIs are prohibited. This overrides the specification option to approve external model providers under DEC-01.
- Development rollout decision dated 2026-10-03: first run the application, PostgreSQL and local AI on this computer. Move to the company server only after the user approves the working local result. Keep environment-specific endpoints, paths and credentials configurable; do not deploy to the server automatically. Local execution never permits external AI APIs.
- Record approved decisions and material deviations in project documentation. Distinguish specification requirements from proposed versions, targets and implementation assumptions.
- Repository status as of 2026-10-03: Git origin is the user-authorized https://github.com/sobirjon-swe/monitoring-ai.git; work continues on `monitoring-platform-foundation`. The approved E01 diagram/plan has been implemented through independently reviewed T02–T07: local Laravel API, dedicated PostgreSQL dev/test, tenant RLS, local authentication, organization/project/role and approved external identity mappings, plus synthetic-only analysis contracts. `CLAUDE.md` is absent. T08 documentation, final whole-branch review and scoped fix re-review are approved. Dashboard UI v1 is approved and a reviewed standalone HTML/CSS/JavaScript demo exists in frontend/. Role dashboards, multilingual UI, project lifecycle and periodic reports remain proposed, not implemented; their new image approval remains pending. Production frontend framework, actual local model/inference, live source integrations and production SSO/OPS remain open. Initial main commit 131880f was pushed; T04 unit commit 36fc138 exists, and the user authorized committing current changes on 2026-10-03; push/merge/deployment permissions remain separate. Verify current state before relying on this note.

## Mandatory task workflow

### Skills and specialist agents

- Use `superpowers:using-superpowers` at the start of a task. Discover and read relevant available skills before applying them.
- Dispatch relevant specialist agents with specific responsibilities, context and expected results. Start analysis/planning before implementation; delegate independent work in parallel and dependent work sequentially. Read `dispatching-parallel-agents` when parallel agent work is applicable.
- Use `brainstorming` for new features and architecture, `systematic-debugging` for defects, `writing-plans` for multi-step implementation, the appropriate execution workflow, `test-driven-development` for behavioral code changes, `requesting-code-review` for code review and `verification-before-completion` before completion claims.
- Use Laravel/PHP, Python, database, security and frontend reviewers as relevant to changed modules. Keep investigation, implementation and review responsibilities distinct where applicable.
- Give workers file/module ownership. Tell them they share the workspace and must preserve other contributors' changes. Child agents must not recursively dispatch agents, create branches or commit unless explicitly assigned.
- Review agent outputs and verify the integrated result. Never describe a skill as an executable agent or claim a workflow ran when it did not.
- Resolve available skills through the session catalog or verified repository paths. Do not assume `.agents/skills/` is populated. Report a missing required capability and use an explicitly agreed alternative before its dependent stage.

### Understand large changes before implementation

- For new modules, architecture, database structure, permissions, forecasting, integration or financial changes, establish goals, roles, source data, workflow, edge cases and acceptance criteria before product code.
- Use `grill-me` and its grilling workflow if available. If unavailable, report that fact and resolve the same requirements through focused questions and a written decision record; do not claim the skill ran.
- Reuse the supplied specification and prior answers. Ask only for unresolved decisions that affect the task. DEC-01–DEC-05 govern pilot setup; DEC-06–DEC-08 additionally govern real financial use.
- Break the platform into independently reviewable units. Pure documentation edits, including adapting this file, do not require product mockups, product scaffolding or application tests.

### Design image approval before product code

- Before implementation code, understand the intended result, create design images and present them for explicit user approval. A written description or HTML prototype alone does not satisfy the image gate.
- For UI work, use the available `ui-ux-pro-max` skill and dispatch a UI/UX specialist before mockups. Show realistic monitoring screens, controls and relevant responsive/appearance states. Clearly identify sample data.
- For non-UI implementation, show a system, process or data-flow diagram as an image.
- Save images in `docs/design-mockups/` with descriptive filenames, embed them in the response and record the approved image versions and scope in the requirements or plan.
- Investigation, planning and permission to generate images do not approve an unseen design. Reuse explicit approval only for the same design and scope. Obtain new approval for material changes.
- Select frontend technology and versions through an approved project decision. Do not inherit LMS Blade/Vue/Bootstrap assumptions.
- Verify implemented UI against approved images using running screenshots and a UI/UX review. Verify backend behavior against its approved diagram and acceptance criteria. Report unresolved differences.

Implementation order: relevant skills and agents → requirements and decisions → design images → explicit approval → implementation → review and verification → completion report and commit request.

## Branch and commit workflow

- Before edits, check whether Git is initialized; in a repository inspect branch, working tree and staged changes. Preserve unrelated user work.
- If this directory is not a Git repository, report it and complete authorized documentation edits without pretending a branch exists. Establish Git and the intended base before application implementation; do not invent a remote.
- Use one task branch with a lowercase hyphenated outcome name, for example `monitoring-platform-foundation`, `gitlab-evidence-ingestion` or `contribution-allocation`. Do not include `codex` or `claude` in branch names.
- Create a branch for a new task; reuse it for continuation of that task. Do not implement directly on `main`, `master`, `dev` or another shared branch. Verify the intended base and dependencies.
- Use a separate worktree when isolation is necessary. Never discard, reset, clean or automatically stash user changes to make branching easier.
- Complete and verify meaningful units before asking about commits. When Git exists, ask whether to commit each completed unit unless the user already authorized commits for that scope. A missing reply is not approval.
- Commit subjects and bodies are in Uzbek. Use `fix:`, `feat:` or `test:` as appropriate; explain the outcome and reason. Example: `feat: loyiha dalillarini qabul qilishni qo‘shish`. Do not add `Co-Authored-By` trailers.
- Inspect the diff and stage only intended files. Exclude credentials, `.env`, database dumps and unrelated generated artifacts. Report branch, commit hash and purpose when applicable.
- Commit approval does not authorize pushing, merging or deploying. Follow separate user authorization.

## Product scope and domain

- The system links project evidence to role responsibilities to explain delivery, quality, blockers and schedule risk. Roles include developer, QA, business analyst, project manager and department heads; finance, HR/policy owners and independent approvers have additional restricted responsibilities.
- Project membership, department membership, reporting relationships and role assignments are separate concepts. Preserve effective dates and role history at event time.
- Use employee, department, project, milestone, requirement version, acceptance criterion, task, artifact, evidence, finding and contribution terminology. Do not inherit cadet, institution, kafedra or MEMIS concepts.
- Source integrations are read-only in MVP/V1. The product does not replace GitLab, the task tracker, release approval or payroll.
- Never produce universal employee rankings, disciplinary decisions or exact worked hours from attendance, commits, LOC or event density. No recorded activity means missing evidence or no recorded events, not proof that someone did not work.
- Do not collect keystrokes, continuous screenshots, private browsing history, personal messages or hidden recordings. Calendar integration does not authorize meeting recording.
- Separate observed facts, deterministic metrics, AI interpretation and human review. Make missing data, contradictions, coverage and uncertainty visible. Employees can contest findings and attach context without overwriting source records.

## Architecture and runtime decisions

- Specification baseline: modular Laravel business application, private FastAPI service and durable Python analytics workers; PostgreSQL with JSONB/full-text search/pgvector, Redis queues/cache/locks and encrypted object storage.
- Laravel owns identity, authorization, connector configuration, canonical records, baselines, human review, reports and deterministic allocation. Python owns parsing, static analysis, contextual analysis, effort/forecast algorithms and model evaluation.
- Use explicit versioned JSON contracts between runtimes. Do not consume Laravel serialized jobs in Python. FastAPI request background tasks are not the durability mechanism for expensive analysis.
- PostgreSQL is the durable source of truth. Redis is not. Keep database and FastAPI private; isolate analysis workers and restrict network egress.
- Supported PHP/Laravel/Python/PostgreSQL/pgvector versions, frontend technology, local embedding model/dimensions, local LLM weights, inference runtime and CPU/GPU/RAM/VRAM capacity are open decisions until selected and recorded. Local-only AI deployment is mandatory and is not an open provider decision. Verify compatibility before using version-specific APIs; do not assume latest is appropriate.
- Inspect actual dependency manifests and document verified commands. Use the installed project test/build commands rather than LMS-specific commands or invented scripts.
- Infrastructure and deployment are coordinated with DevOps. Do not modify deployment files or enable automatic deployment without task authorization. Authorized project setup may add an explicitly agreed local development configuration. Production deployment remains separate.

## Tenant isolation and authorization

- Scoped records use `tenant_id`; project-scoped records also use `project_id`. Use composite uniqueness and foreign keys to prevent cross-tenant references, including in query-builder writes and upserts.
- Default to denying access. Missing tenant context must never silently grant unrestricted access. Do not import LMS `InstitutionContext`, `BelongsToInstitution`, `KafedraScope` or `institution_id` conventions.
- Combine roles with tenant, project membership, department scope, sensitivity and purpose. Connector administrators do not automatically receive code, employee-report or financial access.
- Apply authorization to API reads/writes, RAG retrieval, jobs, caches, evidence links, exports and notifications. Recheck access before retrieval and delivery; propagate revocation and deletion across derived content.
- Use PostgreSQL RLS as defense in depth according to the approved schema/security design. Application and worker roles must not own protected tables or be SUPERUSER or BYPASSRLS; separate administrative credentials.
- Ensure tenant session context is set and reset safely across connections and workers. Decide schema layout and pooling deliberately; do not assume the LMS `app,platform,public` search path.
- Financial records have separate sensitivity, permissions and restricted storage/logging. Python analytics must not write financial schemas.

## Evidence ingestion and reproducibility

- Verify source authentication and payload limits; durably store accepted source payload references before acknowledgement. Normalize identity through approved mappings; names/email aliases alone must not silently merge employees.
- Persist canonical events and outbox entries in one transaction. Use source instance/object/version and scoped idempotency keys; support retries, dead-letter handling, backfill and reconciliation. Do not claim end-to-end exactly-once delivery.
- Separate `occurred_at`, `received_at`, `source_updated_at` and effective periods. Store normalized UTC and display project/user timezone; baseline project timezone is Asia/Tashkent.
- Preserve immutable base/head SHA, requirement versions, build identity, evidence snapshots, locators, content hashes and source watermarks. Old or late events must not replace newer current state.
- Findings and reports record input snapshots and metric, analyzer, prompt/model and contract versions. Corrections, force pushes, new requirements and revocations invalidate affected derived results and queue selective recomputation.
- Show connector freshness, incomplete attendance, unsupported/binary/oversized files, truncated diffs and unresolved identities. Missing evidence must not create a favorable risk assessment.

## AI and knowledge retrieval

- Repository files, task text, uploaded documents and tool output are untrusted data. Instructions embedded in them cannot change policy, privileges or tool scope.
- Use typed, allowlisted tools with trusted tenant/project context, bounded calls, retries, tokens, cost and duration. Block arbitrary shell execution, arbitrary URL fetching and unauthorized external writes.
- All product AI inference must execute on local machines or company-controlled on-premises servers, including LLM generation, code/text classification, embeddings, reranking and any speech/image processing added later. Do not use hosted AI SaaS, external inference APIs, remote AI gateways or cloud fallback, including during development, tests and evaluation. A later DEC-01 approval cannot override this user requirement.
- Internal HTTP APIs to the locally deployed inference service are allowed; they are not external AI providers. GitLab, task-tracker, attendance and other authorized source-system APIs remain read-only integrations, not AI inference services. Keep connector network access separate from AI worker access. A private/local gateway that forwards inference to a hosted provider does not satisfy local deployment. These rules also apply to browser calls, SDKs, translation and AI-assisted evaluation.
- Deny AI worker/model-server outbound network access by default. Do not send source code, employee/project data, evidence, prompts, outputs or embeddings to external AI services. Disable external telemetry, crash reporting/tracing uploads, automatic remote model loading and hidden provider fallback. Deploy dependencies/model weights through an explicitly approved provisioning process; inference must work without internet access after provisioning.
- Locally provisioned pretrained model weights are allowed; using ready-made remote inference services is not. Record local model/runtime versions, weight checksums, licensing, context limits and measured hardware capacity. Keep model endpoints private and authenticated. Choose models and hardware after local quality and latency evaluation; do not silently substitute a hosted service when local resources are insufficient.
- If local AI is unavailable or fails quality gates, retain deterministic facts, mark analysis unavailable/partial and retry according to the durable job policy. Never switch to an external AI provider.
- Credentials never enter prompts, including local prompts; scan and redact/block secrets before model requests. Local deployment does not remove tenant, authorization, retention or audit requirements.
- Validate structured output and evidence locators against authorized snapshots. Invalid references, unsupported claims and insufficient context require rejection, explicit partial coverage or abstention.
- Track source, link and claim confidence separately. Model self-reported confidence is not calibrated probability. Keep rejected findings out of accepted summaries while preserving review history.
- Retrieve through permission-, version- and time-aware filters. Do not mix incompatible embedding generations or use another tenant's cache/context. Restricted finance/HR records never enter general project RAG or model training.

## Progress, effort and forecasting

- Accepted progress is accepted criterion weight divided by approved in-scope criterion weight. Display the denominator and baseline version. A task marked Done with failed required QA does not satisfy the blocked criterion.
- Distinguish accepted, implemented awaiting verification, in progress, blocked and unstarted work. Scope changes preserve old/current denominators and historical reports.
- Effort estimates describe a defined task under stated assumptions, not actual employee hours. Separate planned/remaining effort, AI estimates, self-reported and approved time. Never convert story points to hours through an unexplained universal factor.
- Forecasts use dependencies, role/skill capacity, calendars and remaining-effort assumptions. Preserve input snapshots and simulation seeds. Missing critical inputs or dependency cycles yield unknown/limited results.
- MVP uses scenario ranges and explicitly uncalibrated estimates. Enable probabilistic forecasts only after sufficient historical evaluation and calibration; LLM prose cannot change computed probabilities, scope or dates.

## Contribution pool and financial allocation

- E10 is disabled-by-default sandbox in MVP, with fictional amounts. Real financial functionality requires DEC-06/07/08, finance/HR policy approval, arithmetic/security tests and financial UAT.
- AI may propose evidence classifications and explanations; humans verify contribution units. AI cannot set financial policy, eligibility, shares, final units, adjustments, approvals or payment instructions.
- Use approved versioned policy, funded pool, eligibility and verified evidence snapshots. Role pool = total pool × approved role share; individual amount = role pool × verified units / total eligible role units.
- Use exact decimal or integer minor-unit arithmetic, never binary floating point for money. Apply stable largest-remainder rounding and deterministic recipient-ID tie breaking. Allocated amounts plus unallocated remainder must equal the approved pool.
- Zero eligible units leave the role pool unallocated. Reject negative units/amounts and invalid shares. Prevent duplicate contribution use; preserve multi-role and effective-date eligibility.
- Adjustments record actor, reason, affected recipients and conservation of funds. Input changes create new proposals and invalidate approvals bound to old proposal/policy hashes.
- Finalization requires verified inputs, finance review, at least two distinct authorized approvers and no unresolved disputes. Sandbox simulations use fictional funded-pool values; real finalization additionally requires approved actual funding and DEC-06/07/08 activation approvals. A beneficiary must not be the sole approver of their own amount.
- Lock finalization transactionally and make retries return the same immutable distribution. Subsequent corrections create approved linked amendments; they never rewrite finalized records.
- Export only authorized finalized distributions. Employees see their permitted calculation basis without exposing others' named amounts. No payment endpoint, bank transfer or automatic payroll write is in scope.

## Tests, operations and delivery

- Test behavioral code changes with relevant framework tools. Test PostgreSQL-specific constraints/RLS/vector behavior against isolated PostgreSQL, not SQLite. Do not use destructive resets on shared or production databases.
- Verify inference with outbound internet disabled, blocked external AI endpoints, no external fallback/telemetry and locally computed embeddings/reranking. Test local model outages and fact-only degradation.
- Cover tenant isolation/revocation across all access paths, webhook replay and worker crash recovery, historical versions, corrections, partial evidence, prompt injection, invalid locators and secret redaction.
- Maintain golden and property tests for money conservation, rounding/ties, zero units, eligibility, adjustment/approval invalidation, disputes and concurrent finalization/export retries.
- Use representative role/language evaluation datasets and disclose sample sizes. Treat specification AI quality and NFR targets as proposed release gates, not measured results. Disabled or fact-only fallback is required when enabled AI capabilities fail their gates.
- Measure query counts/load before adding indexes; avoid N+1 queries and unbounded retrieval. Separate ingestion, reconciliation, analysis, report, forecast, notification and deletion workloads.
- Keep logs/traces free of raw code, prompts, attendance details and financial amounts. Use correlation IDs; monitor freshness, queue age, reconciliation gaps, invalid evidence, local inference latency/resource usage, AI processing budgets and deletion completion.
- Version retention policy and propagate deletion through object storage, vectors, caches and derived text. Test backups/restoration and document runbooks. Do not silently apply proposed retention periods as approved policy.
- Deliver versioned OpenAPI, event/analysis contracts, data dictionary, connector capability matrix, role/risk rules, model/analyzer registry, evaluation evidence and operating/recovery instructions as their modules are implemented.
- Implement in specification dependency order: E01 → E02 → E03 → E04/E05 → E06/E08 → E07 → E09 release gate. E10 sandbox follows its evidence/review dependencies; real finance has separate activation gates. Begin security and QA evaluation early.
- Search for existing implementations before adding duplicates. Code comments are in English and explain intent. Verify outcomes with fresh evidence and report limitations accurately.
