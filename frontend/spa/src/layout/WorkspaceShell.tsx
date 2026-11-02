import { useEffect, useRef, type ReactNode } from 'react';
import { useSession } from '../auth/SessionProvider';
import { t, type Locale } from '../i18n';

export type WorkspaceSection = 'overview' | 'requirements' | 'tasks' | 'qa' | 'ba' | 'management' | 'employees' | 'roles';
export const sections: WorkspaceSection[] = ['overview', 'requirements', 'tasks', 'qa', 'ba', 'management', 'employees', 'roles'];

type Props = {
  locale: Locale;
  setLocale: (locale: Locale) => void;
  children: ReactNode;
  section?: WorkspaceSection;
  onNavigate?: (section: WorkspaceSection) => void;
  visibleSections?: WorkspaceSection[];
};

export function WorkspaceShell({ locale, setLocale, children, section = 'overview', onNavigate, visibleSections = sections }: Props) {
  const { state, project, logout, selectProject } = useSession();
  const main = useRef<HTMLElement>(null);
  const nav = useRef<HTMLElement>(null);

  useEffect(() => {
    main.current?.focus();
  }, [section, project?.id]);

  useEffect(() => {
    // Only the navigation owns horizontal scrolling; never move the page or focus.
    const revealCurrent = () => {
      const region = nav.current;
      const current = region?.querySelector<HTMLElement>('[aria-current="page"]');
      if (!current || !region) return;
      const left = current.offsetLeft - region.offsetLeft;
      if (left < region.scrollLeft) region.scrollLeft = left;
      else if (left + current.offsetWidth > region.scrollLeft + region.clientWidth)
        region.scrollLeft = left + current.offsetWidth - region.clientWidth;
    };
    revealCurrent();
    window.addEventListener('resize', revealCurrent);
    return () => window.removeEventListener('resize', revealCurrent);
  }, [section, project?.id, locale, visibleSections.join(',')]);

  return <div className={`glass-shell${section === 'employees' || section === 'roles' ? ' people-shell' : ''}`}>
    <a className="skip" href="#main-content">{t(locale, 'skip')}</a>
    <header className="glass-header glass-panel">
      <a className="glass-brand" href="/app"><svg viewBox="0 0 28 28" fill="none" aria-hidden="true"><path d="M5 22V6l9 9 9-9v16M8 9v9m12-9v9"/></svg><span>Monitoring AI</span></a>
      <div className="glass-account">
        <fieldset className="locales"><legend className="sr-only">{t(locale, 'language')}</legend>
          {(['uz', 'ru', 'en'] as Locale[]).map(value => <button key={value} lang={value} aria-pressed={locale === value} onClick={() => setLocale(value)}>{value.toUpperCase()}</button>)}
        </fieldset>
        {state.status === 'authenticated' && <><span className="glass-identity">{state.session.user.display_name}</span><button onClick={() => void logout()}>{t(locale, 'logout')}</button></>}
      </div>
    </header>
    {project && <div className="glass-nav glass-panel">
      <div className="glass-section">{section === 'employees' || section === 'roles' ? <h1 className="glass-section-title">{t(locale, section)}</h1> : <span className="glass-section-title">{t(locale, section)}</span>}<span className="glass-project">{project.name}</span></div>
      <button data-change-project onClick={() => selectProject(null)}>{t(locale, 'backProjects')}</button>
      <nav ref={nav} aria-label={t(locale, 'menu')}>{visibleSections.map(key => <a key={key} href={`/app/${key}`} aria-current={section === key ? 'page' : undefined}
        onClick={event => {
          if (onNavigate && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
            event.preventDefault();
            onNavigate(key);
          }
        }}>{t(locale, key)}</a>)}</nav>
    </div>}
    <main ref={main} id="main-content" tabIndex={-1} className={!project && state.status !== 'authenticated' ? 'login-main' : !project ? 'project-picker-main' : ''}>{children}</main>
  </div>;
}
