import { createContext, useCallback, useContext, useLayoutEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react';
import { useTable, type ColumnDef } from '@tanstack/react-table';
import type { ReadRecord } from '../../api/readAdapters';
import { t, type Locale } from '../../i18n';
import { readText } from '../../i18n/read';
import { Fields } from '../readWorkspace';
import { DataGrid, DataGridContainer, dataGridFeatures, type DataGridFeatures } from '../../reui-grid/data-grid';
import { DataGridTable } from '../../reui-grid/data-grid-table';

const labels = {
  en: { search:'Search this page', scope:'Search, status and sorting apply only to this loaded page.', status:'Status on this page', all:'All statuses', order:'Sort this page', original:'Original order', asc:'ID ascending', desc:'ID descending', empty:'No matching records on this page.' },
  uz: { search:'Ushbu sahifada qidirish', scope:'Qidiruv, holat va saralash faqat yuklangan sahifaga tegishli.', status:'Ushbu sahifadagi holat', all:'Barcha holatlar', order:'Ushbu sahifani saralash', original:'Asl tartib', asc:'ID o‘sish tartibida', desc:'ID kamayish tartibida', empty:'Ushbu sahifada mos yozuv yo‘q.' },
  ru: { search:'Поиск на этой странице', scope:'Поиск, статус и сортировка применяются только к загруженной странице.', status:'Статус на этой странице', all:'Все статусы', order:'Сортировка этой страницы', original:'Исходный порядок', asc:'ID по возрастанию', desc:'ID по убыванию', empty:'На этой странице нет совпадений.' },
} as const;

const mobileQuery='(max-width: 760px)';
function subscribeMobile(callback:()=>void) {
  if(typeof window.matchMedia!=='function') return ()=>{};
  const media=window.matchMedia(mobileQuery);
  media.addEventListener('change',callback);
  return ()=>media.removeEventListener('change',callback);
}
function isMobile() { return typeof window.matchMedia==='function' && window.matchMedia(mobileQuery).matches; }

const DetailContext=createContext<{expanded:Set<string>;toggle:(id:string)=>void;locale:Locale;view:'requirements'|'tasks'} | null>(null);
function RecordDetails({row}:{row:ReadRecord}) {
  const context=useContext(DetailContext)!;
  const id=String(row.id);
  return <details data-record-id={id} open={context.expanded.has(id)}><summary onClick={event=>{
    event.preventDefault(); context.toggle(id);
  }}>{readText(context.locale,'details')}</summary><Fields record={row} fields={context.view==='requirements'?['requirement_id','version','draft_revision','content_hash','approved_at']:['version_id','revision','status','assignee_id','estimate_basis','remaining_effort','expected_test_keys','content_hash','effective_at']} locale={context.locale}/></details>;
}

export function LifecycleGrid({data,view,locale}:{data:ReadRecord[];view:'requirements'|'tasks';locale:Locale}) {
  const [search,setSearch]=useState('');
  const [status,setStatus]=useState('');
  const [order,setOrder]=useState('original');
  const [expanded,setExpanded]=useState<Set<string>>(()=>new Set());
  const root=useRef<HTMLDivElement>(null);
  const searchInput=useRef<HTMLInputElement>(null);
  const pendingLayout=useRef<{recordId:string|null;technicalIndex:number;tableFocus:boolean;openTechnical:Map<string,number[]>}|null>(null);
  const subscribe=useCallback((callback:()=>void)=>subscribeMobile(()=>{
    const active=document.activeElement;
    const details=active instanceof Element && root.current?.contains(active) ? active.closest<HTMLDetailsElement>('details[data-record-id]') : null;
    const technical=details ? Array.from(details.querySelectorAll<HTMLDetailsElement>('details.technical-details')) : [];
    const focusedTechnical=active instanceof Element ? active.closest<HTMLDetailsElement>('details.technical-details') : null;
    const openTechnical=new Map<string,number[]>();
    root.current?.querySelectorAll<HTMLDetailsElement>('details[data-record-id]').forEach(record=>{
      const id=record.dataset.recordId;
      if(id) openTechnical.set(id,Array.from(record.querySelectorAll<HTMLDetailsElement>('details.technical-details')).flatMap((item,index)=>item.open?[index]:[]));
    });
    pendingLayout.current={recordId:details?.dataset.recordId ?? null,technicalIndex:focusedTechnical ? technical.indexOf(focusedTechnical) : -1,tableFocus:active instanceof Element && !!root.current?.contains(active) && !!active.closest('.table-scroll') && !details,openTechnical};
    callback();
  }),[]);
  const mobile=useSyncExternalStore(subscribe,isMobile,()=>false);
  useLayoutEffect(()=>{
    const pending=pendingLayout.current;
    if(!pending) return;
    const records=Array.from(root.current?.querySelectorAll<HTMLDetailsElement>('details[data-record-id]') ?? []);
    records.forEach(record=>{
      const opened=pending.openTechnical.get(record.dataset.recordId ?? '') ?? [];
      record.querySelectorAll<HTMLDetailsElement>('details.technical-details').forEach((item,index)=>{item.open=opened.includes(index);});
    });
    const details=records.find(element=>element.dataset.recordId===pending.recordId);
    const target=pending.technicalIndex>=0 ? details?.querySelectorAll<HTMLDetailsElement>('details.technical-details')[pending.technicalIndex] : details;
    if(pending.tableFocus) searchInput.current?.focus();
    else target?.querySelector<HTMLElement>('summary')?.focus();
    pendingLayout.current=null;
  },[mobile]);
  const toggle=useCallback((id:string)=>setExpanded(previous=>{const next=new Set(previous); if(next.has(id)) next.delete(id); else next.add(id); return next;}),[]);
  const l=labels[locale];
  const visible=useMemo(()=>{
    const term=search.trim().toLocaleLowerCase();
    const filtered=data.filter(row => (!term || [row.id, row.title, row.version].some(value => String(value ?? '').toLocaleLowerCase().includes(term))) && (!status || row.status===status));
    if(order!=='original') filtered.sort((a,b)=>String(a.id).localeCompare(String(b.id))*(order==='asc'?1:-1));
    return filtered;
  },[data,search,status,order]);
  const statuses=useMemo(()=>Array.from(new Set(data.map(row=>row.status).filter((v):v is string=>typeof v==='string'))).sort(),[data]);
  const columns=useMemo<ColumnDef<DataGridFeatures,ReadRecord>[]>(()=>[
    {accessorKey:'id',header:readText(locale,'id'),cell:({row})=><span className="record-id">{String(row.original.id)}</span>},
    {accessorKey:view==='requirements'?'version':'title',header:readText(locale,view==='requirements'?'version':'title'),cell:({row})=>String(row.original[view==='requirements'?'version':'title'])},
    {id:'details',header:readText(locale,'details'),cell:({row})=><RecordDetails row={row.original}/>},
  ],[locale,view]);
  const table=useTable({features:dataGridFeatures,columns,data:visible,manualPagination:true,getRowId:row=>String(row.id)});
  return <DetailContext.Provider value={{expanded,toggle,locale,view}}><div ref={root}>
    <p className="coverage">{l.scope}</p>
    <div className="lifecycle-grid-controls">
      <label>{l.search}<input ref={searchInput} type="search" value={search} onChange={e=>setSearch(e.target.value)}/></label>
      {view==='tasks' && <label>{l.status}<select value={status} onChange={e=>setStatus(e.target.value)}><option value="">{l.all}</option>{statuses.map(value=><option key={value} value={value}>{readText(locale,value)}</option>)}</select></label>}
      <label>{l.order}<select value={order} onChange={e=>setOrder(e.target.value)}><option value="original">{l.original}</option><option value="asc">{l.asc}</option><option value="desc">{l.desc}</option></select></label>
    </div>
    {mobile ? <div className="lifecycle-mobile-cards">
      {!visible.length && <p>{data.length ? l.empty : readText(locale,'empty')}</p>}
      {visible.map(row=><article className="record-card" key={String(row.id)}>
        <h3>{view==='requirements' ? `${readText(locale,'version')} ${String(row.version)}` : String(row.title)}</h3>
        <p className="record-id">{String(row.id)}</p>
        <RecordDetails row={row}/>
      </article>)}
    </div> : <div className="table-scroll" role="region" aria-label={t(locale,view)} tabIndex={0}>
      <DataGrid table={table} recordCount={visible.length} emptyMessage={data.length ? l.empty : readText(locale,'empty')}><DataGridContainer><DataGridTable/></DataGridContainer></DataGrid>
    </div>}
  </div></DetailContext.Provider>;
}
