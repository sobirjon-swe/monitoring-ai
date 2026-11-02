import {useEffect,useRef,useState,type ReactNode,type KeyboardEvent} from 'react';
import {ApiError} from '../../api/client';
import {parseEmployee,parseRole,parsePeoplePage,type Employee,type CatalogRole,type CreateRole,type AssignRole} from '../../api/peopleRoles';
import {useSession} from '../../auth/SessionProvider';
import {formatDate,t,type Locale} from '../../i18n';
import {peopleText as text} from '../../i18n/people';
import {ReadBoundary,useRead} from '../readWorkspace';
import './people.css';
type Props={projectId:string;
 scopeGeneration:number;
 locale:Locale;
 view:'employees'|'roles';
 canCreate:boolean;
 canAssign:boolean;
 canReadRoles?:boolean};
export function PeopleWorkspace({projectId,scopeGeneration,locale,view,canCreate,canAssign,canReadRoles=false}:Props){
 const {project,selectProject}=useSession();
 const [search,setSearch]=useState(''),[filter,setFilter]=useState(''),[cursor,setCursor]=useState<string|null>(null),[previous,setPrevious]=useState<(string|null)[]>([]);
 const [open,setOpen]=useState(false),[employee,setEmployee]=useState<Employee|null>(null),[denied,setDenied]=useState(false),[locked,setLocked]=useState(false),[success,setSuccess]=useState(false),[editorEpoch,setEditorEpoch]=useState(0);
 const isEmployees=view==='employees';
 const query=new URLSearchParams();
 if(search)query.set('search',search);
 if(isEmployees){if(filter)query.set('role_id',filter);
 }else query.set('status',filter||'all');
 if(cursor)query.set('cursor',cursor);
 const path=`/browser/v1/projects/${projectId}/${isEmployees?'employees':'role-catalog'}?${query}`;
 const page=useRead(path,scopeGeneration,v=>parsePeoplePage<Employee|CatalogRole>(v,isEmployees?parseEmployee:parseRole),e=>{if(e.status===403){setDenied(true);
 setOpen(false);
 }});
 function resetPage(){setCursor(null);
 setPrevious([]);
 }
 function edit(target:Employee|null){if(locked && target?.id!==employee?.id)return;
 if(target?.id!==employee?.id){setEmployee(target);
 setEditorEpoch(n=>n+1);
 }setOpen(true);
 setSuccess(false);
 }
 const writable=!denied&&(isEmployees?canAssign:canCreate);
 return <section className="people-workspace">
  <div className={`people-grid${open?' has-editor':''}`}>
   <div className="people-content glass-panel" inert={open}>
    <div className="people-heading">
<div>
<h2>{text(locale,isEmployees?'team':'catalog')}</h2>
<button className="people-project" onClick={()=>selectProject(null)}>{project?.name}</button>
</div>{!isEmployees&&writable&&<button className="people-primary" onClick={()=>edit(null)} disabled={locked}>{text(locale,'create')}</button>}</div>
    <p className="people-subtle">{text(locale,isEmployees?'scope':'catalogScope')}</p>{!isEmployees&&!canCreate&&<p className="people-subtle">{text(locale,'adminNotice')}</p>}
    <div className="people-filters">
<label>
<span className="sr-only">{text(locale,isEmployees?'searchEmployee':'searchRole')}</span>
<SearchIcon/>
<input type="search" value={search} maxLength={120} placeholder={text(locale,isEmployees?'searchEmployee':'searchRole')} onChange={e=>{setSearch(e.target.value);
 resetPage();
 }}/>
</label>
      {isEmployees?(canReadRoles?<EmployeeRoleFilter projectId={projectId} scopeGeneration={scopeGeneration} locale={locale} value={filter} onChange={v=>{setFilter(v);
 resetPage();
 }}/>:<span className="people-subtle">{text(locale,'roleReadUnavailable')}</span>):<label>
<span className="sr-only">{text(locale,'status')}</span>
<select value={filter} onChange={e=>{setFilter(e.target.value);
 resetPage();
 }}>
<option value="">{text(locale,'all')}</option>
<option value="active">{text(locale,'active')}</option>
<option value="archived">{text(locale,'archived')}</option>
</select>
</label>}
    </div>
    {denied&&<p role="alert">{text(locale,'denied')}</p>}{success&&<p role="status">{text(locale,'success')}</p>}
    {locked&&!open&&<button onClick={()=>setOpen(true)}>{text(locale,'retrySame')}</button>}
    <ReadBoundary state={page.state} retry={page.retry} locale={locale}>{data=>
<>
     <p className="people-count">{data.visible_record_count===null?text(locale,'unknownCount'):`${text(locale,'count')}: ${data.visible_record_count}`}</p>
     {!data.items.length?<p className="people-empty">{text(locale,isEmployees?'noEmployees':'noRoles')}</p>:<div className="people-table-region" role="region" aria-label={text(locale,isEmployees?'team':'catalog')} tabIndex={0}>
<table role="table" className={`people-table ${isEmployees?'people-table-employees':'people-table-catalog'}`}>
<thead>
<tr>{(isEmployees?['employee','workRole','membership','action']:['name','description','status','revision']).map(k=>
<th key={k} scope="col">{text(locale,k as 'employee')}</th>)}</tr>
</thead>
<tbody>
      {data.items.map(item=>isEmployees?<tr role="row" key={item.id} className={open&&employee?.id===item.id?'selected':''}>
<td role="cell" data-label={text(locale,'employee')}>
<div className="person-name">
<span className="person-avatar" aria-hidden="true">{initials((item as Employee).display_name)}</span>
<strong>{(item as Employee).display_name}</strong>
</div>
</td>
<td role="cell" data-label={text(locale,'workRole')}>
<div className="people-role-stack">{(item as Employee).roles.length?(item as Employee).roles.map(role=>
<span key={role.id}>{role.name}<small>{period(locale,role.valid_from,role.valid_to)}</small>
</span>):<span className="people-subtle">{text(locale,'noRole')}</span>}<details>
<summary>{text(locale,'functionalRoles')}</summary>{(item as Employee).system_roles.length?<ul>{(item as Employee).system_roles.map(code=>
<li key={code}>
<code>{code}</code>
</li>)}</ul>:<span>—</span>}</details>
</div>
</td>
<td role="cell" data-label={text(locale,'membership')}>
<span className="people-period">{period(locale,(item as Employee).membership.valid_from,(item as Employee).membership.valid_to)}</span>
</td>
<td role="cell" data-label={text(locale,'action')}>{writable?<button className="people-arrow" aria-label={`${text(locale,'assign')}: ${(item as Employee).display_name}`} disabled={locked&&employee?.id!==item.id} onClick={()=>edit(item as Employee)}>
<ArrowIcon/>
</button>:<span className="people-subtle">{text(locale,'readOnly')}</span>}</td>
</tr>:<tr role="row" key={item.id}>
<td role="cell" data-label={text(locale,'name')}>
<strong>{(item as CatalogRole).name}</strong>
</td>
<td role="cell" data-label={text(locale,'description')}>{(item as CatalogRole).description||text(locale,'noDescription')}</td>
<td role="cell" data-label={text(locale,'status')}>
<span className="people-status">{text(locale,(item as CatalogRole).status)}</span>
</td>
<td role="cell" data-label={text(locale,'revision')}>{(item as CatalogRole).revision}</td>
</tr>)}
     </tbody>
</table>
</div>}
     <div className="people-pagination">{previous.length>0&&<button onClick={()=>{setCursor(previous[previous.length-1]);
 setPrevious(v=>v.slice(0,-1));
 }}>{text(locale,'back')}</button>}{data.next_cursor&&<button onClick={()=>{setPrevious(v=>[...v,cursor]);
 setCursor(data.next_cursor);
 }}>{text(locale,'next')}</button>}</div>
    </>}</ReadBoundary>
    <p className="people-footnote">{text(locale,'notice')}</p>
   </div>
   {!denied&&<RoleEditor key={`${view}:${employee?.id??'new'}:${editorEpoch}`} open={open} onClose={()=>setOpen(false)} locale={locale} projectId={projectId} scopeGeneration={scopeGeneration} employee={employee} create={!isEmployees} enabled={writable} onLocked={setLocked} onDenied={()=>{setDenied(true);
 setOpen(false);
 setLocked(false);
 }} onSaved={()=>{setOpen(false);
 setLocked(false);
 setSuccess(true);
 setEditorEpoch(n=>n+1);
 page.retry();
 }} onReload={()=>{page.retry();
 }}/>}
  </div>
 </section>;
}
function initials(name:string){return name.trim().split(/\s+/).slice(0,2).map(v=>v[0]).join('').toUpperCase();
 }
