import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ApprovalRequest, Child } from '../api/types';

const { listRequestsMock, listChildrenMock, approveMock, denyMock } = vi.hoisted(() => ({
  listRequestsMock: vi.fn(),
  listChildrenMock: vi.fn(),
  approveMock: vi.fn(),
  denyMock: vi.fn(),
}));
vi.mock('../api/requests', () => ({
  listRequests: listRequestsMock,
  approveRequest: approveMock,
  denyRequest: denyMock,
}));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: vi.fn(),
  updateChild: vi.fn(),
  pairChildDevice: vi.fn(),
}));

import { Approvals } from './Approvals';

const lucas: Child = {
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null,
  device: null, status: 'online', usedMinutes: 0, limitMinutes: 60,
  bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
  createdAt: null, updatedAt: null,
};

const pending: ApprovalRequest = {
  id: 10, childId: 1, kind: 'extra_time', description: 'Mais tempo',
  highlight: '+30 min', reason: 'Quero terminar fase', status: 'pending',
  decidedAt: null, decidedBy: null,
  createdAt: new Date(Date.now() - 5 * 60_000).toISOString(),
};
const approved: ApprovalRequest = {
  ...pending, id: 11, status: 'approved',
  decidedAt: new Date().toISOString(), decidedBy: 1,
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<Approvals />, { wrapper });
}

describe('Approvals page', () => {
  beforeEach(() => {
    listRequestsMock.mockReset();
    listChildrenMock.mockReset().mockResolvedValue([lucas]);
    approveMock.mockReset();
    denyMock.mockReset();
  });

  it('shows loading skeleton initially', () => {
    listRequestsMock.mockReturnValue(new Promise(() => {}));
    renderPage();
    expect(screen.getByText(/pendentes/i)).toBeInTheDocument();
  });

  it('renders pending list with badge counter', async () => {
    listRequestsMock.mockResolvedValue([pending, approved]);
    renderPage();

    // "Lucas" aparece em vários lugares (dropdown filter + card) — só conferir que existe
    const lucasMatches = await screen.findAllByText(/lucas/i);
    expect(lucasMatches.length).toBeGreaterThan(0);
    expect(screen.getByText(/mais tempo/i)).toBeInTheDocument();
    // badge "1" no tab Pendentes (1 pending)
    const pendingTab = screen.getByRole('button', { name: /pendentes/i });
    expect(pendingTab).toHaveTextContent('1');
  });

  it('switches to histórico tab and shows decided', async () => {
    listRequestsMock.mockResolvedValue([pending, approved]);
    const user = userEvent.setup();
    renderPage();

    await screen.findAllByText(/lucas/i);
    await user.click(screen.getByRole('button', { name: /histórico/i }));

    expect(await screen.findByText(/aprovado/i)).toBeInTheDocument();
  });

  it('shows empty state when no pending', async () => {
    listRequestsMock.mockResolvedValue([approved]); // só decided
    renderPage();

    expect(await screen.findByText(/nada por aqui/i)).toBeInTheDocument();
  });

  it('calls approveRequest with id when Aprovar clicked', async () => {
    listRequestsMock.mockResolvedValue([pending]);
    approveMock.mockResolvedValue({ ...pending, status: 'approved' });
    const user = userEvent.setup();
    renderPage();

    await screen.findAllByText(/lucas/i);
    await user.click(screen.getByRole('button', { name: /aprovar/i }));

    await waitFor(() => {
      expect(approveMock).toHaveBeenCalled();
      expect(approveMock.mock.calls[0]?.[0]).toBe(10);
    });
  });

  it('calls denyRequest when Negar clicked', async () => {
    listRequestsMock.mockResolvedValue([pending]);
    denyMock.mockResolvedValue({ ...pending, status: 'denied' });
    const user = userEvent.setup();
    renderPage();

    await screen.findAllByText(/lucas/i);
    await user.click(screen.getByRole('button', { name: /negar/i }));

    await waitFor(() => {
      expect(denyMock).toHaveBeenCalled();
      expect(denyMock.mock.calls[0]?.[0]).toBe(10);
    });
  });

  it('shows error state when listRequests fails', async () => {
    listRequestsMock.mockRejectedValue(new Error('boom'));
    renderPage();

    expect(await screen.findByText(/falha ao carregar pedidos/i)).toBeInTheDocument();
  });
});
