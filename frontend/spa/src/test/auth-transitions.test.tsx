import { act, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { ApiClient } from '../api/client';
import { LoginPage } from '../auth/LoginPage';
import { SessionProvider, useSession } from '../auth/SessionProvider';

const session = (name: string, csrf: string) => ({ authenticated: true, csrf_token: csrf,
  user: { id: name, tenant_id: 'tenant', employee_id: null, display_name: name }, expires_at: '2026-10-25T00:00:00Z' });
const json = (data: unknown) => new Response(JSON.stringify({ data }));
function deferred() {
  let resolve!: (response: Response) => void;
  const promise = new Promise<Response>(done => { resolve = done; });
  return { promise, resolve };
}
let current!: ReturnType<typeof useSession>;
function Probe() {
  current = useSession();
  return <><span>{current.state.status === 'authenticated' ? current.state.session.user.display_name : current.state.status}</span>
    <LoginPage locale="en" /></>;
}

it('blocks login and reload during both logout and fresh CSRF bootstrap', async () => {
  const logout = deferred();
  const bootstrap = deferred();
  const fetcher = vi.fn().mockResolvedValueOnce(json(session('Session A', 'csrf-a')))
    .mockReturnValueOnce(logout.promise).mockReturnValueOnce(bootstrap.promise)
    .mockResolvedValue(json(session('Session B', 'csrf-b')));
  render(<SessionProvider client={new ApiClient(fetcher)}><Probe /></SessionProvider>);
  await screen.findByText('Session A');
  let done!: Promise<void>;
  act(() => { done = current.logout(); });
  expect(screen.queryByText('Session A')).toBeNull();
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
  const blockedDuringLogout = (screen.getByRole('button', { name: 'Sign in' }) as HTMLButtonElement).disabled;
  await act(async () => { void current.login('blocked-token'); void current.reload(); await Promise.resolve(); });
  expect(fetcher).toHaveBeenCalledTimes(2);
  expect(blockedDuringLogout).toBe(true);
  await act(async () => { logout.resolve(new Response(null, { status: 204 })); });
  await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(3));
  expect((screen.getByRole('button', { name: 'Sign in' }) as HTMLButtonElement).disabled).toBe(true);
  await act(async () => { void current.login('still-blocked'); void current.reload(); await Promise.resolve(); });
  expect(fetcher).toHaveBeenCalledTimes(3);
  await act(async () => { bootstrap.resolve(json({ authenticated: false, csrf_token: 'fresh-anonymous' })); await done; });
  expect((screen.getByRole('button', { name: 'Sign in' }) as HTMLButtonElement).disabled).toBe(false);
  await act(async () => { await current.login('permitted-token'); });
  await screen.findByText('Session B');
  expect(fetcher.mock.calls[3][1].headers['X-CSRF-TOKEN']).toBe('fresh-anonymous');
});

it('preserves session B and its CSRF when session A delayed read returns401', async () => {
  const oldRead = deferred();
  const fetcher = vi.fn().mockResolvedValueOnce(json(session('Session A', 'csrf-a')))
    .mockReturnValueOnce(oldRead.promise)
    .mockResolvedValueOnce(new Response(null, { status: 204 }))
    .mockResolvedValueOnce(json({ authenticated: false, csrf_token: 'anonymous-csrf' }))
    .mockResolvedValueOnce(json(session('Session B', 'csrf-b')))
    .mockResolvedValue(new Response(null, { status: 204 }));
  const client = new ApiClient(fetcher);
  render(<SessionProvider client={client}><Probe /></SessionProvider>);
  await screen.findByText('Session A');
  const oldResult = client.request('/browser/v1/projects').catch(error => error);
  await act(async () => { await current.logout(); });
  await act(async () => { await current.login('new-session-token'); });
  await screen.findByText('Session B');
  await act(async () => { oldRead.resolve(new Response('{}', { status: 401 })); await oldResult; });
  expect(screen.getByText('Session B')).toBeTruthy();
  await client.request('/browser/v1/logout', { method: 'POST' });
  expect(fetcher.mock.calls.at(-1)![1].headers['X-CSRF-TOKEN']).toBe('csrf-b');
  expect(fetcher.mock.calls.filter(([path]) => path === '/browser/v1/session')).toHaveLength(2);
});


it('queues logout behind an in-flight login and uses that response current CSRF', async () => {
  const login = deferred();
  const fetcher = vi.fn().mockResolvedValueOnce(json({ authenticated: false, csrf_token: 'initial' }))
    .mockReturnValueOnce(login.promise)
    .mockResolvedValueOnce(new Response(null, { status: 204 }))
    .mockResolvedValueOnce(json({ authenticated: false, csrf_token: 'after-logout' }));
  render(<SessionProvider client={new ApiClient(fetcher)}><Probe /></SessionProvider>);
  await screen.findByText('anonymous');
  let signingIn!: Promise<void>;
  let signingOut!: Promise<void>;
  act(() => { signingIn = current.login('first-token'); });
  await act(async () => { void current.login('blocked-second-token'); void current.reload(); await Promise.resolve(); });
  expect(fetcher).toHaveBeenCalledTimes(2);
  act(() => { signingOut = current.logout(); });
  expect(screen.queryByText('Session B')).toBeNull();
  expect(fetcher).toHaveBeenCalledTimes(2);
  await act(async () => { login.resolve(json(session('Session B', 'rotated-login-csrf'))); await signingIn; await signingOut; });
  expect(fetcher.mock.calls[2][0]).toBe('/browser/v1/logout');
  expect(fetcher.mock.calls[2][1].headers['X-CSRF-TOKEN']).toBe('rotated-login-csrf');
  expect(screen.queryByText('Session B')).toBeNull();
  expect(current.authTransition).toBe(false);
});

it('still fails closed immediately for a current-session401 across a project switch', async () => {
  const read = deferred();
  const recovery = deferred();
  const fetcher = vi.fn().mockResolvedValueOnce(json(session('Session A', 'csrf-a')))
    .mockReturnValueOnce(read.promise).mockReturnValueOnce(recovery.promise);
  const client = new ApiClient(fetcher);
  render(<SessionProvider client={client}><Probe /></SessionProvider>);
  await screen.findByText('Session A');
  const result = client.request('/browser/v1/projects').catch(error => error);
  act(() => current.selectProject({ id: 'next', name: 'Next', revision: 1 }));
  await act(async () => { read.resolve(new Response('{}', { status: 401 })); await result; });
  expect(screen.queryByText('Session A')).toBeNull();
  expect(current.project).toBeNull();
  expect(current.authTransition).toBe(true);
  await act(async () => { recovery.resolve(json({ authenticated: false, csrf_token: 'recovered' })); });
  await waitFor(() => expect(current.authTransition).toBe(false));
});
