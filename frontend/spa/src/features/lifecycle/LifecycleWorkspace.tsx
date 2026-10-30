import { useState } from 'react';
import { parseLifecycle, parseProgress } from '../../api/readAdapters';
import { readText } from '../../i18n/read';
import { t } from '../../i18n';
import { ReadBoundary, WorkspaceHeader, useRead, type WorkspaceProps } from '../readWorkspace';
import { LifecycleGrid } from './LifecycleGrid';
import { OverviewDashboard } from './OverviewDashboard';

export function LifecycleWorkspace({projectId,scopeGeneration,locale='uz',view='overview'}:WorkspaceProps & {view?:'overview'|'requirements'|'tasks'}) {
  const [cursor,setCursor]=useState<string|null>(null);
  const route=view==='tasks'?'lifecycle-tasks':'requirements';
  const query=new URLSearchParams({limit:'20'});
  if(cursor) query.set('cursor',cursor);
  const path=`/browser/v1/projects/${projectId}/${view==='overview'?'progress':`${route}?${query}`}`;
  const {state,retry}=useRead(path,scopeGeneration,value=>view==='overview'?parseProgress(value):parseLifecycle(value,view));
  return <section>
    <WorkspaceHeader locale={locale} title={t(locale,view)} projectId={projectId}/>
    <ReadBoundary state={state} retry={retry} locale={locale}>{data=>{
      if(!Array.isArray(data)) {
        return <OverviewDashboard data={data} locale={locale} projectId={projectId}/>;
      }
      return <>
        <p className="coverage">{readText(locale,view==='requirements'?'versionsOnly':'tasksOnly')}</p>
        <LifecycleGrid key={`${scopeGeneration}:${projectId}:${view}:${cursor ?? ''}`} data={data} view={view==='tasks'?'tasks':'requirements'} locale={locale}/>
        {data.length===20 && <button onClick={()=>setCursor(String(data[data.length-1].id))}>{t(locale,'more')}</button>}
      </>;
    }}</ReadBoundary>
  </section>;
}
