import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { RecentBlock } from '../api/reports';

const { getRecentBlocksMock } = vi.hoisted(() => ({
  getRecentBlocksMock: vi.fn(),
}));
vi.mock('../api/reports', () => ({
  getRecentBlocks: getRecentBlocksMock,
}));

import { RecentBlocks } from './RecentBlocks';

const bedtimeBlock: RecentBlock = {
  id: 1,
  childId: 10,
  childName: 'Maria',
  detail: 'bedtime',
  createdAt: new Date(Date.now() - 5 * 60_000).toISOString(),
};

const weekdayBlock: RecentBlock = {
  id: 2,
  childId: 11,
  childName: 'João',
  detail: 'weekday',
  createdAt: new Date(Date.now() - 90 * 60_000).toISOString(),
};

function renderComponent() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<RecentBlocks />, { wrapper });
}

describe('RecentBlocks', () => {
  beforeEach(() => {
    getRecentBlocksMock.mockReset();
  });

  it('renders heading', () => {
    getRecentBlocksMock.mockReturnValue(new Promise(() => {}));
    renderComponent();
    expect(screen.getByRole('heading', { name: /bloqueios recentes/i })).toBeInTheDocument();
  });

  it('shows empty state when list is empty', async () => {
    getRecentBlocksMock.mockResolvedValue([]);
    renderComponent();
    expect(await screen.findByText(/nenhum bloqueio recente/i)).toBeInTheDocument();
  });

  it('renders one item per block with child name and detail label', async () => {
    getRecentBlocksMock.mockResolvedValue([bedtimeBlock, weekdayBlock]);
    renderComponent();

    expect(await screen.findByText('Maria')).toBeInTheDocument();
    expect(screen.getByText('João')).toBeInTheDocument();
    expect(screen.getByText('Hora de dormir')).toBeInTheDocument();
    expect(screen.getByText('Dia bloqueado')).toBeInTheDocument();
  });

  it('falls back to "Filho #N" when childName comes empty', async () => {
    getRecentBlocksMock.mockResolvedValue([{ ...bedtimeBlock, childName: '' }]);
    renderComponent();
    expect(await screen.findByText('Filho #10')).toBeInTheDocument();
  });

  it('shows error state when fetch fails', async () => {
    getRecentBlocksMock.mockRejectedValue(new Error('boom'));
    renderComponent();
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('boom');
  });
});
