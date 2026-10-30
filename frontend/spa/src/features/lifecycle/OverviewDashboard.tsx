import type { Progress } from '../../api/readAdapters';
import { useSession } from '../../auth/SessionProvider';
import { formatDate, type Locale } from '../../i18n';
import { readText } from '../../i18n/read';
import { Reasons, Value } from '../readWorkspace';
import './overview.css';

const copy = {
  uz: {
    overview: 'Loyiha holati', accepted: 'Qabul qilingan vazn', total: 'Tasdiqlangan jami vazn',
    weight: 'Mezonlar vazni', coverage: 'Ma’lumot qamrovi', progress: 'Qabul qilingan natija',
    basis: 'Tasdiqlangan bazadagi mezonlar vazni bo‘yicha', remaining: 'Hali qabul qilinmagan',
    trend: 'Dalillar dinamikasi', noTrend: 'Vaqt qatori mavjud emas',
    noTrendDetail: 'Joriy holat taqdim etilgan. Davrlar bo‘yicha dalillar bu ma’lumotga kiritilmagan.',
    attention: 'Qamrov va cheklovlar', noReasons: 'Qamrov bo‘yicha sabab kodlari berilmagan.',
    noReasonsDetail: 'Bu boshqa manbalarning to‘liqligini tasdiqlamaydi.',
    selectedProject: 'Tanlangan loyiha', project: 'Loyiha', result: 'Qabul holati',
    updated: 'Qayd etilgan vaqt', snapshot: 'Joriy holat', noDate: 'Qayd vaqti berilmagan',
  },
  ru: {
    overview: 'Обзор проекта', accepted: 'Принятый вес', total: 'Общий утверждённый вес',
    weight: 'Вес критериев', coverage: 'Покрытие данных', progress: 'Принятый результат',
    basis: 'По весу критериев утверждённой базы', remaining: 'Ещё не принято',
    trend: 'Динамика свидетельств', noTrend: 'Временной ряд недоступен',
    noTrendDetail: 'Доступен текущий снимок. Свидетельства по периодам в эти данные не включены.',
    attention: 'Покрытие и ограничения', noReasons: 'Коды причин покрытия не предоставлены.',
    noReasonsDetail: 'Это не подтверждает полноту других источников.',
    selectedProject: 'Выбранный проект', project: 'Проект', result: 'Статус приёмки',
    updated: 'Время записи', snapshot: 'Текущий снимок', noDate: 'Время записи не указано',
  },
  en: {
    overview: 'Project overview', accepted: 'Accepted weight', total: 'Approved total weight',
    weight: 'Criterion weight', coverage: 'Data coverage', progress: 'Accepted progress',
    basis: 'By criterion weight in the approved baseline', remaining: 'Not yet accepted',
    trend: 'Evidence over time', noTrend: 'Time series unavailable',
    noTrendDetail: 'A current snapshot is available. Evidence by period is not included in this data.',
    attention: 'Coverage and limitations', noReasons: 'No coverage reason codes were supplied.',
    noReasonsDetail: 'This does not confirm completeness of other sources.',
    selectedProject: 'Selected project', project: 'Project', result: 'Acceptance status',
    updated: 'Recorded at', snapshot: 'Current snapshot', noDate: 'Recorded time not supplied',
  },
} satisfies Record<Locale, Record<string, string>>;

function DashboardIcon({kind = 'document'}: {kind?: 'document'|'chart'|'database'|'calendar'}) {
  return <svg className="overview-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
    {kind === 'chart' ? <><path d="M4 4v16h16M8 15v-4m5 4V7m5 8v-6"/></> : kind === 'database' ? <><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/></> : kind === 'calendar' ? <><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18"/></> : <><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6M8 13h8m-8 4h6"/></>}
  </svg>;
}

