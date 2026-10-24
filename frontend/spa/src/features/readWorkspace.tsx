import { useEffect, useRef, useState, type ReactNode } from 'react';
import { ApiError } from '../api/client';
import { useSession } from '../auth/SessionProvider';
import { formatDate, t, type Locale, type TranslationKey } from '../i18n';
import { readText, fieldText } from '../i18n/read';
import type { Json, Page, ReadRecord } from '../api/readAdapters';

export type WorkspaceProps = {projectId:string; scopeGeneration:number; locale?:Locale};
type ReadState<T> = {status:'loading'} | {status:'ready'; data:T} | {status:'error'; error:ApiError};
export function useRead<T>(path:string,scopeGeneration:number,parse:(value:unknown)=>T,onError?:(error:ApiError)=>void) {
  const {client,isCurrent}=useSession();
  const [attempt,setAttempt]=useState(0);
  const [state,setState]=useState<ReadState<T>>({status:'loading'});
  const current=useRef(0);
  const parseRef=useRef(parse);
  const errorRef=useRef(onError);
  parseRef.current=parse;
  errorRef.current=onError;
  const key=`${scopeGeneration}:${path}:${attempt}`;
  const [stateKey,setStateKey]=useState(key);
  useEffect(()=>{
    const abort=new AbortController();
    const ticket=++current.current;
    setState({status:'loading'});
    setStateKey(key);
    void client.request<unknown>(path,{signal:abort.signal}).then(value=>{
      if(!abort.signal.aborted && ticket===current.current && isCurrent(scopeGeneration)) {
        setState({status:'ready',data:parseRef.current(value)});
      }
    }).catch(error=>{
      if(!abort.signal.aborted && ticket===current.current && isCurrent(scopeGeneration)) {
        const failure=error instanceof ApiError?error:new ApiError('invalid_response');
        // Notify the owning module in the same update batch so a sibling's
        // earlier favorable result cannot survive a new authoritative denial.
        errorRef.current?.(failure);
        setState({status:'error',error:failure});
      }
    });
    return ()=>{abort.abort();++current.current;};
  },[client,isCurrent,key,path,scopeGeneration]);
  // A changed locator must hide old data in the render before effect cleanup.
  return {state:stateKey===key?state:{status:'loading'} as ReadState<T>,retry:()=>setAttempt(value=>value+1)};
}

export function ReadBoundary<T>({state,retry,locale,children}:{state:ReadState<T>;retry:()=>void;locale:Locale;children:(data:T)=>ReactNode}) {
  if(state.status==='loading') return <p role="status">{t(locale,'loading')}</p>;
  if(state.status==='error') return <div role="alert" className="read-error">
    <p>{t(locale,(['forbidden','network','not_found','invalid_request','invalid_response','unauthenticated','too_many_requests'].includes(state.error.code)?state.error.code:'unavailable') as TranslationKey)}</p>
    {state.error.requestId && <p>{t(locale,'requestId',{id:state.error.requestId})}</p>}
    <button onClick={retry}>{t(locale,'retry')}</button>
  </div>;
  return children(state.data);
}
export function WorkspaceHeader({locale,title,projectId}:{locale:Locale;title:string;projectId:string}) {
  const {project}=useSession();
  return <><p className="eyebrow">{t(locale,'readOnly')}</p><h1 tabIndex={-1}>{title}</h1>
    <p className="project-context">{readText(locale,'project')}: <strong>{project?.name}</strong> <span className="record-id">{projectId}</span></p>
    <p className="muted">{readText(locale,'timezone')}</p><p className="privacy-note">{readText(locale,'privacy')}</p></>;
}
export function Reasons({codes,locale}:{codes:string[];locale:Locale}) {
  return codes.length>0?<ul className="reason-list">{[...new Set(codes)].map(code=><li key={code}>{readText(locale,code)} <code>{code}</code></li>)}</ul>:null;
}
export function PageCoverage({page,locale}:{page:Page;locale:Locale}) {
  return <div className={`coverage coverage-${page.coverage_status}`} role="status">
    <strong>{readText(locale,'coverage')}: {readText(locale,page.coverage_status)}</strong>
    <p>{page.visible_record_count===null?readText(locale,'unknownTotal'):`${readText(locale,'visible')}: ${page.visible_record_count}`}</p>
    <Reasons codes={page.reasons} locale={locale}/>
    {page.synthetic && <span className="badge">{readText(locale,'synthetic')}</span>} <span className="badge">{readText(locale,'ai')}</span>
  </div>;
}
const dates=new Set(['effective_at','effective_until','valid_from','valid_to','approved_at','received_at','requested_at','response_at','recorded_at','opened_at','action_occurred_at','reviewed_at','original_opened_at','occurred_at','source_updated_at']);
const codes=new Set(['state','status','applicability','handoff_type','clarification_state','answer_review_status','scope_status','dependency_status','underlying_dependency_status','support_status','review_status','current_applicability','current_outcome','waiting_on','outcome','operation','kind','event_type','basis','scope_basis','coverage_status','role','attribution','age_basis','business_age_reason','reason_code','relation','phase','variant']);
export function Value({value,field,locale}:{value:Json|undefined;field:string;locale:Locale}) {
  if(value===null || value===undefined) return <span className="muted">{readText(locale,'unknown')}</span>;
  if(typeof value==='boolean') return <>{readText(locale,value?'yes':'no')}</>;
  if(typeof value==='number') return <>{value}</>;
  if(typeof value==='string') {
    if(dates.has(field)) return <time dateTime={value}>{formatDate(locale,value)}</time>;
    return <>{codes.has(field)?readText(locale,value):value}</>;
  }
  if(Array.isArray(value)) {
    if(['reason_codes','reasons','codes'].includes(field) && value.every(item=>typeof item==='string')) return <Reasons codes={value as string[]} locale={locale}/>;
    return value.length?<ul className="nested-values">{value.map((item,index)=><li key={index}><Value value={item} field={field} locale={locale}/></li>)}</ul>:<span>—</span>;
  }
  return <Fields record={value} fields={Object.keys(value)} locale={locale}/>;
}
export function Fields({record,fields,locale}:{record:ReadRecord;fields:string[];locale:Locale}) {
  const supplied=fields.filter(key=>Object.hasOwn(record,key));
  const technical=supplied.filter(isTechnicalField);
  const primary=supplied.filter(key=>!isTechnicalField(key));
  const rows=(keys:string[])=><dl className="record-fields">{keys.map(key=><div key={key}><dt>{fieldText(locale,key)}</dt><dd><Value value={record[key]} field={key} locale={locale}/></dd></div>)}</dl>;
  return <>
    {primary.length>0 && rows(primary)}
    {technical.length>0 && <details className="technical-details"><summary>{readText(locale,'technicalDetails')}</summary>{rows(technical)}</details>}
  </>;
}
const provenanceFields=new Set(['responsibility','responsibilities','membership','mapping','roles','links','request_source','answer_source','reference_results','current_projection','expected_test_keys']);
function isTechnicalField(key:string):boolean {
  // Source prose and semantic state stay primary. Identifiers, hashes and
  // nested provenance remain available through native keyboard disclosures.
  return key==='id' || key.endsWith('_id') || key.endsWith('_hash') || provenanceFields.has(key);
}
