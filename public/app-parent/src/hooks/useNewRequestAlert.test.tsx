import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ApprovalRequest } from '../api/types';
import { useNewRequestAlert } from './useNewRequestAlert';

const { listRequestsMock } = vi.hoisted(() => ({ listRequestsMock: vi.fn() }));
vi.mock('../api/requests', () => ({ listRequests: listRequestsMock }));

function req(id: number): ApprovalRequest {
  return {
    id,
    childId: 16,
    kind: 'extra_time',
    description: 'Mais tempo de tela',
    highlight: '+45 min',
    reason: null,
    status: 'pending',
    decidedAt: null,
    decidedBy: null,
    createdAt: '2026-07-23 12:11:02',
  };
}

function setup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { ...renderHook(() => useNewRequestAlert(), { wrapper }), client };
}

describe('useNewRequestAlert', () => {
  beforeEach(() => listRequestsMock.mockReset());

  it('não alerta na primeira carga: pedido que já estava lá não é novidade', async () => {
    listRequestsMock.mockResolvedValue([req(20)]);
    const { result } = setup();

    await waitFor(() => expect(listRequestsMock).toHaveBeenCalled());
    expect(result.current.alerta).toBeNull();
  });

  it('alerta quando aparece pedido que não existia', async () => {
    listRequestsMock.mockResolvedValueOnce([req(20)]).mockResolvedValue([req(20), req(21)]);
    const { result, client } = setup();

    // Esperar a PRIMEIRA carga chegar de fato: `alerta` já nasce null, então
    // aguardar por null passaria antes de existir baseline — e aí o pedido 21
    // entraria como carga inicial, sem alertar.
    await waitFor(() =>
      expect(client.getQueryData(['requests', 'pending'])).toHaveLength(1),
    );
    await client.invalidateQueries({ queryKey: ['requests', 'pending'] });

    await waitFor(() => expect(result.current.alerta?.id).toBe(21));
  });

  it('dispensar tira o card da tela', async () => {
    listRequestsMock.mockResolvedValueOnce([]).mockResolvedValue([req(21)]);
    const { result, client } = setup();

    await waitFor(() => expect(client.getQueryData(['requests', 'pending'])).toHaveLength(0));
    await client.invalidateQueries({ queryKey: ['requests', 'pending'] });
    await waitFor(() => expect(result.current.alerta).not.toBeNull());

    result.current.dispensar();

    await waitFor(() => expect(result.current.alerta).toBeNull());
  });
});
