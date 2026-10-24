import { createContext, useContext, useState, useRef, useEffect, useCallback, type ReactNode } from 'react';
import { ApiClient, ApiError, apiClient } from '../api/client';
import type { SessionView, ProjectSummary } from '../api/contracts';

type Active = Extract<SessionView, { authenticated: true }>;
export type SessionState = { status: 'loading' }
  | { status: 'anonymous'; error?: string }
  | { status: 'authenticated'; session: Active };
type Context = {
  state: SessionState;
  client: ApiClient;
  authTransition: boolean;
  scopeGeneration: number;
  project: ProjectSummary | null;
  login: (token: string) => Promise<void>;
  logout: () => Promise<void>;
  reload: () => Promise<void>;
  selectProject: (project: ProjectSummary | null) => void;
  isCurrent: (generation: number) => boolean;
};
const SessionContext = createContext<Context | null>(null);

export function SessionProvider({ children, client = apiClient }: { children: ReactNode; client?: ApiClient }) {
  const [state, setState] = useState<SessionState>({ status: 'loading' });
  const [project, setProject] = useState<ProjectSummary | null>(null);
  const [scopeGeneration, setGeneration] = useState(0);
  const [authTransition, setAuthTransition] = useState(false);
  const transition = useRef<Promise<void> | null>(null);
  const generation = useRef(0);
  const mounted = useRef(true);
  const recovering = useRef(false);

  const bump = useCallback(() => {
    const next = ++generation.current;
    setGeneration(next);
    setProject(null);
    return next;
  }, []);
  const isCurrent = useCallback((value: number) => mounted.current && value === generation.current, []);

  const serialize = useCallback((work: () => Promise<void>, queue = false) => {
    // Login/reload are rejected while busy; logout and bounded 401 recovery may queue.
    // Never queue a submitted raw token for later use.
    if (transition.current && !queue) return Promise.resolve();
    setAuthTransition(true);
    const pending = transition.current ? transition.current.catch(() => {}).then(work) : work();
    const completion = pending.finally(() => {
      if (transition.current !== completion) return;
      transition.current = null;
      if (mounted.current) setAuthTransition(false);
    });
    transition.current = completion;
    return completion;
  }, []);

  const acceptCsrf = useCallback((session: SessionView) => {
    // A queued logout may supersede the visible result, but must use the CSRF
    // belonging to the cookie just written by the preceding serialized response.
    client.advanceSessionEpoch();
    client.setCsrf(session.csrf_token);
  }, [client]);

  const bootstrap = useCallback(async (allowAuthenticated: boolean, epoch: number) => {
    try {
      const session = await client.request<SessionView>('/browser/v1/session');
      if (!mounted.current) return;
      acceptCsrf(session);
      if (!isCurrent(epoch)) return;
      setState(session.authenticated && allowAuthenticated
        ? { status: 'authenticated', session }
        : { status: 'anonymous' });
    } catch (error) {
      if (isCurrent(epoch)) setState({ status: 'anonymous', error: error instanceof ApiError ? error.code : 'unavailable' });
    }
  }, [acceptCsrf, client, isCurrent]);

  const reload = useCallback(() => {
    if (transition.current) return Promise.resolve();
    const epoch = bump();
    client.advanceSessionEpoch();
    setState({ status: 'loading' });
    return serialize(() => bootstrap(true, epoch));
  }, [bootstrap, bump, client, serialize]);

  useEffect(() => {
    mounted.current = true;
    client.onUnauthorized = () => {
      client.advanceSessionEpoch();
      const epoch = bump();
      setState({ status: 'anonymous', error: 'unauthenticated' });
      if (recovering.current) return;
      recovering.current = true;
      void serialize(async () => {
        try {
          const session = await client.request<SessionView>('/browser/v1/session');
          if (mounted.current && !session.authenticated) acceptCsrf(session);
          // Recovery only obtains anonymous CSRF; an authenticated result requires explicit reload.
          if (!isCurrent(epoch)) return;
        } catch {
          // Fail closed; a second current-session401 clears state without recursive recovery.
        } finally { recovering.current = false; }
      }, true);
    };
    void reload();
    return () => {
      mounted.current = false;
      ++generation.current;
      client.advanceSessionEpoch();
      client.onUnauthorized = undefined;
    };
  }, [acceptCsrf, bump, client, isCurrent, reload, serialize]);

  const login = useCallback((token: string) => {
    if (transition.current) return Promise.resolve();
    const epoch = bump();
    client.advanceSessionEpoch();
    setState({ status: 'anonymous' });
    return serialize(async () => {
      try {
        const session = await client.request<SessionView>('/browser/v1/login', { method: 'POST', body: { token } });
        if (!session.authenticated) throw new ApiError('invalid_response');
        if (!mounted.current) return;
        acceptCsrf(session);
        if (isCurrent(epoch)) setState({ status: 'authenticated', session });
      } catch (error) {
        if (isCurrent(epoch)) setState({ status: 'anonymous', error: error instanceof ApiError ? error.code : 'unavailable' });
      }
    });
  }, [acceptCsrf, bump, client, isCurrent, serialize]);

  const logout = useCallback(() => {
    const epoch = bump();
    client.advanceSessionEpoch();
    setState({ status: 'anonymous' });
    return serialize(async () => {
      try {
        await client.request('/browser/v1/logout', { method: 'POST' });
        if (mounted.current) await bootstrap(false, epoch);
      } catch {
        if (isCurrent(epoch)) {
          client.setCsrf('');
          setState({ status: 'anonymous', error: 'logout_uncertain' });
        }
      }
    }, true);
  }, [bootstrap, bump, client, isCurrent, serialize]);

  const selectProject = useCallback((next: ProjectSummary | null) => {
    bump();
    setProject(next);
  }, [bump]);

  return <SessionContext.Provider value={{ state, client, authTransition, scopeGeneration, project, login, logout, reload, selectProject, isCurrent }}>
    {children}
  </SessionContext.Provider>;
}

export function useSession() {
  const value = useContext(SessionContext);
  if (!value) throw new Error('SessionProvider required');
  return value;
}
