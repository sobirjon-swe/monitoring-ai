import { useEffect, useId, useRef } from 'react';

export type BaContextFormLabels = {
  title: string;
  field: string;
  hint: string;
  count: (bytes: number) => string;
  warning: string;
  submit: string;
  pending: string;
  empty: string;
  tooLong: string;
};

type BaContextFormProps = {
  value: string;
  onChange: (value: string) => void;
  onSubmit: () => void;
  fieldDisabled?: boolean;
  submitBlocked?: boolean;
  pending?: boolean;
  error?: string;
  labels: BaContextFormLabels;
};

export function BaContextForm({value, onChange, onSubmit, fieldDisabled = false, submitBlocked = false, pending = false, error, labels}: BaContextFormProps) {
  const id = useId();
  const field = useRef<HTMLTextAreaElement>(null);
  const bytes = new TextEncoder().encode(value).length;
  const validation = bytes > 4000 ? labels.tooLong : value.length > 0 && !value.trim() ? labels.empty : undefined;
  const message = error || validation;
  const blocked = fieldDisabled || submitBlocked || pending || !value.trim() || bytes > 4000;

  useEffect(() => {
    if (error && !fieldDisabled && !pending) field.current?.focus();
  }, [error, fieldDisabled, pending]);

  return <form onSubmit={event => { event.preventDefault(); if (!blocked) onSubmit(); }} aria-busy={pending}>
    <h3>{labels.title}</h3>
    <p className="warning">{labels.warning}</p>
    <label htmlFor={`${id}-field`}>{labels.field}</label>
    <textarea ref={field} id={`${id}-field`} value={value} onChange={event => onChange(event.target.value)} disabled={fieldDisabled || pending} required
      aria-invalid={message ? true : undefined} aria-describedby={`${id}-hint ${id}-count${message ? ` ${id}-error` : ''}`}
      rows={5} style={{display:'block', width:'100%', maxWidth:'100%', font:'inherit', padding:'10px', marginTop:'8px'}} />
    <p id={`${id}-hint`} className="muted">{labels.hint}</p>
    <p id={`${id}-count`} aria-live="polite">{labels.count(bytes)}</p>
    {message && <p id={`${id}-error`} className="error" role="alert">{message}</p>}
    <button type="submit" className="primary" disabled={blocked}>{pending ? labels.pending : labels.submit}</button>
  </form>;
}