export function OverviewDashboard({data,locale,projectId}: {data:Progress;locale:Locale;projectId:string}) {
  const {project} = useSession();
  const c = copy[locale];
  const available = data.status !== 'unavailable' && data.baseline_id !== null && data.total_weight !== null && data.total_weight > 0 && data.percentage_basis_points !== null;
  const status = readText(locale,data.status === 'available' ? 'complete' : data.status);
  const date = data.as_of ? new Date(data.as_of) : null;
  const validDate = date && !Number.isNaN(date.getTime());
  const month = validDate ? new Intl.DateTimeFormat({uz:'uz-UZ',ru:'ru-RU',en:'en-GB'}[locale],{month:'long',year:'numeric',timeZone:'Asia/Tashkent'}).format(date) : c.overview;
  const unknown = readText(locale,'unavailable');

  return <div className="overview-dashboard">
    <div className="overview-title-row"><h2>{month}</h2><span className="overview-date"><DashboardIcon kind="calendar"/>{validDate ? <time dateTime={data.as_of}>{formatDate(locale,data.as_of!)}</time> : c.snapshot}</span></div>
    <div className="overview-stat-grid">
      <article className="overview-panel overview-stat"><div><h3>{c.accepted}</h3><DashboardIcon kind="document"/></div><strong>{available ? data.accepted_weight : '—'}</strong><p>{c.weight}</p></article>
      <article className="overview-panel overview-stat"><div><h3>{c.total}</h3><DashboardIcon kind="chart"/></div><strong>{available ? data.total_weight : '—'}</strong><p>{readText(locale,'baseline')}</p></article>
      <article className="overview-panel overview-stat overview-stat-status"><div><h3>{c.coverage}</h3><DashboardIcon kind="database"/></div><strong>{status}</strong><p>{c.snapshot}</p></article>
    </div>
    <div className="overview-main-grid">
      <section className="overview-panel overview-progress" aria-labelledby="overview-progress-title">
        <h3 id="overview-progress-title">{c.progress}</h3>
        <div className="overview-progress-value"><strong>{available ? `${data.percentage_basis_points!/100}%` : unknown}</strong><span>{available ? `${data.accepted_weight} / ${data.total_weight}` : c.basis}</span></div>
        {available && <div className="overview-progress-track"><progress max={10000} value={data.percentage_basis_points!} aria-label={readText(locale,'acceptedWeight')}/><div className="overview-progress-legend"><span><i/>{c.accepted}</span><span><i/>{c.remaining}</span></div><p>{c.basis}</p></div>}
        <div className="overview-trend"><h4>{c.trend}</h4><div className="overview-empty"><DashboardIcon kind="chart"/><strong>{c.noTrend}</strong><p>{c.noTrendDetail}</p></div></div>
      </section>
      <aside className="overview-panel overview-coverage" aria-labelledby="overview-coverage-title">
        <h3 id="overview-coverage-title">{c.attention}</h3>
        <div className="overview-coverage-status"><span className={`overview-status-dot overview-status-${data.status}`}/><div><span>{c.coverage}</span><strong>{c.snapshot} · {status}</strong></div></div>
        {data.coverage_reason_codes.length ? <Reasons codes={data.coverage_reason_codes} locale={locale}/> : <div className="overview-no-reasons"><DashboardIcon/><p>{c.noReasons}</p><span>{c.noReasonsDetail}</span></div>}
      </aside>
    </div>
    <section className="overview-panel overview-project"><h3>{c.selectedProject}</h3><div className="overview-project-table"><table><thead><tr><th>{c.project}</th><th>{c.coverage}</th><th>{c.updated}</th></tr></thead><tbody><tr><td><span className="overview-project-dot"/>{project?.id === projectId ? project.name : readText(locale,'unknown')}</td><td><span className="overview-table-status">{c.coverage}: {status}</span></td><td>{validDate ? <time dateTime={data.as_of}>{formatDate(locale,data.as_of!)}</time> : c.noDate}</td></tr></tbody></table></div></section>
    <details className="overview-panel overview-details"><summary>{readText(locale,'details')}</summary>
      <dl className="record-fields"><div><dt>{readText(locale,'baseline')}</dt><dd>{data.baseline_id??readText(locale,'unknown')}</dd></div><div><dt>{readText(locale,'policy')}</dt><dd>{data.policy_id??readText(locale,'unknown')}</dd></div></dl>
      {data.previous_snapshot && <><h3>{readText(locale,'previous_snapshot')}</h3><Value value={data.previous_snapshot} field="previous_snapshot" locale={locale}/></>}
      {data.schedule_coverage && <><h3>{readText(locale,'schedule_coverage')}</h3><Value value={data.schedule_coverage} field="schedule_coverage" locale={locale}/></>}
    </details>
  </div>;
}
