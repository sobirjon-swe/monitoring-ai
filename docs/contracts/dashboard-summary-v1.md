# Dashboard summary v1

GET /browser/v1/projects/{project}/dashboard-summary. Cookie session, exact UUID, no query/body. Requires current project.read and progress.read. Returns data wrapper with version dashboard-summary-v1, project_id, observed_at UTC, synthetic true only for pinned Uzbek fixture tenant+three project IDs (null elsewhere), ai_interpretation unavailable.

Blocks all use coverage_status complete|unknown|unavailable, basis, counts(object only when complete, otherwise null), reason_codes. Zero is a real count; unknown/unavailable is never zero.

- tasks / current_native_task_versions: unstarted,in_progress,blocked,implemented_awaiting_verification,done,total. Requires requirements.read. Counts current task heads once; sentinel1001refuses complete results over1000 or missing head/status. Done is not human acceptance.
- qa / approved_plan_criteria: executed_criteria,required_criteria,all_required_passed_criteria,fully_executed_criteria,excluded_criteria,omitted_criteria. Requires qa.read+qa.source.read and native current eligibility. Executed includes failures; passed is distinct. Incomplete/native omitted metrics return unknown/null.
- dependencies.qa / current_authorized_qa_handoffs: open,total. Requires qa.read+qa.source.read. Native queue open and acknowledged remain unresolved; total is only current authorized queue records, not historical/all handoffs.
- dependencies.ba / current_authorized_ba_clarifications: open,total. Requires ba.read+ba.source.read. Open means unresolved and not withdrawn within current authorized reader scope. No hidden project-wide totals.

Queues fully traverse at most1000distinct eligible records (21calls max, allowing finalemptyBApage); repeated cursor/duplicate/stale/partial/overflow gives unknown/null. Project lifecycle coordination lock covers composed read. Viewer purposes checked around blocks and at delivery; exact native QA/BA original proofs retained in opt-in ReadDeliveryFence and asserted at a final DBtimestamp. Expiry yields original_authority_expired/null for affected block. Perblock proof limits10000claims,8MiBmetadata,64calls fail closed. Binding removed on every exit. Normal native reads outside summary do not bind a fence.

Existing /progress remains canonical accepted BASELINE WEIGHT. Browser capabilities adds optional synthetic boolean|null attestation from same pinned-scope helper, enabling provenance before metrics load. No permission granted by metadata. Frontend rejects wrong project/version, inconsistent count sums/ratios, negative/unsafe counts or unknown-with-counts. Whole endpoint403/404 from either progress/summary invalidates both; transient errors and perblock unavailable remain independent. No historical time series supplied.
