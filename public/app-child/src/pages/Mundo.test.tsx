import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Mundo } from './Mundo';

const browseLibrary = vi.fn();
const listLibraryCategories = vi.fn();
const listChildRecommendations = vi.fn();
const addFavorite = vi.fn();
const recordHistory = vi.fn();
vi.mock('../api/content', () => ({
  browseLibrary: () => browseLibrary(),
  listLibraryCategories: () => listLibraryCategories(),
  listChildRecommendations: () => listChildRecommendations(),
  listChildFavorites: () => Promise.resolve([]),
  addFavorite: (id: number) => addFavorite(id),
  removeFavorite: vi.fn(),
  recordHistory: (...a: unknown[]) => {
    recordHistory(...a);
    return Promise.resolve({ ok: true });
  },
}));

const sample = [
  { id: 10, categoryId: 1, title: 'Roblox', description: null, url: 'https://roblox.com', thumbnail: null, type: 'link', ageMin: 7, ageMax: 9, estimatedMinutes: null, level: null, tags: null, favorited: false },
];

describe('Mundo', () => {
  afterEach(() => {
    browseLibrary.mockReset();
    listLibraryCategories.mockReset();
    listChildRecommendations.mockReset();
    addFavorite.mockReset();
    recordHistory.mockReset();
  });

  it('lista conteúdo da biblioteca e abre + registra histórico ao tocar', async () => {
    browseLibrary.mockResolvedValue(sample);
    listLibraryCategories.mockResolvedValue([]);
    listChildRecommendations.mockResolvedValue([]);
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    renderWithClient(<Mundo />);
    fireEvent.click(await screen.findByText('Roblox'));
    expect(open).toHaveBeenCalledWith('https://roblox.com', '_blank', 'noopener,noreferrer');
    await waitFor(() => expect(recordHistory).toHaveBeenCalledWith(10, 'open', 0));
    open.mockRestore();
  });

  it('mostra estado vazio quando a biblioteca está vazia', async () => {
    browseLibrary.mockResolvedValue([]);
    listLibraryCategories.mockResolvedValue([]);
    listChildRecommendations.mockResolvedValue([]);
    renderWithClient(<Mundo />);
    expect(await screen.findByText(/nada por aqui ainda/i)).toBeInTheDocument();
  });
});
