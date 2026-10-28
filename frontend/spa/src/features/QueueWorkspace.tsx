import { useCallback, useRef, useState } from 'react';
import { ApiError } from '../api/client';
import { locatorFor, parsePage, type Domain, type HistoryLocator } from '../api/readAdapters';
import { RecordHistory } from '../evidence/RecordHistory';
import { t } from '../i18n';
import { readText } from '../i18n/read';
import { Fields, PageCoverage, ReadBoundary, Reasons, useRead, type WorkspaceProps } from './readWorkspace';
import { BaAttemptPanel } from './ba/BaAttemptPanel';

export function QueueWorkspace({projectId,scopeGeneration,locale='uz',domain,onFailure,canWrite=false}:WorkspaceProps & {domain:Domain;onFailure?:(error:ApiError)=>void;canWrite?:boolean}) {
  const [cursor,setCursor]=useState<string|null>(null);
  const [locator,setLocator]=useState<HistoryLocator|null>(null);
  const [denied,setDenied]=useState<ApiError|null>(null);
  const origin=useRef<HTMLButtonElement|null>(null);
  const query=new URLSearchParams({limit:domain==='qa'?'50':'20'});
  if(cursor) query.set('cursor',cursor);
  const {state,retry}=useRead(`/browser/v1/projects/${projectId}/${domain}/queue?${query}`,scopeGeneration,value=>parsePage(value,domain),onFailure);
  const onDenied=useCallback((error:ApiError)=>{setLocator(null);setDenied(error);onFailure?.(error);},[onFailure]);
  function close() {setLocator(null);origin.current?.focus();}
  if(denied) return <ReadBoundary state={{status:'error',error:denied}} retry={()=>{setDenied(null);setCursor(null);retry();}} locale={locale}>{()=>null}</ReadBoundary>;
  return <ReadBoundary state={state} retry={()=>{setLocator(null);retry();}} locale={locale}>{page=><>
    <PageCoverage page={page} locale={locale}/>
    {domain==='ba' && <p className="warning">{readText(locale,'customerAuthenticity')}</p>}
    {domain==='management' && <p className="warning">{readText(locale,'supportMeaning')}</p>}
    {!page.items.length && <p>{readText(locale,'empty')}</p>}
    <div className="record-grid">{page.items.map(row=>{
      const recordLocator=locatorFor(domain,row);
      return <article key={recordLocator.id} className="record-card">
        <h3 className="record-id">{recordLocator.id}</h3>
        <Fields record={row} fields={queueFields[domain]} locale={locale}/>
        <Reasons codes={(domain==='ba'?row.reasons:row.reason_codes) as string[]??[]} locale={locale}/>
        {domain==='management' && <Reasons codes={row.current_reason_codes as string[]} locale={locale}/>}
        <button aria-label={`${readText(locale,'history')}: ${recordLocator.id}`} onClick={event=>{origin.current=event.currentTarget;setLocator(recordLocator);}}>{readText(locale,'history')}</button>
        {domain==='ba' && canWrite && <BaAttemptPanel projectId={projectId} clarificationId={recordLocator.id} revision={row.revision as number} scopeGeneration={scopeGeneration} locale={locale} queuePath={`/browser/v1/projects/${projectId}/ba/queue?${query}`} onRefresh={()=>{setLocator(null);retry();}} onDenied={onDenied}/>}
      </article>;
    })}</div>
    {page.next_cursor && <button onClick={()=>{setLocator(null);setCursor(page.next_cursor);}}>{t(locale,'more')}</button>}
    {locator && <RecordHistory key={`${locator.domain}:${locator.id}`} projectId={projectId} scopeGeneration={scopeGeneration} locator={locator} locale={locale} onClose={close} onDenied={onDenied}/>}
  </>}</ReadBoundary>;
}
const queueFields={
  qa:['revision','state','handoff_type','owner_id','opened_at','applicability','responsibility','age'],
  ba:['revision','question','clarification_state','applicability','answer_review_status','scope_status','dependency_status','answer','requester_id','coordinator_id','decision_owner_id','request_version_id','answer_version_id','review_id','requested_at','response_at','waiting_on','elapsed_seconds','scope_origin_coverage','links','responsibilities','request_source','answer_source'],
  management:['revision','state','support_status','review_status','underlying_dependency_status','current_outcome','applicability','current_applicability','reason','context','owner_id','helper_id','requester_id','reviewer_id','action_version_id','support','subject_support','current_projection','reference_results','responsibility','original_opened_at','action_occurred_at','recorded_at','elapsed_seconds'],
};
