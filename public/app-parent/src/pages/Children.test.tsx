import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Child } from '../api/types';

const { listChildrenMock, createChildMock, pairMock } = vi.hoisted(() => ({
  listChildrenMock: vi.fn(),
  createChildMock: vi.fn(),
  pairMock: vi.fn(),
}));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: createChildMock,
  updateChild: vi.fn(),
  pairChildDevice: pairMock,
}));

import { Children } from './Children';

const lucas: Child = {
  id: 1,
  slug: 'lucas',
  name: 'Lucas',
  age: 9,
  avatarUrl: null,
  device: 'Tablet',
  status: 'online',
  usedMinutes: 30,
  limitMinutes: 60,
  createdAt: null,
  updatedAt: null,
};

const paloma: Child = {
  ...lucas,
  id: 2,
  slug: 'paloma',
  name: 'Paloma',
  age: 6,
  status: 'offline',
  device: null,
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<Children />, { wrapper });
}

describe('Children page', () => {
  beforeEach(() => {
    listChildrenMock.mockReset();
    createChildMock.mockReset();
    pairMock.mockReset();
  });

  it('shows loading state initially', () => {
    listChildrenMock.mockReturnValue(new Promise(() => {})); // never resolves
    renderPage();
    expect(screen.getByText(/carregando filhos/i)).toBeInTheDocument();
  });

  it('renders grid with cards for each child', async () => {
    listChildrenMock.mockResolvedValue([lucas, paloma]);
    renderPage();

    expect(await screen.findByText('Lucas')).toBeInTheDocument();
    expect(screen.getByText('Paloma')).toBeInTheDocument();
    // status badges
    expect(screen.getByText(/online agora/i)).toBeInTheDocument();
    expect(screen.getByText(/^offline$/i)).toBeInTheDocument();
  });

  it('renders error state when listChildren fails', async () => {
    listChildrenMock.mockRejectedValue(new Error('network'));
    renderPage();

    expect(await screen.findByText(/falha ao carregar/i)).toBeInTheDocument();
  });

  it('renders only AddChildCard when list is empty', async () => {
    listChildrenMock.mockResolvedValue([]);
    renderPage();

    // só o placeholder dashboard placeholder card aparece
    expect(await screen.findAllByText(/adicionar novo filho/i)).not.toHaveLength(0);
  });

  it('opens add dialog from header button', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Lucas');
    // Clicar no botão do header (não no card placeholder)
    const headerBtn = screen.getAllByRole('button', { name: /adicionar novo filho/i })[0];
    await user.click(headerBtn);

    expect(screen.getByRole('dialog', { name: /adicionar novo filho/i })).toBeInTheDocument();
  });

  it('opens pair dialog from card icon button', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Lucas');
    const pairBtn = screen.getByRole('button', { name: /parear dispositivo/i });
    await user.click(pairBtn);

    expect(screen.getByRole('dialog', { name: /parear dispositivo/i })).toBeInTheDocument();
  });

  it('shows fallback initial when avatarUrl is null', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    renderPage();

    await screen.findByText('Lucas');
    // "L" deve aparecer no fallback do avatar
    expect(screen.getByText('L', { selector: 'div' })).toBeInTheDocument();
  });

  it('renders age + device when both available', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    renderPage();

    expect(await screen.findByText(/9 anos.*tablet/i)).toBeInTheDocument();
  });

  it('shows "Idade não informada" when age is null', async () => {
    listChildrenMock.mockResolvedValue([{ ...lucas, age: null }]);
    renderPage();

    await waitFor(() => {
      expect(screen.getByText(/idade não informada/i)).toBeInTheDocument();
    });
  });
});
