import { useEffect, useRef, useState } from 'react';
import { ApiError, type BaResult } from '../../api/client';
import { historyPath, parsePage, type ReadRecord } from '../../api/readAdapters';
import { useSession } from '../../auth/SessionProvider';
import { t, type Locale } from '../../i18n';
import { BaContextForm } from './BaContextForm';
import { Fields, PageCoverage, ReadBoundary, useRead } from '../readWorkspace';

const translations = {
  uz: {open:'Kontekst qo‘shish', title:'Kontekst qo‘shish', field:'Kontekst', hint:'Majburiy; ko‘pi bilan 4000 UTF-8 bayt.', warning:'Mijoz haqiqiyligi tekshirilmagan.', submit:'Saqlash', pending:'Tekshirilmoqda…', empty:'Kontekst kiriting.', tooLong:'Kontekst 4000 baytdan oshdi.', unknown:'Natija noma’lum. Faol urinishni tekshiring.', recover:'Urinishni tekshirish', active:'Faol urinish mavjud. Uning natijasini tekshiring.', execute:'Natijani tekshirish', done:'Kontekst saqlandi. Tasdiqlashdan oldin natijani ko‘rib chiqing.', ack:'Tasdiqni qabul qilish', conflict:'Ma’lumot o‘zgargan yoki boshqa urinish faol. Joriy holatni tekshiring.', recheck:'Joriy holatni tekshirish', failed:'Amal bajarilmadi. Qayta tekshiring.', count:'bayt'},
  ru: {open:'Добавить контекст', title:'Добавить контекст', field:'Контекст', hint:'Обязательно; не более 4000 байт UTF-8.', warning:'Подлинность клиента не проверена.', submit:'Сохранить', pending:'Проверка…', empty:'Введите контекст.', tooLong:'Контекст превышает 4000 байт.', unknown:'Результат неизвестен. Проверьте активную попытку.', recover:'Проверить попытку', active:'Есть активная попытка. Проверьте её результат.', execute:'Проверить результат', done:'Контекст сохранён. Просмотрите результат перед подтверждением.', ack:'Подтвердить квитанцию', conflict:'Данные изменились или активна другая попытка. Проверьте текущее состояние.', recheck:'Проверить состояние', failed:'Операция не выполнена. Проверьте снова.', count:'байт'},
  en: {open:'Add context', title:'Add context', field:'Context', hint:'Required; at most 4000 UTF-8 bytes.', warning:'Customer authenticity is not verified.', submit:'Save', pending:'Checking…', empty:'Enter context.', tooLong:'Context exceeds 4000 bytes.', unknown:'The outcome is unknown. Check the active attempt.', recover:'Check attempt', active:'An attempt is active. Check its result.', execute:'Check result', done:'Context saved. Review the result before acknowledging it.', ack:'Acknowledge receipt', conflict:'The record changed or another attempt is active. Check the current state.', recheck:'Check current state', failed:'The operation failed. Check again.', count:'bytes'},
};
type Phase='checking'|'ready'|'busy'|'unknown'|'active'|'done'|'conflict';
const extra = {
  uz:{receipt:'Tasdiq identifikatori',event:'Hodisa identifikatori',history:'Ruxsatli tarix',historyLimit:'Tarix qisman yoki sahifalangan bo‘lishi mumkin; hodisa ushbu sahifada ko‘rinmasligi mumkin.',visible:'Hodisa ushbu sahifada ko‘rinadi.',notVisible:'Hodisa ushbu sahifada ko‘rinmaydi. Hodisani ko‘rmasdan tasdiq asosida belgilashingiz mumkin.',rejected:'Urinish band qilinishidan oldin rad etildi. Kontekstni tuzating.',terminal:'Urinish rad etildi va yopildi. Joriy yozuvni tekshiring.',current:'Qayta olingan joriy yozuv',review:'Joriy yozuvni ko‘rdim'},
  ru:{receipt:'Идентификатор квитанции',event:'Идентификатор события',history:'Доступная история',historyLimit:'История может быть неполной или разбитой на страницы; событие может отсутствовать на этой странице.',visible:'Событие видно на этой странице.',notVisible:'Событие не видно на этой странице. Можно подтвердить квитанцию, не видя событие.',rejected:'Запрос отклонён до резервирования попытки. Исправьте контекст.',terminal:'Попытка отклонена и закрыта. Проверьте текущую запись.',current:'Обновлённая текущая запись',review:'Я просмотрел текущую запись'},
  en:{receipt:'Receipt ID',event:'Event ID',history:'Authorized history',historyLimit:'History may be incomplete or paginated; the event may not appear on this page.',visible:'Event is visible on this page.',notVisible:'Event is not visible on this page. You may acknowledge the receipt without seeing the event.',rejected:'Request rejected before claiming. Correct the context.',terminal:'Attempt rejected and closed. Check the current record.',current:'Refreshed current record',review:'I have reviewed the current record'},
};
export function BaAttemptPanel({projectId,clarificationId,revision,scopeGeneration,locale,onRefresh,onDenied,queuePath}:{projectId:string;clarificationId:string;revision:number;scopeGeneration:number;locale:Locale;onRefresh:()=>void;onDenied:(error:ApiError)=>void;queuePath:string}) {
  const {client,isCurrent}=useSession(); const s=translations[locale];
  const [open,setOpen]=useState(false), [phase,setPhase]=useState<Phase>('checking'), [draft,setDraft]=useState(''), [currentRevision,setRevision]=useState(revision), [attempt,setAttempt]=useState('');
  const [result,setResult]=useState<BaResult|null>(null), [historyReady,setHistoryReady]=useState(false), [rejected,setRejected]=useState<'claim'|'terminal'|null>(null), [reviewRow,setReviewRow]=useState<ReadRecord|null>(null);
  const frozen=useRef<{revision:number;context:string}|null>(null), running=useRef(false), alive=useRef(true), uncertainClaim=useRef(false);
  useEffect(()=>{alive.current=true;return ()=>{alive.current=false;};},[]);
  const current=()=>alive.current && isCurrent(scopeGeneration);
  const failure=(error:unknown, fallback:Phase='unknown')=>{
    if(!current()) return;
    if(error instanceof ApiError && (error.status===401 || error.status===403)){setDraft('');frozen.current=null;setAttempt('');onDenied(error);return;}
    setPhase(error instanceof ApiError && error.status===409?'conflict':fallback);
  };
  async function status() {
    if(running.current)return; running.current=true; setPhase('checking');
    try {const slot=await client.showBaAttempt(projectId,clarificationId);if(current()){setAttempt(slot.attempt_id);setPhase('active');}}
    catch(error){if(!current())return;if(error instanceof ApiError && error.status===404){setAttempt('');setPhase(frozen.current?'unknown':'ready');}else failure(error);}
    finally{running.current=false;}
  }
  useEffect(()=>{if(open)void status();},[open]);
  async function claim() {
    if(running.current)return; running.current=true; setPhase('busy');
    const snapshot=frozen.current ?? {revision:currentRevision,context:draft}; frozen.current=snapshot;
    try {const slot=await client.claimBaAttempt(projectId,clarificationId,snapshot.revision,snapshot.context);if(current()){setAttempt(slot.attempt_id);setRejected(null);setPhase('active');}}
    catch(error){
      if(current() && error instanceof ApiError && [413,422].includes(error.status) && !uncertainClaim.current){frozen.current=null;setRejected('claim');setPhase('ready');}
      else {uncertainClaim.current=true;failure(error);}
    }
    finally{running.current=false;}
  }
  async function execute(){
    if(running.current)return;running.current=true;setPhase('busy');
    try{const outcome=await client.executeBaAttempt(projectId,clarificationId,attempt);if(current()){setResult(outcome);setHistoryReady(false);setPhase('done');}}
    catch(error){if(current() && error instanceof ApiError && error.code==='attempt_rejected'){frozen.current=null;uncertainClaim.current=false;setAttempt('');setRejected('terminal');setPhase('conflict');}else failure(error);}
    finally{running.current=false;}
  }
  async function ack(){
    if(running.current)return;running.current=true;setPhase('busy');
    try{await client.ackBaAttempt(projectId,clarificationId,attempt);if(current()){frozen.current=null;setDraft('');setAttempt('');onRefresh();}}
    catch(error){failure(error);}
    finally{running.current=false;}
  }
  async function recheck(){
    if(running.current)return;running.current=true;setPhase('checking');
    try{
      try {const slot=await client.showBaAttempt(projectId,clarificationId);if(current()){setAttempt(slot.attempt_id);setPhase('active');}return;}
      catch(error){if(!(error instanceof ApiError && error.status===404))throw error;}
      const page=parsePage(await client.request(queuePath), 'ba'); const row=page.items.find(item=>item.clarification_id===clarificationId);
      if(!row || typeof row.revision!=='number')throw new ApiError('invalid_response');
      if(current()){setRevision(row.revision);frozen.current=null;uncertainClaim.current=false;setAttempt('');setReviewRow(row);setPhase('conflict');}
    }catch(error){failure(error,'conflict');}finally{running.current=false;}
  }
  if(!open)return <button onClick={()=>setOpen(true)}>{s.open}</button>;
  return <div>
    {(phase==='ready'||phase==='conflict') && <BaContextForm value={draft} onChange={setDraft} onSubmit={()=>void claim()} submitBlocked={phase!=='ready'} error={rejected==='terminal'?extra[locale].terminal:phase==='conflict'?s.conflict:rejected==='claim'?extra[locale].rejected:undefined} labels={{...s,count:n=>`${n} / 4000 ${s.count}`}}/>}
    {(phase==='checking'||phase==='busy') && <p role="status">{s.pending}</p>}
    {phase==='unknown' && <><p role="alert">{s.unknown}</p><button onClick={()=>void status()}>{s.recover}</button>{frozen.current && <button onClick={()=>void claim()}>{s.submit}</button>}</>}
    {phase==='active' && <><p role="status">{s.active}</p><button onClick={()=>void execute()}>{s.execute}</button></>}
    {phase==='done' && result && <><p role="status">{s.done}</p><p>{extra[locale].receipt}: <span>{result.receipt_id}</span></p><p>{extra[locale].event}: <span>{result.event_id}</span></p><AttemptHistory projectId={projectId} clarificationId={clarificationId} eventId={result.event_id} scopeGeneration={scopeGeneration} locale={locale} onReady={setHistoryReady} onDenied={onDenied}/><button disabled={!historyReady} onClick={()=>void ack()}>{s.ack}</button></>}
    {phase==='conflict' && (reviewRow ? <><h4>{extra[locale].current}</h4><Fields record={reviewRow} fields={['revision','question','clarification_state','applicability','answer_review_status','scope_status','dependency_status','answer','reasons']} locale={locale}/><button onClick={()=>{setReviewRow(null);setPhase('ready');}}>{extra[locale].review}</button></> : <button onClick={()=>void recheck()}>{s.recheck}</button>)}
  </div>;
}

