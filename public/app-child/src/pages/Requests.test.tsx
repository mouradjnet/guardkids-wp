import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import type { MyRequest } from '../api/types';
import { renderWithClient } from '../test/queryClient';
import { Requests } from './Requests';

const listMyRequests = vi.fn();
const createRequest = vi.fn();

vi.mock('../api/child', () => ({
  listMyRequests: () => listMyRequests(),
  createRequest: (input: unknown) => createRequest(input),
}));

const fakeRequest = (overrides: Partial<MyRequest> = {}): MyRequest => ({
  id: 1,
  childId: 1,
  kind: 'extra_time',
  description: 'Mais tempo de tela —',
  highlight: '+30 min',
  reason: null,
  status: 'pending',
  decidedAt: null,
  createdAt: new Date(Date.now() - 5 * 60_000).toISOString(),
  ...overrides,
});

describe('Requests', () => {
  afterEach(() => {
    listMyRequests.mockReset();
    createRequest.mockReset();
  });

  it('mostra empty state quando a lista vem vazia', async () => {
    listMyRequests.mockResolvedValueOnce([]);
    renderWithClient(<Requests />);
    expect(await screen.findByText(/nenhum pedido ainda/i)).toBeInTheDocument();
  });

  it('renderiza os pedidos retornados pela API', async () => {
    listMyRequests.mockResolvedValueOnce([
      fakeRequest({ id: 1, status: 'pending' }),
      fakeRequest({
        id: 2,
        status: 'approved',
        highlight: '+15 min',
        description: 'Mais tempo de tela —',
      }),
    ]);
    renderWithClient(<Requests />);
    await waitFor(() => {
      expect(screen.getByText('Aguardando')).toBeInTheDocument();
      expect(screen.getByText('Aprovado')).toBeInTheDocument();
    });
  });

  it('exibe erro quando a lista falha', async () => {
    listMyRequests.mockRejectedValueOnce(
      new ApiError('db_error', 'Falha no banco.', 500),
    );
    renderWithClient(<Requests />);
    expect(await screen.findByText(/falha no banco/i)).toBeInTheDocument();
  });

  it('envia request de extra_time com highlight do preset selecionado', async () => {
    listMyRequests.mockResolvedValue([]);
    createRequest.mockResolvedValueOnce(fakeRequest());

    renderWithClient(<Requests />);
    await screen.findByText(/nenhum pedido ainda/i);

    fireEvent.click(screen.getByRole('button', { name: /pedir mais tempo/i }));
    fireEvent.click(screen.getByRole('button', { name: '+45 min' }));
    fireEvent.click(screen.getByRole('button', { name: /enviar pedido/i }));

    await waitFor(() => {
      expect(createRequest).toHaveBeenCalledWith({
        kind: 'extra_time',
        description: 'Mais tempo de tela —',
        highlight: '+45 min',
        reason: undefined,
      });
    });
  });

  it('envia request de unblock_site com domínio normalizado e motivo', async () => {
    listMyRequests.mockResolvedValue([]);
    createRequest.mockResolvedValueOnce(fakeRequest());

    renderWithClient(<Requests />);
    await screen.findByText(/nenhum pedido ainda/i);

    fireEvent.click(screen.getByRole('button', { name: /pedir site/i }));
    fireEvent.change(screen.getByPlaceholderText(/ex: coolmathgames\.com/i), {
      target: { value: '  CoolMathGames.com  ' },
    });
    fireEvent.change(screen.getByPlaceholderText(/tem jogos pra aprender/i), {
      target: { value: 'Tarefa de matemática.' },
    });
    fireEvent.click(screen.getByRole('button', { name: /enviar pedido/i }));

    await waitFor(() => {
      expect(createRequest).toHaveBeenCalledWith({
        kind: 'unblock_site',
        description: 'Liberar site',
        highlight: 'coolmathgames.com',
        reason: 'Tarefa de matemática.',
      });
    });
  });

  it('mostra erro do form quando a criação falha', async () => {
    listMyRequests.mockResolvedValueOnce([]);
    createRequest.mockRejectedValueOnce(
      new ApiError('rate_limited', 'Calma aí.', 429),
    );

    renderWithClient(<Requests />);
    await screen.findByText(/nenhum pedido ainda/i);

    fireEvent.click(screen.getByRole('button', { name: /pedir mais tempo/i }));
    fireEvent.click(screen.getByRole('button', { name: /enviar pedido/i }));

    expect(await screen.findByText(/calma aí\. \(429\)/i)).toBeInTheDocument();
  });
});
