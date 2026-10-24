import { ApiError } from './client';

export type Coverage = 'complete' | 'partial' | 'unknown' | 'unavailable';
export type Json = null | boolean | number | string | Json[] | { [key: string]: Json };
export type ReadRecord = { [key: string]: Json };
export type Page = {items: ReadRecord[]; next_cursor: string | null; visible_record_count: number | null; coverage_status: Coverage; reasons: string[]; synthetic: boolean};
export type Progress = {status: 'available'|'partial'|'unavailable'; accepted_weight:number|null; total_weight:number|null; percentage_basis_points:number|null; baseline_id:string|null; policy_id:string|null; coverage_reason_codes:string[]; as_of?:string;previous_snapshot?:Json;schedule_coverage?:Json};
export type QaVerification = {coverage_status:Coverage; reason_codes:string[]; synthetic:boolean; qa_verification:ReadRecord; counts:ReadRecord; selection:Json; critical_signals:ReadRecord};
export type Domain = 'qa'|'ba'|'management';
export type QaKind = 'build'|'environment'|'definition'|'plan'|'selection'|'execution'|'defect'|'handoff';
export type HistoryLocator = {domain:'qa'; kind:QaKind; id:string} | {domain:'ba'|'management'; id:string};
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const coverages: Coverage[] = ['complete','partial','unknown','unavailable'];
export function invalid(): never {throw new ApiError('invalid_response');}
export function object(value:unknown):ReadRecord {
  if(!value || typeof value!=='object' || Array.isArray(value)) return invalid();
  if(!json(value,0)) return invalid();
  return value as ReadRecord;
}
function json(value:unknown,depth:number):value is Json {
  if(depth>20) return false;
  if(value===null || typeof value==='string' || typeof value==='boolean') return true;
  if(typeof value==='number') return Number.isFinite(value);
  if(Array.isArray(value)) return value.every(item=>json(item,depth+1));
  return typeof value==='object' && Object.values(value).every(item=>json(item,depth+1));
}
export function strings(value:unknown):string[] {
  if(!Array.isArray(value) || !value.every(item=>typeof item==='string')) return invalid();
  return value;
}
function integer(value:unknown,nullable=false):value is number|null {return (nullable && value===null) || (typeof value==='number' && Number.isSafeInteger(value) && value>=0);}
function id(value:unknown):value is string {return typeof value==='string' && uuid.test(value);}
function nullableId(value:unknown):value is string|null {return value===null || id(value);}
function field(record:ReadRecord,key:string,type:'text'|'id'|'integer') {
  const value=record[key];
  if(type==='text' ? typeof value!=='string' : type==='id' ? !id(value) : !integer(value)) invalid();
}
function enumField(record:ReadRecord,key:string,allowed:string[]) {
  if(typeof record[key]!=='string' || !allowed.includes(record[key])) invalid();
}
export function parseProgress(value:unknown):Progress {
  const row=object(value);
  enumField(row,'status',['available','partial','unavailable']);
  for(const key of ['accepted_weight','total_weight','percentage_basis_points']) if(!integer(row[key],true)) invalid();
  if(!nullableId(row.baseline_id)||!nullableId(row.policy_id)) invalid();
  strings(row.coverage_reason_codes);
  for(const key of ['previous_snapshot','schedule_coverage']) if(row[key]!==undefined && row[key]!==null) object(row[key]);
  if(row.percentage_basis_points!==null && (Number(row.percentage_basis_points)>10000 || row.baseline_id===null || !row.total_weight || row.status==='unavailable')) invalid();
  return row as unknown as Progress;
}
export function parseLifecycle(value:unknown,kind:'requirements'|'tasks'):ReadRecord[] {
  if(!Array.isArray(value)) return invalid();
  return value.map(value=>{
    const row=object(value); field(row,'id','id');
    if(kind==='requirements') {
      field(row,'requirement_id','id'); field(row,'version','integer'); field(row,'draft_revision','integer');
      field(row,'content_hash','text'); field(row,'approved_at','text');
    } else {
      field(row,'version_id','id'); field(row,'revision','integer'); field(row,'title','text');
      field(row,'status','text'); field(row,'content_hash','text'); field(row,'effective_at','text');
      strings(row.expected_test_keys);
    }
    return row;
  });
}
export function parsePage(value:unknown,domain:Domain,history=false):Page {
  const page=object(value);
  if(!Array.isArray(page.items) || !(page.next_cursor===null || typeof page.next_cursor==='string') || !integer(page.visible_record_count,true) || !coverages.includes(page.coverage_status as Coverage) || typeof page.synthetic!=='boolean' || page.ai_interpretation!=='unavailable') invalid();
  const reasons=strings(domain==='management'?page.reasons:page.reason_codes);
  if(page.coverage_status!=='complete' && page.visible_record_count!==null) invalid();
  const items=(page.items as Json[]).map(value=>{
    const row=object(value);
    field(row,domain==='ba'?'clarification_id':domain==='management'?'action_id':'id','id');
    if(history && domain==='qa') {
      if(!integer(row.revision) && !integer(row.source_revision)) invalid();
    } else {
      field(row,'revision','integer');
      if(domain==='qa') {
        field(row,'state','text'); field(row,'handoff_type','text');
        enumField(row,'applicability',['current','stale']); strings(row.reason_codes);
      }
      if(domain==='ba' && !history) {
        field(row,'question','text'); field(row,'clarification_state','text');
        enumField(row,'applicability',['current','stale','revoked','unknown']);
        enumField(row,'dependency_status',['decision_resolved','unresolved']);
        enumField(row,'scope_status',['not_requested','scope_approval_pending','unknown']);
        enumField(row,'answer_review_status',['unanswered','awaiting_review','confirmed','rejected','revoked','retracted']); strings(row.reasons);
        if(row.customer_authenticity!=='not_verified') invalid();
      }
      if(domain==='ba' && history) {field(row,'id','id'); field(row,'kind','text');}
      if(domain==='management') {
        field(row,'event_id','id'); field(row,'operation','text'); field(row,'state','text');
        field(row,'support_status','text'); field(row,'review_status','text');
        strings(row.underlying_dependency_status); strings(row.reason_codes); strings(row.current_reason_codes);
        if(!Array.isArray(row.reference_results)) invalid();
      }
    }
    return row;
  });
  return {items,next_cursor:page.next_cursor as string|null,visible_record_count:page.visible_record_count as number|null,coverage_status:page.coverage_status as Coverage,reasons,synthetic:page.synthetic as boolean};
}
export function parseVerification(value:unknown):QaVerification {
  const row=object(value);
  if(!coverages.includes(row.coverage_status as Coverage) || typeof row.synthetic!=='boolean' || row.ai_interpretation!=='unavailable') invalid();
  strings(row.reason_codes);
  const metric=object(row.qa_verification), counts=object(row.counts), signals=object(row.critical_signals);
  if(metric.basis!=='distinct_included_criteria_with_current_completed_execution' || !['approved_plan','department_authorized_entries'].includes(String(metric.scope_basis)) || !integer(metric.ratio_basis_points,true)) invalid();
  for(const key of ['numerator','denominator','ratio_basis_points']) if(!integer(counts[key],true)) invalid();
  for(const key of ['fully_executed_criterion_count','all_required_passed_criterion_count','excluded_criterion_count','omitted_count']) if(!integer(counts[key])) invalid();
  const criteria=Array.isArray(counts.criteria)?counts.criteria:object(counts.criteria);
  if(!Array.isArray(counts.excluded)) object(counts.excluded);
  strings(signals.codes);
  if(!Array.isArray(signals.items) || !integer(signals.omitted_count) || typeof signals.continuation_required!=='boolean') invalid();
  if(row.selection!==null) {
    const selection=object(row.selection);
    for(const key of ['id','plan_id','plan_review_id','build_id','environment_id']) field(selection,key,'id');
    field(selection,'revision','integer');
  }
  for(const [criterionId,value] of Object.entries(criteria)) {
    if(!id(criterionId)) invalid();
    const group=object(value);
    strings(group.reason_codes);
    if(!Array.isArray(group.tests)) invalid();
    for(const value of group.tests as Json[]) {
      const test=object(value);
      field(test,'definition_version_id','id');
      if(!nullableId(test.execution_id)) invalid();
      enumField(test,'status',['passed','failed','blocked','missing','stale','unknown']);
      if(typeof test.required!=='boolean' || typeof test.release_critical!=='boolean') invalid();
      strings(test.reason_codes);
    }
  }
  return row as unknown as QaVerification;
}
export function verificationLocators(data:QaVerification):HistoryLocator[] {
  const locators:HistoryLocator[]=[];
  const add=(kind:QaKind,value:Json|undefined)=>{
    if(id(value) && !locators.some(locator=>locator.domain==='qa' && locator.kind===kind && locator.id===value)) locators.push({domain:'qa',kind,id:value});
  };
  if(data.selection) {
    const selection=object(data.selection);
    add('selection',selection.id); add('plan',selection.plan_id); add('build',selection.build_id); add('environment',selection.environment_id);
  }
  for(const group of Object.values(data.counts.criteria as ReadRecord)) {
    for(const value of object(group).tests as Json[]) {
      const test=object(value);
      add('definition',test.definition_version_id); add('execution',test.execution_id);
    }
  }
  for(const value of data.critical_signals.items as Json[]) add('defect',object(value).defect_id);
  return locators;
}
export function locatorFor(domain:Domain,row:ReadRecord):HistoryLocator {
  // A QA queue item is a handoff; it does not authorize arbitrary subject kinds.
  const subject=domain==='qa'?row.id:domain==='ba'?row.clarification_id:row.action_id;
  if(!id(subject)) return invalid();
  return domain==='qa'?{domain,kind:'handoff',id:subject}:{domain,id:subject};
}
export function historyPath(projectId:string,locator:HistoryLocator,cursor?:string|null):string {
  if(!id(projectId)||!id(locator.id)) return invalid();
  if(locator.domain==='qa' && !['build','environment','definition','plan','selection','execution','defect','handoff'].includes(locator.kind)) invalid();
  const suffix=locator.domain==='qa'?`qa/history/${locator.kind}/${locator.id}`:`${locator.domain}/history/${locator.id}`;
  const params=new URLSearchParams({limit:locator.domain==='qa'?'50':'20'});
  if(cursor) params.set('cursor',cursor);
  return `/browser/v1/projects/${projectId}/${suffix}?${params}`;
}
