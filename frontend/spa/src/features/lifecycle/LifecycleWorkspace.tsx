import { useState } from 'react';
import { parseLifecycle, parseProgress } from '../../api/readAdapters';
import { readText } from '../../i18n/read';
import { t } from '../../i18n';
import { Fields, ReadBoundary, Reasons, Value, WorkspaceHeader, useRead, type WorkspaceProps } from '../readWorkspace';
import { LifecycleGrid } from './LifecycleGrid';

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
        const available=data.status!=='unavailable' && data.baseline_id!==null && data.total_weight!==null && data.total_weight>0 && data.percentage_basis_points!==null;
        return <div className="progress-card">
          <h2>{readText(locale,'acceptedWeight')}</h2><p className="badge">{readText(locale,data.status==='available'?'complete':data.status)}</p>
          <div className="progress-summary"><strong>{available?`${data.accepted_weight} / ${data.total_weight}`:readText(locale,'unavailable')}</strong>
            {available && <><progress max={10000} value={data.percentage_basis_points!} aria-label={readText(locale,'acceptedWeight')}/><strong>{data.percentage_basis_points!/100}%</strong></>}
          </div>
          <dl className="record-fields"><div><dt>{readText(locale,'baseline')}</dt><dd>{data.baseline_id??readText(locale,'unknown')}</dd></div><div><dt>{readText(locale,'policy')}</dt><dd>{data.policy_id??readText(locale,'unknown')}</dd></div></dl>
          <Reasons codes={data.coverage_reason_codes} locale={locale}/>
          {data.as_of && <Fields record={{recorded_at:data.as_of}} fields={['recorded_at']} locale={locale}/>}
          {(data.previous_snapshot || data.schedule_coverage) && <details><summary>{readText(locale,'details')}</summary>
            {data.previous_snapshot && <><h3>{readText(locale,'previous_snapshot')}</h3><Value value={data.previous_snapshot} field="previous_snapshot" locale={locale}/></>}
            {data.schedule_coverage && <><h3>{readText(locale,'schedule_coverage')}</h3><Value value={data.schedule_coverage} field="schedule_coverage" locale={locale}/></>}
          </details>}
        </div>;
      }
      return <>
        <p className="coverage">{readText(locale,view==='requirements'?'versionsOnly':'tasksOnly')}</p>
        <LifecycleGrid key={`${scopeGeneration}:${projectId}:${view}:${cursor ?? ''}`} data={data} view={view==='tasks'?'tasks':'requirements'} locale={locale}/>
        {data.length===20 && <button onClick={()=>setCursor(String(data[data.length-1].id))}>{t(locale,'more')}</button>}
      </>;
    }}</ReadBoundary>
  </section>;
}
