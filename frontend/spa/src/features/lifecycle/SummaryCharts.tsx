import type {DashboardSummary,SummaryBlock} from '../../api/dashboardSummary';
import {parseDashboardSummary} from '../../api/dashboardSummary';
import type {Locale} from '../../i18n';
import {chartCopy,chartReason} from '../../i18n/charts';
import {ReadBoundary,useRead,type WorkspaceProps} from '../readWorkspace';
import './charts.css';
function Missing({block,locale}:{block:SummaryBlock<unknown>;locale:Locale}){const c=chartCopy[locale];return <div className="chart-missing"><strong>{block.coverage_status==='unknown'?c.unknown:c.unavailable}</strong><ul>{block.reason_codes.map(code=><li key={code}>{chartReason(locale,code)} <code>{code}</code></li>)}</ul></div>;}
function Bar({label,value,total,tone='orange',unit}:{label:string;value:number;total:number;tone?:string;unit?:string}){return <div className={`chart-bar-row chart-tone-${tone}`}><span>{label}</span><div className="chart-bar-track" aria-hidden="true"><i style={{width:`${total>0?value/total*100:0}%`}}/></div><strong>{value}{unit?` ${unit}`:''}</strong></div>;}
function Ratio({label,value,total,locale,tone}:{label:string;value:number;total:number;locale:Locale;tone:string}){return <div className={`chart-ratio chart-tone-${tone}`}><div><span>{label}</span><strong>{value} / {total}</strong></div>{total>0?<><div className="chart-bar-track" aria-hidden="true"><i style={{width:`${value/total*100}%`}}/></div><span>{Math.round(value/total*100)}%</span></>:<p>{chartCopy[locale].noDenominator}</p>}</div>;}
export function SummaryCharts({data,locale,showProvenance=true}:{data:DashboardSummary;locale:Locale;showProvenance?:boolean}){
 const c=chartCopy[locale];const tasks=data.tasks.counts,qa=data.qa.counts;
 const statuses=['done','in_progress','implemented_awaiting_verification','blocked','unstarted'] as const;
 return <div className="summary-charts">
  {showProvenance&&<div className="chart-snapshot"><span>{c.snapshot} · {c.ai}</span>{data.synthetic===true&&<strong className="chart-demo">{c.demo}</strong>}</div>}
  <div className="chart-panel-grid">
   <section className="overview-panel chart-panel" aria-labelledby="task-chart-title"><header><h3 id="task-chart-title">{c.tasks}</h3>{tasks&&<span>{c.total}: {tasks.total} {c.taskUnit}</span>}</header>{tasks?<div className="chart-bars">{statuses.map((key,index)=><Bar key={key} label={c[key]} value={tasks[key]} total={tasks.total} unit={c.taskUnit} tone={['green','orange','blue','amber','neutral'][index]}/>)}</div>:<Missing block={data.tasks} locale={locale}/>}<p className="chart-note">{c.taskNote}</p></section>
   <section className="overview-panel chart-panel" aria-labelledby="qa-chart-title"><header><h3 id="qa-chart-title">{c.qa}</h3></header>{qa?<><div className="chart-qa-ratios"><Ratio label={c.executed} value={qa.executed_criteria} total={qa.required_criteria} locale={locale} tone="orange"/><Ratio label={c.passed} value={qa.all_required_passed_criteria} total={qa.required_criteria} locale={locale} tone="blue"/></div><dl className="chart-qa-details"><div><dt>{c.fully}</dt><dd>{qa.fully_executed_criteria}</dd></div><div><dt>{c.excluded}</dt><dd>{qa.excluded_criteria}</dd></div><div><dt>{c.omitted}</dt><dd>{qa.omitted_criteria}</dd></div></dl></>:<Missing block={data.qa} locale={locale}/>}<p className="chart-note">{c.qaNote}</p></section>
   <section className="overview-panel chart-panel chart-dependencies" aria-labelledby="dependency-chart-title"><header><h3 id="dependency-chart-title">{c.deps}</h3></header>{(['qa','ba'] as const).map(key=>{const b=data.dependencies[key];return <div className="chart-dependency" key={key}><h4>{key==='qa'?c.qaDeps:c.baDeps}</h4>{b.counts?<><Bar label={key==='qa'?c.qaDeps:c.baDeps} value={b.counts.open} total={b.counts.total} tone={key==='qa'?'red':'amber'}/><p className="chart-denominator">{c.total}: {b.counts.total} {c.queue}</p></>:<Missing block={b} locale={locale}/>}</div>;})}<p className="chart-note">{c.depsNote}</p></section>
  </div>
 </div>;
}
export function DashboardCharts({projectId,scopeGeneration,locale='uz'}:WorkspaceProps){const {state,retry}=useRead(`/browser/v1/projects/${projectId}/dashboard-summary`,scopeGeneration,value=>parseDashboardSummary(value,projectId));return <div className="overview-dashboard"><ReadBoundary state={state} retry={retry} locale={locale}>{data=><SummaryCharts data={data} locale={locale}/>}</ReadBoundary></div>;}
