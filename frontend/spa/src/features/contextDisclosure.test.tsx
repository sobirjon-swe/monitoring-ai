import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { WorkspaceHeader } from './readWorkspace';

vi.mock('../auth/SessionProvider', () => ({ useSession: () => ({ project: { name: 'CRM platforma' } }) }));

it('keeps project identity available without leading the workspace with technical IDs', () => {
  const id = '11111111-1111-4111-8111-111111111111';
  render(<WorkspaceHeader locale="en" title="Requirements" projectId={id} />);
  expect(screen.getByRole('heading', { name: 'Requirements', level: 1 })).toBeTruthy();
  expect(screen.getByText('CRM platforma')).toBeTruthy();
  const details = screen.getByText(id).closest('details');
  expect(details).not.toBeNull();
  expect(details?.open).toBe(false);
  expect(screen.getByText('Read-only workspace')).toBeTruthy();
});