function AttemptHistory({projectId,clarificationId,eventId,scopeGeneration,locale,onReady,onDenied}:{projectId:string;clarificationId:string;eventId:string;scopeGeneration:number;locale:Locale;onReady:(ready:boolean)=>void;onDenied:(error:ApiError)=>void}) {
  const [cursor,setCursor]=useState<string|null>(null);
  const path=historyPath(projectId,{domain:'ba',id:clarificationId},cursor);
  const {state,retry}=useRead(path,scopeGeneration,value=>parsePage(value,'ba',true),error=>{onReady(false);if([401,403,404,409].includes(error.status))onDenied(error);});
  useEffect(()=>{onReady(state.status==='ready');},[state.status,onReady]);
  return <section><h4>{extra[locale].history}</h4><p>{extra[locale].historyLimit}</p><ReadBoundary state={state} retry={()=>{onReady(false);retry();}} locale={locale}>{page=><><PageCoverage page={page} locale={locale}/><p role="status">{page.items.some(row=>row.id===eventId)?extra[locale].visible:extra[locale].notVisible}</p>{page.items.map((row,index)=><article key={String(row.id??index)}><Fields record={row} fields={['id','revision','kind','state','context','reason']} locale={locale} labels={row.kind==='context.add'?{context:locale==='uz'?'Kontekst':locale==='ru'?'Контекст':'Context'}:undefined}/></article>)}{cursor && <button onClick={()=>{onReady(false);setCursor(null);}}>{locale==='uz'?'Birinchi sahifa':locale==='ru'?'Первая страница':'First page'}</button>}{page.next_cursor && <button onClick={()=>{onReady(false);setCursor(page.next_cursor);}}>{t(locale,'more')}</button>}</>}</ReadBoundary></section>;
}
