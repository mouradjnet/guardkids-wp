import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { ContentDashboard } from './ContentDashboard';

const getAnalytics = vi.fn();
const listContents = vi.fn();
const listContentCategories = vi.fn();
vi.mock('../api/content', () => ({
  getAnalytics: () => getAnalytics(),
  listContents: () => listContents(),
  listContentCategories: () => listContentCategories(),
  createContent: vi.fn(),
  updateContent: vi.fn(),
  deleteContent: vi.fn(),
  listRecommendations: () => Promise.resolve([]),
  createRecommendation: vi.fn(),
  deleteRecommendation: vi.fn(),
  reorderRecommendations: vi.fn(),
}));
vi.mock('../api/children', () => ({
  listChildren: () => Promise.resolve([]),
}));

describe('ContentDashboard', () => {
  afterEach(() => {
    getAnalytics.mockReset();
    listContents.mockReset();
    listContentCategories.mockReset();
  });

  it('mostra estado vazio quando não há conteúdo e botão ativo', async () => {
    getAnalytics.mockResolvedValueOnce({ mostAccessed: [], favoriteCategories: [], timePerCategory: [] });
    listContents.mockResolvedValueOnce([]);
    listContentCategories.mockResolvedValueOnce([]);
    renderWithClient(<ContentDashboard />);
    expect(await screen.findByText('Nenhum conteúdo cadastrado')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /adicionar conteúdo/i })).toBeEnabled();
  });
});