function period(locale:Locale,from:string,to:string|null){return `${formatDate(locale,from)} — ${to?formatDate(locale,to):'…'}`;
 }
function useCatalogPages(projectId:string,scopeGeneration:number,status:'all'|'active',onDenied?:()=>void){
 const [cursor,setCursor]=useState(''),[items,setItems]=useState<CatalogRole[]>([]);
 const page=useRead(`/browser/v1/projects/${projectId}/role-catalog?status=${status}${cursor?'&cursor='+encodeURIComponent(cursor):''}`,scopeGeneration,v=>parsePeoplePage(v,parseRole),e=>{if(e.status===403)onDenied?.();
 });
 useEffect(()=>{if(page.state.status==='ready'){const next=page.state.data.items;
 setItems(old=>Array.from(new Map([...old,...next].map(r=>[r.id,r])).values()));
 }},[page.state]);
 return {...page,items,more:()=>{if(page.state.status==='ready'&&page.state.data.next_cursor)setCursor(page.state.data.next_cursor);
 }};
}
function EmployeeRoleFilter({projectId,scopeGeneration,locale,value,onChange}:{projectId:string;
 scopeGeneration:number;
 locale:Locale;
 value:string;
 onChange:(v:string)=>void}){
 const roles=useCatalogPages(projectId,scopeGeneration,'all');
 return <div className="people-role-filter">
<ReadBoundary state={roles.state} retry={roles.retry} locale={locale}>{data=>
<>
  <label>
<span className="sr-only">{text(locale,'allRoles')}</span>
<select value={value} onChange={e=>onChange(e.target.value)}>
<option value="">{text(locale,'allRoles')}</option>{roles.items.map(role=>
<option key={role.id} value={role.id}>{role.name}</option>)}</select>
</label>
  {data.next_cursor&&<button type="button" onClick={roles.more}>{text(locale,'moreRoles')}</button>}
 </>}</ReadBoundary>
</div>;
}
function RoleEditor({open,onClose,locale,projectId,scopeGeneration,employee,create,enabled,onLocked,onDenied,onSaved,onReload}:{open:boolean;
 onClose:()=>void;
 locale:Locale;
 projectId:string;
 scopeGeneration:number;
 employee:Employee|null;
 create:boolean;
 enabled:boolean;
 onLocked:(v:boolean)=>void;
 onDenied:()=>void;
 onSaved:()=>void;
 onReload:()=>void}){
 const {client,isCurrent,project}=useSession();
 const [name,setName]=useState(''),[description,setDescription]=useState(''),[selected,setSelected]=useState<CatalogRole|null>(null),[from,setFrom]=useState(''),[until,setUntil]=useState(''),[state,setState]=useState<'idle'|'pending'|'uncertain'|'conflict'|'error'>('idle'),[error,setError]=useState<ApiError|null>(null),[roleEpoch,setRoleEpoch]=useState(0);
 const request=useRef<CreateRole|AssignRole|null>(null),busy=useRef(false),mounted=useRef(true);
 useEffect(()=>{mounted.current=true;
 return()=>{mounted.current=false;
 };
 },[]);
 const current=()=>mounted.current&&isCurrent(scopeGeneration);
 const frozen=state==='pending'||state==='uncertain';
 const invalidDates=!!((from&&!Number.isFinite(Date.parse(from)))||(until&&!Number.isFinite(Date.parse(until)))||(from&&until&&until<=from));
 const valid=create?!!name.trim()&&name.trim().length<=120&&description.length<=2000:!!selected&&!invalidDates;
 async function save(){if(busy.current||!enabled||(!valid&&state!=='uncertain')||state==='conflict')return;
 busy.current=true;
 onLocked(true);
 setState('pending');
 setError(null);
  if(!request.current)request.current=create?{name:name.trim(),description:description.trim(),request_id:crypto.randomUUID()}:{employee_id:employee!.id,role_catalog_id:selected!.id,expected_role_revision:selected!.revision,request_id:crypto.randomUUID(),...(from?{valid_from:new Date(from+':00+05:00').toISOString()}:{}),...(until?{valid_to:new Date(until+':00+05:00').toISOString()}: {})};
  try{if(create)await client.createCatalogRole(request.current as CreateRole);
 else await client.assignCatalogRole(projectId,request.current as AssignRole);
 if(current())onSaved();
 }
  catch(e){if(!current())return;
 const failure=e instanceof ApiError?e:new ApiError('unavailable');
 setError(failure);
 if(failure.status===403){onDenied();
 return;
 }if(failure.status===409){setState('conflict');
 onLocked(false);
 }else if(failure.status===422||failure.code==='invalid_request'){request.current=null;
 setState('error');
 onLocked(false);
 }else {setState('uncertain');
 onLocked(true);
 }}
  finally{busy.current=false;
 }
 }
 function reload(){request.current=null;
 setSelected(null);
 setState('idle');
 setError(null);
 setRoleEpoch(n=>n+1);
 onLocked(false);
 onReload();
 }
 return <Drawer open={open} onClose={onClose} title={text(locale,create?'create':'assign')} closeLabel={text(locale,'close')}>
  <form onSubmit={e=>{e.preventDefault();
 void save();
 }} aria-busy={state==='pending'}>
   {employee&&<div className="people-editor-person">
<span className="person-avatar" aria-hidden="true">{initials(employee.display_name)}</span>
<strong>{employee.display_name}</strong>
</div>}
   {create?<>
<label>{text(locale,'name')}<input autoComplete="off" required maxLength={120} value={name} disabled={frozen} onChange={e=>{setName(e.target.value);
 request.current=null;
 }}/>
</label>
<label>{text(locale,'description')}<textarea rows={5} maxLength={2000} value={description} disabled={frozen} onChange={e=>{setDescription(e.target.value);
 request.current=null;
 }}/>
</label>
<p className="people-subtle">{text(locale,'validation')}</p>
</>:<>
<label>{text(locale,'project')}<input value={project?.name??''} readOnly/>
</label>{open&&<ActiveRolePicker key={roleEpoch} projectId={projectId} scopeGeneration={scopeGeneration} locale={locale} value={selected} onChange={role=>{setSelected(role);
 request.current=null;
 }} onDenied={onDenied} disabled={frozen}/>}
    <details className="people-dates">
<summary>{text(locale,'dates')}</summary>
<p className="people-subtle">{text(locale,'dateHint')}</p>
<label>{text(locale,'from')}<input type="datetime-local" value={from} disabled={frozen} onChange={e=>{setFrom(e.target.value);
 request.current=null;
 }}/>
</label>
<label>{text(locale,'until')}<input type="datetime-local" value={until} disabled={frozen} onChange={e=>{setUntil(e.target.value);
 request.current=null;
 }}/>
</label>{invalidDates&&<p role="alert">{text(locale,'dateValidation')}</p>}</details>
<p className="people-subtle">{text(locale,'pmNotice')}</p>
</>}
   <p className="people-editor-notice">{text(locale,'notice')}</p>
   {state==='uncertain'&&<p role="alert">{text(locale,'uncertain')}</p>}{state==='conflict'&&<div role="alert">
<p>{text(locale,'conflict')}</p>
<button type="button" onClick={reload}>{text(locale,'reload')}</button>
</div>}{state==='error'&&<p role="alert">{text(locale,create?'validation':'assignmentValidation')}</p>}{error?.requestId&&<p>{t(locale,'requestId',{id:error.requestId})}</p>}
   <div className="people-editor-actions">
<button type="button" onClick={onClose}>{text(locale,'cancel')}</button>
<button className="people-primary" type="submit" disabled={!enabled||state==='pending'||state==='conflict'||(!valid&&state!=='uncertain')}>{text(locale,state==='pending'?'pending':state==='uncertain'?'retrySame':create?'save':'assign')}</button>
</div>
  </form>
 </Drawer>;
}
function ActiveRolePicker({projectId,scopeGeneration,locale,value,onChange,disabled,onDenied}:{projectId:string;
 scopeGeneration:number;
 locale:Locale;
 value:CatalogRole|null;
 onChange:(role:CatalogRole|null)=>void;
 disabled:boolean;
 onDenied:()=>void}){
 const roles=useCatalogPages(projectId,scopeGeneration,'active',onDenied);
 return <ReadBoundary state={roles.state} retry={roles.retry} locale={locale}>{data=>
<>
  <label>{text(locale,'existing')}<select required disabled={disabled} value={value?.id??''} onChange={e=>onChange(roles.items.find(r=>r.id===e.target.value)??null)}>
<option value="">{text(locale,'chooseRole')}</option>{roles.items.map(role=>
<option key={role.id} value={role.id}>{role.name}</option>)}</select>
</label>
  {!roles.items.length&&<p>{text(locale,'noAvailable')}</p>}{data.next_cursor&&<button type="button" disabled={disabled} onClick={roles.more}>{text(locale,'moreRoles')}</button>}
 </>}</ReadBoundary>;
}
function Drawer({open,onClose,title,closeLabel,children}:{open:boolean;
 onClose:()=>void;
 title:string;
 closeLabel:string;
 children:ReactNode}){const panel=useRef<HTMLElement>(null),returnFocus=useRef<HTMLElement|null>(null);
 useEffect(()=>{if(!open)return;
 returnFocus.current=document.activeElement as HTMLElement;
 panel.current?.querySelector<HTMLElement>('button,input,select,textarea')?.focus();
 const chrome=[...document.querySelectorAll<HTMLElement>('.glass-header,.glass-nav,.people-shell > .skip')];
 chrome.forEach(el=>el.inert=true);
 return()=>{chrome.forEach(el=>el.inert=false);
 const opener=returnFocus.current;
 if(opener?.isConnected)opener.focus();
 else document.getElementById('main-content')?.focus();
 };
 },[open]);
 function keys(e:KeyboardEvent){if(e.key==='Escape'){e.preventDefault();
 onClose();
 }if(e.key==='Tab'){const elements=panel.current?.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),summary');
 if(!elements?.length)return;
 const first=elements[0],last=elements[elements.length-1];
 if(e.shiftKey&&document.activeElement===first){e.preventDefault();
 last.focus();
 }else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();
 first.focus();
 }}}return <aside ref={panel} hidden={!open} className="people-editor glass-panel" role={open?'dialog':undefined} aria-modal={open||undefined} aria-label={title} onKeyDown={keys}>
<div className="people-editor-heading">
<h2>{title}</h2>
<button type="button" aria-label={closeLabel} onClick={onClose}>
<CloseIcon/>
</button>
</div>{children}</aside>;
 }
function SearchIcon(){return <svg viewBox="0 0 24 24" aria-hidden="true">
<circle cx="10" cy="10" r="7"/>
<path d="m15 15 6 6"/>
</svg>;
 }
function ArrowIcon(){return <svg viewBox="0 0 24 24" aria-hidden="true">
<path d="M4 12h16m-6-6 6 6-6 6"/>
</svg>;
 }
function CloseIcon(){return <svg viewBox="0 0 24 24" aria-hidden="true">
<path d="m5 5 14 14M5 19 19 5"/>
</svg>;
 }
