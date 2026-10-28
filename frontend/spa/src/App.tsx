import { useEffect, useState } from 'react';
import { SessionProvider, useSession } from './auth/SessionProvider';
import { LoginPage } from './auth/LoginPage';
import { ProjectPicker } from './projects/ProjectPicker';
import { WorkspaceShell, sections, type WorkspaceSection } from './layout/WorkspaceShell';
import { readLocale, saveLocale, t, type Locale } from './i18n';
import { LifecycleWorkspace } from './features/lifecycle/LifecycleWorkspace';
import { QaWorkspace } from './features/qa/QaWorkspace';
import { BaWorkspace } from './features/ba/BaWorkspace';
import { ManagementWorkspace } from './features/management/ManagementWorkspace';
import { ReadBoundary, useRead } from './features/readWorkspace';
import { invalid, object, strings } from './api/readAdapters';
import type { Capabilities } from './api/contracts';

function fromPath(): WorkspaceSection {
  const segment = location.pathname.split('/')[2];
  return sections.includes(segment as WorkspaceSection) ? segment as WorkspaceSection : 'overview';
}
function Workspace() {
  const { state, project, scopeGeneration } = useSession();
  const [locale, setLocale] = useState<Locale>(readLocale);
  const [section, setSection] = useState<WorkspaceSection>(fromPath);
  useEffect(() => {
    document.documentElement.lang = locale;
    saveLocale(locale);
  }, [locale]);
  useEffect(() => {
    const onPop = () => setSection(fromPath());
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, []);
  function navigate(next: WorkspaceSection) {
    history.pushState(null, '', `/app/${next}`);
    setSection(next);
  }
  const shell = { locale, setLocale, section, onNavigate: navigate };
  if (state.status === 'authenticated' && project) return <ProjectWorkspace key={scopeGeneration} {...shell} projectId={project.id} scopeGeneration={scopeGeneration} />;
  return <WorkspaceShell {...shell}>
    {state.status === 'loading' ? <p role="status">{t(locale, 'loading')}</p> :
      state.status !== 'authenticated' ? <LoginPage locale={locale} /> : <ProjectPicker key={scopeGeneration} locale={locale} />}
  </WorkspaceShell>;
}
const purposes: Record<WorkspaceSection, string[]> = {
  overview: ['progress.read'], requirements: ['requirements.read'], tasks: ['requirements.read'],
  qa: ['qa.read', 'qa.source.read'], ba: ['ba.read', 'ba.source.read'], management: ['management.read', 'management.source.read'],
};
function ProjectWorkspace(props: { projectId: string; scopeGeneration: number; locale: Locale; setLocale: (locale: Locale) => void; section: WorkspaceSection; onNavigate: (section: WorkspaceSection) => void }) {
  const { projectId, scopeGeneration, locale, section } = props;
  const { state, retry } = useRead(`/browser/v1/projects/${projectId}/capabilities`, scopeGeneration, value => {
    const record = object(value);
    if (record.project_id !== projectId || typeof record.observed_at !== 'string') invalid();
    strings(record.purposes);
    return record as unknown as Capabilities;
  });
  const visibleSections = state.status === 'ready' ? sections.filter(key => purposes[key].every(purpose => state.data.purposes.includes(purpose))) : [];
  return <WorkspaceShell {...props} visibleSections={visibleSections}>
    <ReadBoundary state={state} retry={retry} locale={locale}>{() => visibleSections.includes(section) ?
      <DomainWorkspace key={section} projectId={projectId} scopeGeneration={scopeGeneration} locale={locale} section={section} canWriteBa={state.status==='ready' && state.data.purposes.includes('ba.request.manage')} /> :
      <section><h1>{t(locale, section)}</h1><p>{t(locale, 'forbidden')}</p><p>{t(locale, 'permissionHints')}</p>{visibleSections.map(key => <button key={key} onClick={() => props.onNavigate(key)}>{t(locale, key)}</button>)}</section>
    }</ReadBoundary>
  </WorkspaceShell>;
}
function DomainWorkspace({ section, canWriteBa, ...props }: { projectId: string; scopeGeneration: number; locale: Locale; section: WorkspaceSection; canWriteBa:boolean }) {
  if (section === 'qa') return <QaWorkspace {...props} />;
  if (section === 'ba') return <BaWorkspace {...props} canWrite={canWriteBa} />;
  if (section === 'management') return <ManagementWorkspace {...props} />;
  return <LifecycleWorkspace {...props} view={section} />;
}
export default function App() { return <SessionProvider><Workspace /></SessionProvider>; }
