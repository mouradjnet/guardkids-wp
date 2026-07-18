import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

const getMeMock = vi.fn();
vi.mock('../api/me', () => ({ getMe: () => getMeMock() }));

import { useCurrentRole } from './useCurrentRole';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useCurrentRole', () => {
  it('deriva isAdmin do role=admin do servidor', async () => {
    getMeMock.mockResolvedValue({ role: 'admin', name: 'Djair', email: 'd@x.test' });
    const { result } = renderHook(() => useCurrentRole(), { wrapper: makeWrapper() });

    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.role).toBe('admin');
    expect(result.current.isAdmin).toBe(true);
    expect(result.current.isCollaborator).toBe(false);
    expect(result.current.name).toBe('Djair');
    expect(result.current.email).toBe('d@x.test');
  });

  it('role=collaborator: isCollaborator true, isAdmin false', async () => {
    getMeMock.mockResolvedValue({ role: 'collaborator', name: 'Ana', email: 'a@x.test' });
    const { result } = renderHook(() => useCurrentRole(), { wrapper: makeWrapper() });

    await waitFor(() => expect(result.current.role).toBe('collaborator'));
    expect(result.current.isCollaborator).toBe(true);
    expect(result.current.isAdmin).toBe(false);
  });

  it('enquanto carrega, role=null e ambos os flags false (default seguro)', () => {
    getMeMock.mockReturnValue(new Promise(() => {})); // nunca resolve
    const { result } = renderHook(() => useCurrentRole(), { wrapper: makeWrapper() });

    expect(result.current.role).toBeNull();
    expect(result.current.isAdmin).toBe(false);
    expect(result.current.isCollaborator).toBe(false);
  });
});
