import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Category, Child, Site } from '../api/types';

const { listSitesMock, createSiteMock, deleteSiteMock, listCategoriesMock, updateCategoryMock, listChildrenMock } = vi.hoisted(() => ({
  listSitesMock: vi.fn(),
  createSiteMock: vi.fn(),
  deleteSiteMock: vi.fn(),
  listCategoriesMock: vi.fn(),
  updateCategoryMock: vi.fn(),
  listChildrenMock: vi.fn(),
}));
vi.mock('../api/sites', () => ({
  listSites: listSitesMock,
  createSite: createSiteMock,
  deleteSite: deleteSiteMock,
}));
vi.mock('../api/categories', () => ({
  listCategories: listCategoriesMock,
  updateCategoryBlocked: updateCategoryMock,
}));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: vi.fn(),
  updateChild: vi.fn(),
  pairChildDevice: vi.fn(),
}));

import { SitesRules } from './SitesRules';

const lucas: Child = {
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null,
  device: null, status: 'online', usedMinutes: 0, limitMinutes: 60,
  bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
  createdAt: null, updatedAt: null,
};

const whitelistSite: Site = {
  id: 10, domain: 'khanacademy.org', category: 'education',
  listType: 'whitelist', appliesTo: [], createdAt: null,
};
const blacklistSite: Site = {
  id: 11, domain: 'tiktok.com', category: null,
  listType: 'blacklist', appliesTo: [1], createdAt: null,
};

const gamblingCat: Category = {
  id: 1, slug: 'gambling', name: 'Apostas', description: 'cassinos',
  icon: 'casino', blocked: true,
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<SitesRules />, { wrapper });
}

describe('SitesRules page', () => {
  beforeEach(() => {
    listSitesMock.mockReset().mockResolvedValue([whitelistSite, blacklistSite]);
    listCategoriesMock.mockReset().mockResolvedValue([gamblingCat]);
    listChildrenMock.mockReset().mockResolvedValue([lucas]);
    createSiteMock.mockReset();
    deleteSiteMock.mockReset();
    updateCategoryMock.mockReset();
  });

  it('renders 3 tabs with counts', async () => {
    renderPage();
    expect(await screen.findByRole('button', { name: /permitidos/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /bloqueados/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /categorias/i })).toBeInTheDocument();
  });

  it('shows whitelisted site in default tab', async () => {
    renderPage();
    expect(await screen.findByText('khanacademy.org')).toBeInTheDocument();
  });

  it('switches to Bloqueados tab and shows blacklisted site', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('khanacademy.org');

    await user.click(screen.getByRole('button', { name: /bloqueados/i }));
    expect(await screen.findByText('tiktok.com')).toBeInTheDocument();
  });

  it('switches to Categorias tab and renders blocked toggle', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('khanacademy.org');

    await user.click(screen.getByRole('button', { name: /categorias/i }));
    expect(await screen.findByText('Apostas')).toBeInTheDocument();
    expect(screen.getByText(/bloqueada para todos/i)).toBeInTheDocument();
  });

  it('calls createSite with parsed payload when adding domain', async () => {
    createSiteMock.mockResolvedValue({ ...whitelistSite, id: 99, domain: 'youtube.com' });
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('khanacademy.org');

    const input = screen.getByPlaceholderText(/adicionar domínio permitido/i) as HTMLInputElement;
    await user.type(input, 'youtube.com');
    fireEvent.submit(input.closest('form')!);

    await waitFor(() => {
      expect(createSiteMock).toHaveBeenCalled();
      expect(createSiteMock.mock.calls[0]?.[0]).toEqual({
        domain: 'youtube.com',
        list_type: 'whitelist',
        applies_to: [],
      });
    });
  });

  it('calls deleteSite when Remover clicked', async () => {
    deleteSiteMock.mockResolvedValue({ deleted: true, id: 10 });
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('khanacademy.org');

    await user.click(screen.getByRole('button', { name: /remover/i }));

    await waitFor(() => {
      expect(deleteSiteMock).toHaveBeenCalled();
      expect(deleteSiteMock.mock.calls[0]?.[0]).toBe(10);
    });
  });

  it('toggles category via PATCH on switch click', async () => {
    updateCategoryMock.mockResolvedValue({ ...gamblingCat, blocked: false });
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('khanacademy.org');

    await user.click(screen.getByRole('button', { name: /categorias/i }));
    await screen.findByText('Apostas');

    const toggle = screen.getByRole('switch');
    await user.click(toggle);

    await waitFor(() => {
      expect(updateCategoryMock).toHaveBeenCalled();
      expect(updateCategoryMock.mock.calls[0]?.[0]).toBe(1);
      expect(updateCategoryMock.mock.calls[0]?.[1]).toBe(false);
    });
  });

  it('filters by search query', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('khanacademy.org');

    const search = screen.getByPlaceholderText(/buscar site/i) as HTMLInputElement;
    await user.type(search, 'inexistente');

    await waitFor(() => {
      expect(screen.getByText(/nada encontrado/i)).toBeInTheDocument();
    });
  });
});
