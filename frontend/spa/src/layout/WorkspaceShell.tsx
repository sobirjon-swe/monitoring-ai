import { useEffect, useRef, useState, type ReactNode, type KeyboardEvent } from 'react';
import { useSession } from '../auth/SessionProvider';
import { t, type Locale } from '../i18n';
import './overviewShell.css';

export type WorkspaceSection = 'overview' | 'requirements' | 'tasks' | 'qa' | 'ba' | 'management';
export const sections: WorkspaceSection[] = ['overview', 'requirements', 'tasks', 'qa', 'ba', 'management'];

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
  const [menu, setMenu] = useState(false);
  const drawer = useRef<HTMLElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const wasOpen = useRef(false);
  const focusMainAfterClose = useRef(false);

  useEffect(() => {
    focusMainAfterClose.current = true;
    setMenu(false);
  }, [section, project?.id]);
  useEffect(() => {
    if (menu) drawer.current?.querySelector<HTMLElement>('a,button')?.focus();
    else if (focusMainAfterClose.current) {
      // This effect observes the closed commit, after inert has been removed.
      focusMainAfterClose.current = false;
      document.getElementById('main-content')?.focus();
    }
    else if (wasOpen.current) trigger.current?.focus();
    wasOpen.current = menu;
  }, [menu, section, project?.id]);
  useEffect(() => {
    if (typeof matchMedia !== 'function') return;
    const viewport = matchMedia('(max-width: 760px)');
    const resize = () => { if (!viewport.matches) setMenu(false); };
    viewport.addEventListener('change', resize);
    return () => viewport.removeEventListener('change', resize);
  }, []);

  function drawerKeys(event: KeyboardEvent<HTMLElement>) {
    if (!menu) return;
    if (event.key === 'Escape') { event.preventDefault(); setMenu(false); }
    if (event.key !== 'Tab') return;
    const links = drawer.current?.querySelectorAll<HTMLElement>('a,button');
    if (!links?.length) return;
    const first = links[0];
    const last = links[links.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }

  return <div className={`app-frame${project && section === 'overview' ? ' overview-frame' : ''}`}>
    <a className="skip" href="#main-content" inert={menu}>{t(locale, 'skip')}</a>
    <aside ref={drawer} className={menu ? 'sidebar open' : 'sidebar'} aria-label={t(locale, 'menu')}
      role={menu ? 'dialog' : undefined} aria-modal={menu || undefined} onKeyDown={drawerKeys}>
      <a className="brand" href="/app"><svg className="brand-mark" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m3 17 4-10 5 10 5-10 4 10M3 12h18" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"/></svg><span>Monitoring <em>AI</em></span></a>
      {!project && <p className="sidebar-intro">{t(locale, 'intro')}</p>}
      {project ? <>
        <p className="project-name">{project.name}</p>
        <nav>{visibleSections.map(key => <a key={key} href={`/app/${key}`} aria-current={section === key ? 'page' : undefined}
          onClick={event => {
            if (onNavigate) {
              event.preventDefault();
              focusMainAfterClose.current = true;
              setMenu(false);
              onNavigate(key);
            }
          }}><NavIcon section={key}/><span>{t(locale, key)}</span></a>)}</nav>
        <button className="sidebar-button" onClick={() => { selectProject(null); setMenu(false); }}>{t(locale, 'backProjects')}</button>
      </> : <p>{t(locale, 'principles')}</p>}
      <button className="mobile-close" onClick={() => setMenu(false)}>{t(locale, 'closeMenu')}</button>
    </aside>
    <div className="workspace-column" inert={menu}>
      <header>
        <button ref={trigger} className="menu-toggle" aria-expanded={menu} onClick={() => setMenu(!menu)}>{t(locale, 'menu')}</button>
        <div className="shell-context"><span>{t(locale,'workspace')}</span>{project && <strong title={project.name}>{project.name}</strong>}</div>
        <div className="identity">{state.status === 'authenticated' && <>
          <span>{state.session.user.display_name}</span><button onClick={() => void logout()}>{t(locale, 'logout')}</button>
        </>}</div>
        <fieldset className="locales"><legend className="sr-only">{t(locale, 'language')}</legend>
          {(['uz', 'ru', 'en'] as Locale[]).map(value => <button key={value} lang={value} aria-pressed={locale === value}
            onClick={() => setLocale(value)}>{value.toUpperCase()}</button>)}
        </fieldset>
      </header>
      <main id="main-content" tabIndex={-1} className={!project && state.status !== 'authenticated' ? 'login-main' : ''}>{children}</main>
    </div>
  </div>;
}

function NavIcon({section}:{section:WorkspaceSection}) {
  const paths:Record<WorkspaceSection,string>={
    overview:'M3 10 12 3l9 7M5 9v12h5v-7h4v7h5V9',
    requirements:'M6 3h9l3 3v15H6V3Zm8 0v5h4M9 12h6M9 16h6',
    tasks:'m4 6 1.5 1.5L8 5M11 6h9M4 12l1.5 1.5L8 11M11 12h9M4 18l1.5 1.5L8 17M11 18h9',
    qa:'m12 3 8 4v6c0 4-8 8-8 8s-8-4-8-8V7l8-4Zm-4 9 3 3 5-6',
    ba:'M4 4h16v12H9l-5 4V4Zm4 5h8M8 12h5',
    management:'M12 3v5M5 21v-6h14v6M12 15V8M3 21h4M10 21h4M17 21h4',
  };
  return <svg className="nav-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d={paths[section]} stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round"/></svg>;
}
