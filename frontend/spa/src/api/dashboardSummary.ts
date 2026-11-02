import {invalid,object,strings} from './readAdapters';
export type SummaryBlock<T>={coverage_status:'complete'|'unknown'|'unavailable';basis:string;counts:T|null;reason_codes:string[]};
export type TaskCounts={unstarted:number;in_progress:number;blocked:number;implemented_awaiting_verification:number;done:number;total:number};
export type QaCounts={executed_criteria:number;required_criteria:number;all_required_passed_criteria:number;fully_executed_criteria:number;excluded_criteria:number;omitted_criteria:number};
export type DependencyCounts={open:number;total:number};
export type DashboardSummary={version:'dashboard-summary-v1';project_id:string;observed_at:string;synthetic:boolean|null;ai_interpretation:'unavailable';tasks:SummaryBlock<TaskCounts>;qa:SummaryBlock<QaCounts>;dependencies:{qa:SummaryBlock<DependencyCounts>;ba:SummaryBlock<DependencyCounts>}};
const id=/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
function block<T>(value:unknown,basis:string,keys:string[]):SummaryBlock<T>{
 const v=object(value);const reasons=strings(v.reason_codes);
 if(v.basis!==basis||!['complete','unknown','unavailable'].includes(String(v.coverage_status))||reasons.length>64||reasons.some(r=>r.length>160||!r.length))invalid();
 if(v.coverage_status==='complete') {const counts=object(v.counts);if(Object.keys(counts).length!==keys.length)invalid();for(const key of keys)if(typeof counts[key]!=='number'||!Number.isSafeInteger(counts[key])||Number(counts[key])<0||Number(counts[key])>100000)invalid();}
 else if(v.counts!==null||!reasons.length)invalid();
 return v as unknown as SummaryBlock<T>;
}
export function parseDashboardSummary(value:unknown,projectId:string):DashboardSummary{
 const v=object(value);
 if(!id.test(projectId)||v.project_id!==projectId||v.version!=='dashboard-summary-v1'||typeof v.observed_at!=='string'||!/^\d{4}-\d\d-\d\dT/.test(v.observed_at)||!Number.isFinite(Date.parse(v.observed_at))||!(v.synthetic===null||typeof v.synthetic==='boolean')||v.ai_interpretation!=='unavailable')invalid();
 const tasks=block<TaskCounts>(v.tasks,'current_native_task_versions',['unstarted','in_progress','blocked','implemented_awaiting_verification','done','total']);
 const qa=block<QaCounts>(v.qa,'approved_plan_criteria',['executed_criteria','required_criteria','all_required_passed_criteria','fully_executed_criteria','excluded_criteria','omitted_criteria']);
 const deps=object(v.dependencies);const dependencies={qa:block<DependencyCounts>(deps.qa,'current_authorized_qa_handoffs',['open','total']),ba:block<DependencyCounts>(deps.ba,'current_authorized_ba_clarifications',['open','total'])};
 if(tasks.counts){const c=tasks.counts;if(c.unstarted+c.in_progress+c.blocked+c.implemented_awaiting_verification+c.done!==c.total)invalid();}
 if(qa.counts){const c=qa.counts;if(c.executed_criteria>c.required_criteria||c.fully_executed_criteria>c.executed_criteria||c.all_required_passed_criteria>c.fully_executed_criteria)invalid();}
 for(const b of Object.values(dependencies))if(b.counts&&b.counts.open>b.counts.total)invalid();
 return {...v,tasks,qa,dependencies} as DashboardSummary;
}
