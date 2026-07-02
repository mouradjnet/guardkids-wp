import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { ContentDashboard } from './ContentDashboard';

const getContentSummary = vi.fn();
vi.mock('../api/content', () => ({
  getContentSummary: () => getContentSummary(),
}));

describe('ContentDashboard', () => {
  afterEach(() => getContentSummary.mockReset());

  it('mostra métricas zeradas e "Nunca" na sincronização', async () => {
    getContentSummary.mockResolvedValueOnce({
      contents: 0, categories: 0, favorites: 0, recommendations: 0, lastSync: null,
    });
    renderWithClient(<ContentDashboard />);
    expect(await screen.findByText('Nenhum conteúdo cadastrado')).toBeInTheDocument();
    expect(screen.getByText('Nunca')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /adicionar conteúdo/i })).toBeDisabled();
  });
});
