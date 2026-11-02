import { useState } from 'react';
import { ApiError } from '../../api/client';
import { parseDashboardSummary } from '../../api/dashboardSummary';
import { chartCopy } from '../../i18n/charts';
import { parseLifecycle, parseProgress } from '../../api/readAdapters';
import { readText } from '../../i18n/read';
import { t } from '../../i18n';
import { ReadBoundary, WorkspaceHeader, useRead, type WorkspaceProps } from '../readWorkspace';
import { LifecycleGrid } from './LifecycleGrid';
import { OverviewDashboard } from './OverviewDashboard';
import { SummaryCharts } from './SummaryCharts';

export function LifecycleWorkspace({view='overview',isDemo=false,...props}:WorkspaceProps & {view?:'overview'|'requirements'|'tasks';isDemo?:boolean}) {
  return view==='overview'?<ChartOverviewWorkspace {...props} isDemo={isDemo}/>:<LifecycleRecords {...props} view={view}/>;
}
function ChartOverviewWorkspace({projectId,scopeGeneration,locale='uz',isDemo}:WorkspaceProps & {isDemo:boolean}) {
  const scopeKey=`${scopeGeneration}:${projectId}`;
  const [fault,setFault]=useState<{scopeKey:string;error:ApiError}|null>(null);
  const deny=(error:ApiError)=>{if(error.status===403||error.status===404||error.code==='forbidden'||error.code==='not_found')setFault({scopeKey,error});};
  const progress=useRead(`/browser/v1/projects/${projectId}/progress`,scopeGeneration,parseProgress,deny);
  const summary=useRead(`/browser/v1/projects/${projectId}/dashboard-summary`,scopeGeneration,value=>parseDashboardSummary(value,projectId),deny);
  const retryBoth=()=>{setFault(null);progress.retry();summary.retry();};
  const activeFault=fault?.scopeKey===scopeKey?fault:null;
  const c=chartCopy[locale];
  return <section><WorkspaceHeader locale={locale} title={t(locale,'overview')} projectId={projectId}/>
    <div className="charts-workspace">
      <div className="chart-snapshot"><span>{c.snapshot} · {c.ai}</span>{isDemo&&<strong className="chart-demo">{c.demo}</strong>}</div>
      {activeFault?<div className="chart-read-state"><ReadBoundary state={{status:'error',error:activeFault.error}} retry={retryBoth} locale={locale}>{()=>null}</ReadBoundary></div>:<>
        <div className={progress.state.status==='ready'?'overview-dashboard chart-read-ready':'chart-read-state'}><ReadBoundary state={progress.state} retry={progress.retry} locale={locale}>{data=><OverviewDashboard data={data} locale={locale} projectId={projectId} compact/>}</ReadBoundary></div>
        <div className={summary.state.status==='ready'?'overview-dashboard chart-read-ready':'chart-read-state'}><ReadBoundary state={summary.state} retry={summary.retry} locale={locale}>{data=><SummaryCharts data={data} locale={locale} showProvenance={false}/>}</ReadBoundary></div>
      </>}
    </div>
  </section>;
}
function LifecycleRecords({projectId,scopeGeneration,locale='uz',view}:WorkspaceProps & {view:'requirements'|'tasks'}) {
  const [cursor,setCursor]=useState<string|null>(null);
  const route=view==='tasks'?'lifecycle-tasks':'requirements';
  const query=new URLSearchParams({limit:'20'});
  if(cursor) query.set('cursor',cursor);
  const path=`/browser/v1/projects/${projectId}/${route}?${query}`;
  const {state,retry}=useRead(path,scopeGeneration,value=>parseLifecycle(value,view));
  return <section>
    <WorkspaceHeader locale={locale} title={t(locale,view)} projectId={projectId}/>
    <ReadBoundary state={state} retry={retry} locale={locale}>{data=>{
      return <>
        <p className="coverage">{readText(locale,view==='requirements'?'versionsOnly':'tasksOnly')}</p>
        <LifecycleGrid key={`${scopeGeneration}:${projectId}:${view}:${cursor ?? ''}`} data={data} view={view==='tasks'?'tasks':'requirements'} locale={locale}/>
        {data.length===20 && <button onClick={()=>setCursor(String(data[data.length-1].id))}>{t(locale,'more')}</button>}
      </>;
    }}</ReadBoundary>
  </section>;
}
