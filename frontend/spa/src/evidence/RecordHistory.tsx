import { useEffect, useRef } from 'react';
import { historyPath, parsePage, type HistoryLocator } from '../api/readAdapters';
import { readText } from '../i18n/read';
import { t, type Locale } from '../i18n';
import { Fields, PageCoverage, ReadBoundary, useRead } from '../features/readWorkspace';
import { useState } from 'react';
import type { ApiError } from '../api/client';

export function RecordHistory({projectId,scopeGeneration,locator,locale,onClose,onDenied}:{projectId:string;scopeGeneration:number;locator:HistoryLocator;locale:Locale;onClose:()=>void;onDenied:(error:ApiError)=>void}) {
  const [cursor,setCursor]=useState<string|null>(null);
  const title=useRef<HTMLHeadingElement>(null);
  const {state,retry}=useRead(historyPath(projectId,locator,cursor),scopeGeneration,value=>parsePage(value,locator.domain,true),error=>{
    if([401,403,404,409].includes(error.status)) onDenied(error);
  });
  useEffect(()=>{title.current?.focus();},[]);
  return <section className="history-panel" aria-labelledby="history-title" onKeyDown={event=>{if(event.key==='Escape') onClose();}}>
    <div className="section-heading"><h2 id="history-title" ref={title} tabIndex={-1}>{readText(locale,'history')}</h2><button onClick={onClose}>{readText(locale,'closeHistory')}</button></div>
    <p className="record-id">{locator.domain}{locator.domain==='qa'?` / ${locator.kind}`:''} · {locator.id}</p>
    <ReadBoundary state={state} retry={retry} locale={locale}>{page=><>
      <PageCoverage page={page} locale={locale}/>
      {!page.items.length && <p>{readText(locale,'empty')}</p>}
      {page.items.map((row,index)=><article className="record-card" key={`${row.id??row.event_id??index}:${row.revision??row.source_revision}`}>
        <Fields record={row} fields={historyFields[locator.domain]} locale={locale} labels={locator.domain==='ba' && row.kind==='context.add'?{context:readText(locale,'humanContext')}:undefined}/>
      </article>)}
      {page.next_cursor && <button onClick={()=>setCursor(page.next_cursor)}>{t(locale,'more')}</button>}
    </>}</ReadBoundary>
  </section>;
}
const historyFields={
  qa:['id','revision','source_revision','event_type','state','outcome','decision','owner_id','effective_at','received_at','content_hash'],
  ba:['id','clarification_id','revision','kind','state','request_version_id','answer_version_id','subject_answer_version_id','review_id','context','reason','request','answer','links','responsibilities'],
  management:['action_id','event_id','revision','operation','state','support_status','review_status','action_version_id','action_hash','assignment_id','owner_id','helper_id','requester_id','subject_support','support','current_projection','review_id','reviewer_id','reviewed_at','action_occurred_at','recorded_at','reason','context','applicability','current_applicability','current_outcome','underlying_dependency_status','reference_results','responsibility','elapsed_seconds'],
};
