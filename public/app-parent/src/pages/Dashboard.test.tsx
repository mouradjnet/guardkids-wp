import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Child } from '../api/types';

const { listChildrenMock, listRequestsMock, listSettingsMock, getMeMock, getRecentBlocksMock } = vi.hoisted(() => ({
  listChildrenMock: vi.fn(),
  listRequestsMock: vi.fn(),
  listSettingsMock: vi.fn(),
  getMeMock: vi.fn(),
  getRecentBlocksMock: vi.fn(),
}));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: vi.fn(),
  updateChild: vi.fn(),
  pairChildDevice: vi.fn(),
}));
vi.mock('../api/requests', () => ({
  listRequests: listRequestsMock,
  approveRequest: vi.fn(),
  denyRequest: vi.fn(),
}));
vi.mock('../api/settings', () => ({
  listSettings: listSettingsMock,
  updateSettings: vi.fn(),
}));
vi.mock('../api/me', () => ({
  getMe: getMeMock,
}));
vi.mock('../api/reports', () => ({
  getRecentBlocks: getRecentBlocksMock,
}));

import { Dashboard } from './Dashboard';

const lucas: Child = {
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null,
  device: 'Tablet', status: 'online', usedMinutes: 30, limitMinutes: 60,
  dailyLimitEnabled: false,
  bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
  createdAt: null, updatedAt: null,
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<Dashboard />, { wrapper });
}

describe('Dashboard page', () => {
  beforeEach(() => {
    listChildrenMock.mockReset();
    listRequestsMock.mockReset().mockResolvedValue([]);
    listSettingsMock.mockReset().mockResolvedValue({ location_enabled: false });
    getMeMock.mockReset().mockResolvedValue({ role: 'admin', email: 'a@b', name: 'Admin User' });
    getRecentBlocksMock.mockReset().mockResolvedValue([]);
  });

  it('renders Crianças Ativas skeleton while loading', () => {
    listChildrenMock.mockReturnValue(new Promise(() => {}));
    renderPage();
    expect(screen.getByText(/crianças ativas/i)).toBeInTheDocument();
  });

  it('renders ChildCard for each child from API', async () => {
    listChildrenMock.mockResolvedValue([lucas, { ...lucas, id: 2, name: 'Paloma', slug: 'paloma' }]);
    renderPage();

    expect(await screen.findByText('Lucas')).toBeInTheDocument();
    expect(screen.getByText('Paloma')).toBeInTheDocument();
  });

  it('renders error state when listChildren fails', async () => {
    listChildrenMock.mockRejectedValue(new Error('network'));
    renderPage();

    expect(await screen.findByText(/falha ao carregar crianças/i)).toBeInTheDocument();
  });

  it('shows empty state with CTA when no children', async () => {
    listChildrenMock.mockResolvedValue([]);
    renderPage();

    expect(await screen.findByText(/nenhuma criança cadastrada ainda/i)).toBeInTheDocument();
    // Texto "Adicionar Novo Filho" está em <span> nested — use match flexível
    expect(screen.getByText(/vá em/i)).toBeInTheDocument();
  });

  it('shows Ver Todos os Perfis link', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    renderPage();

    await screen.findByText('Lucas');
    expect(screen.getByRole('button', { name: /ver todos os perfis/i })).toBeInTheDocument();
  });
});
