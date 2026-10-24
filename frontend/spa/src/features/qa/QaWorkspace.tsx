import { useCallback, useRef, useState } from 'react';
import { parseVerification, verificationLocators, type HistoryLocator } from '../../api/readAdapters';
import { ApiError } from '../../api/client';
import { RecordHistory } from '../../evidence/RecordHistory';
import { t } from '../../i18n';
import { readText } from '../../i18n/read';
import { Fields, ReadBoundary, Reasons, Value, WorkspaceHeader, useRead, type WorkspaceProps } from '../readWorkspace';
import { QueueWorkspace } from '../QueueWorkspace';

export function QaWorkspace({projectId,scopeGeneration,locale='uz'}:WorkspaceProps) {
  const [fault,setFault]=useState<ApiError|null>(null);
  const [locator,setLocator]=useState<HistoryLocator|null>(null);
  const origin=useRef<HTMLButtonElement|null>(null);
  const fail=useCallback((error:ApiError)=>{setLocator(null);setFault(error);},[]);
  const {state,retry}=useRead(`/browser/v1/projects/${projectId}/qa/verification`,scopeGeneration,parseVerification);
  return <section><WorkspaceHeader locale={locale} title={t(locale,'qa')} projectId={projectId}/>
    <ReadBoundary state={fault?{status:'error',error:fault}:state} retry={()=>{setFault(null);setLocator(null);retry();}} locale={locale}>{data=><>
      <div className="progress-card"><h2>{readText(locale,'qaCoverage')}</h2><p>{readText(locale,'qaMeaning')}</p>
        <p className={`coverage coverage-${data.coverage_status}`}>{readText(locale,'coverage')}: {readText(locale,data.coverage_status)}</p>
        {data.synthetic && <span className="badge">{readText(locale,'synthetic')}</span>} <span className="badge">{readText(locale,'ai')}</span>
        <p className="metric">{data.qa_verification.ratio_basis_points===null?readText(locale,'unavailable'):`${Number(data.qa_verification.ratio_basis_points)/100}%`}</p>
        <Fields record={data.counts} fields={['numerator','denominator','fully_executed_criterion_count','all_required_passed_criterion_count']} locale={locale}/>
        <Reasons codes={data.reason_codes} locale={locale}/><Reasons codes={data.critical_signals.codes as string[]} locale={locale}/>
        <Fields record={data.critical_signals} fields={['omitted_count','continuation_required']} locale={locale}/>
        <details><summary>{readText(locale,'details')}</summary><Value value={data.selection} field="selection" locale={locale}/><Value value={data.counts.criteria} field="criteria" locale={locale}/><Value value={data.counts.excluded} field="excluded" locale={locale}/></details>
        <div className="history-links">{verificationLocators(data).map(item=><button key={`${item.domain==='qa'?item.kind:''}:${item.id}`} aria-label={`${readText(locale,'history')}: ${readText(locale,item.domain==='qa'?item.kind:'unknown')} ${item.id}`} onClick={event=>{origin.current=event.currentTarget;setLocator(item);}}>{readText(locale,'history')} · {readText(locale,item.domain==='qa'?item.kind:'unknown')}</button>)}</div>
        {locator && <RecordHistory key={`${locator.domain==='qa'?locator.kind:''}:${locator.id}`} projectId={projectId} scopeGeneration={scopeGeneration} locale={locale} locator={locator} onDenied={fail} onClose={()=>{setLocator(null);origin.current?.focus();}}/>}
      </div>
      <h2>{readText(locale,'handoffs')}</h2><p>{readText(locale,'qaScope')}</p>
      <QueueWorkspace projectId={projectId} scopeGeneration={scopeGeneration} locale={locale} domain="qa" onFailure={fail}/>
    </>}</ReadBoundary>
  </section>;
}
