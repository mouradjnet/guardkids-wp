import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { listMock, createMock, deleteMock, reorderMock } = vi.hoisted(() => ({
  listMock: vi.fn(),
  createMock: vi.fn(),
  deleteMock: vi.fn(),
  reorderMock: vi.fn(),
}));
vi.mock('../api/content', () => ({
  listRecommendations: listMock,
  createRecommendation: createMock,
  deleteRecommendation: deleteMock,
  reorderRecommendations: reorderMock,
}));

import { RecommendationManager } from './RecommendationManager';

const options = [{ id: 7, title: 'Khan Academy' }];

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('RecommendationManager', () => {
  beforeEach(() => {
    listMock.mockReset().mockResolvedValue([]);
    createMock.mockReset();
    deleteMock.mockReset();
    reorderMock.mockReset();
  });

  it('adiciona uma recomendação pelo select', async () => {
    createMock.mockResolvedValue({ id: 1 });
    wrap(<RecommendationManager childId={1} contentOptions={options} />);

    await screen.findByText(/nenhuma recomendação/i);
    fireEvent.change(screen.getByRole('combobox'), { target: { value: '7' } });
    fireEvent.click(screen.getByRole('button', { name: /adicionar/i }));

    await waitFor(() => expect(createMock).toHaveBeenCalledWith(1, 7));
  });

  it('mostra erro visível quando adicionar falha (não some mudo)', async () => {
    createMock.mockRejectedValue(new Error('servidor fora'));
    wrap(<RecommendationManager childId={1} contentOptions={options} />);

    await screen.findByText(/nenhuma recomendação/i);
    fireEvent.change(screen.getByRole('combobox'), { target: { value: '7' } });
    fireEvent.click(screen.getByRole('button', { name: /adicionar/i }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/falha na recomendação/i);
    expect(alert).toHaveTextContent(/servidor fora/i);
  });
});
