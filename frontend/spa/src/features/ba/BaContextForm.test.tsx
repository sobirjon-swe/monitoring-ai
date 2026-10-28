import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { BaContextForm, type BaContextFormLabels } from './BaContextForm';

const labels: BaContextFormLabels = { title:'Add context', field:'Context', hint:'Required; maximum 4000 UTF-8 bytes', count:(n) => `${n} / 4000 bytes`, warning:'Customer authenticity is not verified.', submit:'Save', pending:'Saving', empty:'Enter context', tooLong:'Context exceeds 4000 bytes' };
function Harness({onSubmit = vi.fn(), fieldDisabled = false, submitBlocked = false, pending = false, error}: {onSubmit?: () => void; fieldDisabled?: boolean; submitBlocked?: boolean; pending?: boolean; error?: string}) {
  const [value,setValue] = useState('');
  return <BaContextForm value={value} onChange={setValue} onSubmit={onSubmit} fieldDisabled={fieldDisabled} submitBlocked={submitBlocked} pending={pending} error={error} labels={labels}/>;
}
it('counts UTF-8 bytes and blocks empty or over-limit submissions', () => {
  const submit = vi.fn(); render(<Harness onSubmit={submit}/>);
  const field = screen.getByRole('textbox', {name:'Context'});
  expect((screen.getByRole('button', {name:'Save'}) as HTMLButtonElement).disabled).toBe(true);
  fireEvent.change(field,{target:{value:'é'.repeat(2000)}});
  expect(screen.getByText('4000 / 4000 bytes')).toBeTruthy();
  fireEvent.click(screen.getByRole('button', {name:'Save'})); expect(submit).toHaveBeenCalledTimes(1);
  fireEvent.change(field,{target:{value:'é'.repeat(2000)+'a'}});
  expect(screen.getByText('4001 / 4000 bytes')).toBeTruthy();
  expect((screen.getByRole('button', {name:'Save'}) as HTMLButtonElement).disabled).toBe(true);
  fireEvent.submit(field.closest('form')!); expect(submit).toHaveBeenCalledTimes(1);
});
it('keeps pending and externally disabled forms from submitting', () => {
  const submit = vi.fn(); const {rerender} = render(<Harness onSubmit={submit} pending/>);
  expect((screen.getByRole('button', {name:'Saving'}) as HTMLButtonElement).disabled).toBe(true);
  fireEvent.submit(screen.getByRole('textbox').closest('form')!); expect(submit).not.toHaveBeenCalled();
  rerender(<Harness onSubmit={submit} fieldDisabled/>);
  expect((screen.getByRole('textbox') as HTMLTextAreaElement).disabled).toBe(true);
});
it('preserves an editable draft while a conflict blocks saving', () => {
  const submit = vi.fn(); const {rerender} = render(<Harness onSubmit={submit}/>);
  const field = screen.getByRole('textbox', {name:'Context'}) as HTMLTextAreaElement;
  fireEvent.change(field,{target:{value:'Draft'}});
  rerender(<Harness onSubmit={submit} submitBlocked error="Conflict"/>);
  expect(field.value).toBe('Draft'); expect(field.disabled).toBe(false);
  expect(document.activeElement).toBe(field);
  fireEvent.change(field,{target:{value:'Revised draft'}});
  expect(field.value).toBe('Revised draft');
  expect((screen.getByRole('button', {name:'Save'}) as HTMLButtonElement).disabled).toBe(true);
  fireEvent.submit(field.closest('form')!); expect(submit).not.toHaveBeenCalled();
  rerender(<Harness onSubmit={submit}/>);
  fireEvent.click(screen.getByRole('button', {name:'Save'})); expect(submit).toHaveBeenCalledTimes(1);
});
it('marks the field required and rejects whitespace-only input', () => {
  const submit = vi.fn(); render(<Harness onSubmit={submit}/>);
  const field = screen.getByRole('textbox', {name:'Context'}) as HTMLTextAreaElement;
  expect(field.required).toBe(true);
  fireEvent.change(field,{target:{value:'  \n '}});
  expect(screen.getByText('Enter context')).toBeTruthy();
  expect((screen.getByRole('button', {name:'Save'}) as HTMLButtonElement).disabled).toBe(true);
  fireEvent.submit(field.closest('form')!); expect(submit).not.toHaveBeenCalled();
});
it('associates guidance and errors with the field and focuses it when an error appears', () => {
  const {rerender} = render(<Harness/>); const field = screen.getByRole('textbox', {name:'Context'});
  expect(screen.getByText('Customer authenticity is not verified.')).toBeTruthy();
  rerender(<Harness error="Unable to save"/>);
  expect(document.activeElement).toBe(field);
  expect(field.getAttribute('aria-invalid')).toBe('true');
  expect(field.getAttribute('aria-describedby')).toContain(screen.getByText('Unable to save').id);
});
