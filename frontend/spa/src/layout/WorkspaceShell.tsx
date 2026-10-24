import { useEffect, useRef, useState, type ReactNode, type KeyboardEvent } from 'react';
import { useSession } from '../auth/SessionProvider';
import { t, type Locale } from '../i18n';

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

  return <div className="app-frame">
    <a className="skip" href="#main-content" inert={menu}>{t(locale, 'skip')}</a>
    <aside ref={drawer} className={menu ? 'sidebar open' : 'sidebar'} aria-label={t(locale, 'menu')}
      role={menu ? 'dialog' : undefined} aria-modal={menu || undefined} onKeyDown={drawerKeys}>
      <a className="brand" href="/app">Monitoring <em>AI</em></a>
      <p>{t(locale, 'intro')}</p><span className="blue-rule" />
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
          }}>{t(locale, key)}</a>)}</nav>
        <button className="sidebar-button" onClick={() => { selectProject(null); setMenu(false); }}>{t(locale, 'backProjects')}</button>
      </> : <p>{t(locale, 'principles')}</p>}
      <button className="mobile-close" onClick={() => setMenu(false)}>{t(locale, 'closeMenu')}</button>
    </aside>
    <div className="workspace-column" inert={menu}>
      <header>
        <button ref={trigger} className="menu-toggle" aria-expanded={menu} onClick={() => setMenu(!menu)}>{t(locale, 'menu')}</button>
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
