import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Child } from '../api/types';

const { listChildrenMock, updateChildMock } = vi.hoisted(() => ({
  listChildrenMock: vi.fn(),
  updateChildMock: vi.fn(),
}));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: vi.fn(),
  updateChild: updateChildMock,
  pairChildDevice: vi.fn(),
}));

import { TimeLimits } from './TimeLimits';

const lucas: Child = {
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null,
  device: null, status: 'online', usedMinutes: 0, limitMinutes: 60,
  bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
  createdAt: null, updatedAt: null,
};
const paloma: Child = {
  ...lucas, id: 2, slug: 'paloma', name: 'Paloma', limitMinutes: 120,
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<TimeLimits />, { wrapper });
}

describe('TimeLimits page', () => {
  beforeEach(() => {
    listChildrenMock.mockReset();
    updateChildMock.mockReset();
  });

  it('renders page header', () => {
    listChildrenMock.mockReturnValue(new Promise(() => {}));
    renderPage();
    expect(screen.getByText(/limites de tempo/i)).toBeInTheDocument();
  });

  it('renders chip for each child from API', async () => {
    listChildrenMock.mockResolvedValue([lucas, paloma]);
    renderPage();
    expect(await screen.findByRole('button', { name: /lucas/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /paloma/i })).toBeInTheDocument();
  });

  it('shows empty state when no children', async () => {
    listChildrenMock.mockResolvedValue([]);
    renderPage();
    expect(await screen.findByText(/sem filhos cadastrados/i)).toBeInTheDocument();
  });

  it('shows error state when listChildren fails', async () => {
    listChildrenMock.mockRejectedValue(new Error('boom'));
    renderPage();
    expect(await screen.findByText(/falha ao carregar filhos/i)).toBeInTheDocument();
  });

  it('selects first child by default and renders DailyTimeCard', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    renderPage();
    // preset 60min ("1h") aparece quando o card renderiza
    expect(await screen.findByRole('button', { name: '1h' })).toBeInTheDocument();
  });

  it('switches selection when another chip clicked', async () => {
    listChildrenMock.mockResolvedValue([lucas, paloma]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByRole('button', { name: /lucas/i });
    await user.click(screen.getByRole('button', { name: /paloma/i }));

    expect(
      await screen.findByRole('heading', { name: /linha do dia.*paloma/i }),
    ).toBeInTheDocument();
  });

  it('calls updateChild with limit_minutes when preset clicked', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    updateChildMock.mockResolvedValue({ ...lucas, limitMinutes: 90 });
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: '1h30' }));

    await waitFor(() => {
      expect(updateChildMock).toHaveBeenCalled();
      expect(updateChildMock.mock.calls[0]?.[0]).toBe(1);
      expect(updateChildMock.mock.calls[0]?.[1]).toEqual({ limit_minutes: 90 });
    });
  });

  it('shows error message when updateChild mutation fails', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    updateChildMock.mockRejectedValue(new Error('mutation failed'));
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole('button', { name: '2h' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/falha ao salvar/i);
  });

  it('renders ComingSoonBadge sections (Bedtime, Weekly, Timeline)', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    renderPage();
    await screen.findByRole('button', { name: '1h' });

    expect(screen.getByRole('heading', { name: /modo dormir/i })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /dias permitidos/i })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /linha do dia/i })).toBeInTheDocument();
  });
});
