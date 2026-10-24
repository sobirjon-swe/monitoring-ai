import { useRef, useState, useEffect, type FormEvent } from 'react';
import { useSession } from './SessionProvider';
import { t, type Locale, type TranslationKey } from '../i18n';

export function LoginPage({ locale }: { locale: Locale }) {
  const { state, login, reload, scopeGeneration, authTransition } = useSession();
  const input = useRef<HTMLInputElement>(null);
  const [visible, setVisible] = useState(false);
  const [pending, setPending] = useState(false);
  const error = state.status === 'anonymous' ? state.error : undefined;

  useEffect(() => {
    if (input.current) input.current.value = '';
    setVisible(false);
  }, [scopeGeneration]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!input.current || pending || authTransition) return;
    const value = input.current.value;
    input.current.value = '';
    setVisible(false);
    setPending(true);
    try { await login(value); }
    finally { setPending(false); input.current?.focus(); }
  }

  const safeErrors = ['network', 'forbidden', 'not_found', 'invalid_request', 'session_expired', 'too_many_requests', 'invalid_response', 'logout_uncertain'];
  const message = error === 'unauthenticated' ? t(locale, 'sessionError')
    : error ? t(locale, (safeErrors.includes(error) ? error : 'unavailable') as TranslationKey) : null;

  return <section className="login-card" aria-labelledby="login-title">
    <h1>Monitoring <em>AI</em></h1>
    <h2 id="login-title">{t(locale, 'localLogin')}</h2>
    <form onSubmit={submit}>
      <label htmlFor="development-token">{t(locale, 'token')}</label>
      <div className="token-field">
        <input id="development-token" ref={input} type={visible ? 'text' : 'password'} autoComplete="off"
          autoCapitalize="none" spellCheck={false} maxLength={512} required aria-invalid={!!error}
          aria-describedby={message ? 'login-error token-help' : 'token-help'} disabled={pending || authTransition} />
        <button type="button" aria-pressed={visible} onClick={() => setVisible(!visible)} disabled={pending || authTransition}>
          {t(locale, visible ? 'hide' : 'show')}
        </button>
      </div>
      {message && <p id="login-error" className="error" role="alert">{message}</p>}
      <button className="primary" disabled={pending || authTransition || state.status === 'loading' || error === 'logout_uncertain'}>
        {t(locale, pending ? 'loading' : 'login')}
      </button>
    </form>
    <p id="token-help">{t(locale, 'tokenHelp')}</p>
    <p>{t(locale, 'tokenPrivacy')}</p>
    {error && <button disabled={authTransition} onClick={() => void reload()}>{t(locale, 'refresh')}</button>}
  </section>;
}
