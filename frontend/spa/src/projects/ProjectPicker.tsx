import { useEffect, useState, useRef } from 'react';
import { useSession } from '../auth/SessionProvider';
import { ApiError } from '../api/client';
import type { ProjectPage, ProjectSummary } from '../api/contracts';
import { t, type Locale, type TranslationKey } from '../i18n';

export function ProjectPicker({ locale }: { locale: Locale }) {
  const { client, scopeGeneration, isCurrent, selectProject } = useSession();
  const [items, setItems] = useState<ProjectSummary[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [attempt, setAttempt] = useState(0);
  const controller = useRef<AbortController | null>(null);

  useEffect(() => {
    const abort = new AbortController();
    controller.current = abort;
    setItems([]);
    setCursor(null);
    setError(null);
    setBusy(true);
    void client.request<ProjectPage>('/browser/v1/projects', { signal: abort.signal }).then(page => {
      if (isCurrent(scopeGeneration) && !abort.signal.aborted) {
        setItems(page.items);
        setCursor(page.next_cursor);
      }
    }).catch(error => {
      if (isCurrent(scopeGeneration) && !abort.signal.aborted) {
        setError(error instanceof ApiError ? error.code : 'unavailable');
      }
    }).finally(() => {
      if (isCurrent(scopeGeneration) && !abort.signal.aborted) setBusy(false);
    });
    return () => abort.abort();
  }, [client, scopeGeneration, isCurrent, attempt]);

  async function more() {
    if (!cursor || busy) return;
    setBusy(true);
    setError(null);
    try {
      const page = await client.request<ProjectPage>('/browser/v1/projects?cursor=' + encodeURIComponent(cursor), { signal: controller.current?.signal });
      if (isCurrent(scopeGeneration)) {
        setItems(previous => [...previous, ...page.items.filter(project => !previous.some(old => old.id === project.id))]);
        setCursor(page.next_cursor);
      }
    } catch (error) {
      if (isCurrent(scopeGeneration) && !controller.current?.signal.aborted) {
        setItems([]);
        setCursor(null);
        setError(error instanceof ApiError ? error.code : 'unavailable');
      }
    } finally {
      if (isCurrent(scopeGeneration)) setBusy(false);
    }
  }

  return <section aria-labelledby="projects-title">
    <h1 id="projects-title" tabIndex={-1}>{t(locale, 'projects')}</h1>
    <p className="muted">{t(locale, 'choose')}</p>
    {error && <div role="alert"><p className="error">{t(locale, (error in { network: 1, forbidden: 1, invalid_request: 1, too_many_requests: 1 } ? error : 'unavailable') as TranslationKey)}</p><button onClick={() => setAttempt(value => value + 1)}>{t(locale, 'retry')}</button></div>}
    {busy && <p role="status">{t(locale, 'loading')}</p>}
    {!busy && !error && items.length === 0 && <div className="empty"><Folder /><h2>{t(locale, 'noProjects')}</h2><p>{t(locale, 'contactOperator')}</p></div>}
    <div className="project-grid">{items.map(project => <article className="project-card" key={project.id}><Folder /><h2>{project.name}</h2><button className="primary" onClick={() => selectProject(project)} aria-label={`${t(locale, 'openProject')}: ${project.name}`}>{t(locale, 'openProject')}</button></article>)}</div>
    {cursor && <button disabled={busy} onClick={() => void more()}>{t(locale, 'more')}</button>}
  </section>;
}
export function Folder() {
  return <svg className="folder" aria-hidden="true" viewBox="0 0 32 32" fill="none"><path d="M4 9h9l3 3h12v15H4V9Zm0 3h24M4 9V6h9l3 3" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" /></svg>;
}
