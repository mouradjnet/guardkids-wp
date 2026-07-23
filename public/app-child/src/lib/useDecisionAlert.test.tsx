import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { MyRequest, MyRequestStatus } from '../api/types';
import { useDecisionAlert } from './useDecisionAlert';

const { listMyRequestsMock } = vi.hoisted(() => ({ listMyRequestsMock: vi.fn() }));
vi.mock('../api/child', () => ({ listMyRequests: listMyRequestsMock }));

function req(id: number, status: MyRequestStatus): MyRequest {
  return {
    id,
    childId: 16,
    kind: 'extra_time',
    description: 'Mais tempo de tela',
    highlight: '+45 min',
    reason: null,
    status,
    decidedAt: status === 'pending' ? null : '2026-07-23 12:30:00',
    createdAt: '2026-07-23 12:11:02',
  };
}

function setup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { ...renderHook(() => useDecisionAlert(true), { wrapper }), client };
}

describe('useDecisionAlert', () => {
  beforeEach(() => listMyRequestsMock.mockReset());

  it('não alerta na primeira carga, mesmo com pedido já decidido', async () => {
    listMyRequestsMock.mockResolvedValue([req(20, 'approved')]);
    const { result } = setup();

    await waitFor(() => expect(listMyRequestsMock).toHaveBeenCalled());
    expect(result.current.alerta).toBeNull();
  });

  it('alerta quando o pai aprova um pedido que estava pendente', async () => {
    listMyRequestsMock
      .mockResolvedValueOnce([req(20, 'pending')])
      .mockResolvedValueOnce([req(20, 'approved')]);
    const { result, client } = setup();

    // Esperar a PRIMEIRA carga chegar: `alerta` ja nasce null, entao aguardar
    // por null passaria antes de existir baseline e a decisao entraria como
    // carga inicial, sem alertar.
    await waitFor(() => expect(client.getQueryData(['child', 'requests'])).toHaveLength(1));
    await client.invalidateQueries({ queryKey: ['child', 'requests'] });

    await waitFor(() => expect(result.current.alerta?.status).toBe('approved'));
  });

  it('alerta também quando o pedido é negado', async () => {
    listMyRequestsMock
      .mockResolvedValueOnce([req(20, 'pending')])
      .mockResolvedValueOnce([req(20, 'denied')]);
    const { result, client } = setup();

    // Esperar a PRIMEIRA carga chegar: `alerta` ja nasce null, entao aguardar
    // por null passaria antes de existir baseline e a decisao entraria como
    // carga inicial, sem alertar.
    await waitFor(() => expect(client.getQueryData(['child', 'requests'])).toHaveLength(1));
    await client.invalidateQueries({ queryKey: ['child', 'requests'] });

    await waitFor(() => expect(result.current.alerta?.status).toBe('denied'));
  });

  it('não repete o alerta enquanto o status não muda de novo', async () => {
    listMyRequestsMock
      .mockResolvedValueOnce([req(20, 'pending')])
      .mockResolvedValue([req(20, 'approved')]);
    const { result, client } = setup();

    // Esperar a PRIMEIRA carga chegar: `alerta` ja nasce null, entao aguardar
    // por null passaria antes de existir baseline e a decisao entraria como
    // carga inicial, sem alertar.
    await waitFor(() => expect(client.getQueryData(['child', 'requests'])).toHaveLength(1));
    await client.invalidateQueries({ queryKey: ['child', 'requests'] });
    await waitFor(() => expect(result.current.alerta).not.toBeNull());

    result.current.dispensar();
    await waitFor(() => expect(result.current.alerta).toBeNull());
    await client.invalidateQueries({ queryKey: ['child', 'requests'] });

    await waitFor(() => expect(listMyRequestsMock).toHaveBeenCalledTimes(3));
    expect(result.current.alerta).toBeNull();
  });
});
