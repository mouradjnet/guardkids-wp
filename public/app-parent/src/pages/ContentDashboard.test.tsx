import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { ContentDashboard } from './ContentDashboard';

const getAnalytics = vi.fn();
const listContents = vi.fn();
const listContentCategories = vi.fn();
const getContentSummary = vi.fn();
const approveContent = vi.fn();
const revokeContent = vi.fn();
vi.mock('../api/content', () => ({
  getAnalytics: () => getAnalytics(),
  listContents: (...args: unknown[]) => listContents(...args),
  listContentCategories: () => listContentCategories(),
  getContentSummary: () => getContentSummary(),
  approveContent: (id: number) => approveContent(id),
  revokeContent: (id: number) => revokeContent(id),
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

const pendingItem = {
  id: 1, categoryId: 1, title: 'Item de teste', description: null, url: null, thumbnail: null,
  type: 'link', ageMin: 0, ageMax: 99, estimatedMinutes: null, level: null, tags: null,
  status: 'pending' as const,
};

function defaults() {
  getAnalytics.mockResolvedValue({ mostAccessed: [], favoriteCategories: [], timePerCategory: [] });
  listContentCategories.mockResolvedValue([]);
  getContentSummary.mockResolvedValue({ contents: 1, categories: 0, favorites: 0, recommendations: 0, pendingCount: 1, lastSync: null });
}

describe('ContentDashboard', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('mostra estado vazio quando não há conteúdo e botão ativo', async () => {
    defaults();
    listContents.mockResolvedValue([]);
    getContentSummary.mockResolvedValue({ contents: 0, categories: 0, favorites: 0, recommendations: 0, pendingCount: 0, lastSync: null });
    renderWithClient(<ContentDashboard />);
    expect(await screen.findByText('Nenhum conteúdo cadastrado')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /adicionar conteúdo/i })).toBeEnabled();
  });

  it('marca item pendente com badge e botão Aprovar que chama a api', async () => {
    defaults();
    listContents.mockResolvedValue([pendingItem]);
    approveContent.mockResolvedValue({ ...pendingItem, status: 'approved' });
    renderWithClient(<ContentDashboard />);

    expect(await screen.findByText('Item de teste')).toBeInTheDocument();
    expect(screen.getByText('Pendente', { selector: 'span' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /aprovar/i }));
    await waitFor(() => expect(approveContent).toHaveBeenCalledWith(1));
  });

  it('mostra erro quando aprovar falha (nao pode falhar em silencio)', async () => {
    defaults();
    listContents.mockResolvedValue([pendingItem]);
    approveContent.mockRejectedValue(new Error('Sem permissão para fazer isso.'));
    renderWithClient(<ContentDashboard />);

    await screen.findByText('Item de teste');
    fireEvent.click(screen.getByRole('button', { name: /aprovar/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/permiss/i);
  });

  it('mostra erro quando excluir falha', async () => {
    defaults();
    listContents.mockResolvedValue([{ ...pendingItem, status: 'approved' as const }]);
    const { deleteContent } = await import('../api/content');
    vi.mocked(deleteContent).mockRejectedValue(new Error('Falha no servidor.'));
    renderWithClient(<ContentDashboard />);

    await screen.findByText('Item de teste');
    fireEvent.click(screen.getByRole('button', { name: /excluir/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/falha no servidor/i);
  });

  it('mostra erro quando revogar falha', async () => {
    defaults();
    listContents.mockResolvedValue([{ ...pendingItem, status: 'approved' as const }]);
    revokeContent.mockRejectedValue(new Error('Nao foi possivel revogar.'));
    renderWithClient(<ContentDashboard />);

    await screen.findByText('Item de teste');
    fireEvent.click(screen.getByRole('button', { name: /revogar/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/revogar/i);
  });
});
