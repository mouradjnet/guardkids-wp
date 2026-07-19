import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ApprovalRequest, Child } from '../api/types';

const { listChildrenMock, listRequestsMock, approveMock, denyMock } = vi.hoisted(() => ({
  listChildrenMock: vi.fn(),
  listRequestsMock: vi.fn(),
  approveMock: vi.fn(),
  denyMock: vi.fn(),
}));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: vi.fn(),
  updateChild: vi.fn(),
  pairChildDevice: vi.fn(),
}));
vi.mock('../api/requests', () => ({
  listRequests: listRequestsMock,
  approveRequest: approveMock,
  denyRequest: denyMock,
}));

import { PendingRequests } from './PendingRequests';

const lucas: Child = {
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null,
  device: null, status: 'online', usedMinutes: 0, limitMinutes: 60,
  paired: false,
  dailyLimitEnabled: false,
  bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
  createdAt: null, updatedAt: null,
};

const extraTime: ApprovalRequest = {
  id: 10, childId: 1, kind: 'extra_time', description: 'Mais tempo',
  highlight: '+30 min', reason: null, status: 'pending',
  decidedAt: null, decidedBy: null,
  createdAt: new Date(Date.now() - 5 * 60_000).toISOString(),
};

const unblock: ApprovalRequest = {
  id: 11, childId: 99, kind: 'unblock_site', description: 'Desbloquear youtube',
  highlight: null, reason: null, status: 'pending',
  decidedAt: null, decidedBy: null,
  createdAt: new Date(Date.now() - 2 * 60_000).toISOString(),
};

function renderComponent() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<PendingRequests />, { wrapper });
}

describe('PendingRequests', () => {
  beforeEach(() => {
    listChildrenMock.mockReset().mockResolvedValue([lucas]);
    listRequestsMock.mockReset();
    approveMock.mockReset();
    denyMock.mockReset();
  });

  it('renders heading', () => {
    listRequestsMock.mockReturnValue(new Promise(() => {}));
    renderComponent();
    expect(screen.getByRole('heading', { name: /solicitações pendentes/i })).toBeInTheDocument();
  });

  it('shows empty state when no pending requests', async () => {
    listRequestsMock.mockResolvedValue([]);
    renderComponent();
    expect(await screen.findByText(/sem pedidos no momento/i)).toBeInTheDocument();
  });

  it('renders badge with count when items exist', async () => {
    listRequestsMock.mockResolvedValue([extraTime, unblock]);
    renderComponent();
    await screen.findByText('Lucas');
    expect(screen.getByRole('heading', { name: /solicitações pendentes/i })).toHaveTextContent('2');
  });

  it('renders child name from children query', async () => {
    listRequestsMock.mockResolvedValue([extraTime]);
    renderComponent();
    expect(await screen.findByText('Lucas')).toBeInTheDocument();
  });

  it('falls back to "Filho #N" when child not found', async () => {
    listRequestsMock.mockResolvedValue([unblock]);
    renderComponent();
    expect(await screen.findByText('Filho #99')).toBeInTheDocument();
  });

  it('renders description and highlight', async () => {
    listRequestsMock.mockResolvedValue([extraTime]);
    renderComponent();
    await screen.findByText('Lucas');
    expect(screen.getByText(/mais tempo/i)).toBeInTheDocument();
    expect(screen.getByText('+30 min')).toBeInTheDocument();
  });

  it('renders relative time', async () => {
    listRequestsMock.mockResolvedValue([extraTime]);
    renderComponent();
    await screen.findByText('Lucas');
    expect(screen.getByText(/há 5 min/i)).toBeInTheDocument();
  });

  it('calls approveRequest with id when Aprovar clicked', async () => {
    listRequestsMock.mockResolvedValue([extraTime]);
    approveMock.mockResolvedValue({ ...extraTime, status: 'approved' });
    const user = userEvent.setup();
    renderComponent();

    await screen.findByText('Lucas');
    await user.click(screen.getByRole('button', { name: /aprovar/i }));

    await waitFor(() => {
      expect(approveMock).toHaveBeenCalled();
      expect(approveMock.mock.calls[0]?.[0]).toBe(10);
    });
  });

  it('calls denyRequest with id when Negar clicked', async () => {
    listRequestsMock.mockResolvedValue([extraTime]);
    denyMock.mockResolvedValue({ ...extraTime, status: 'denied' });
    const user = userEvent.setup();
    renderComponent();

    await screen.findByText('Lucas');
    await user.click(screen.getByRole('button', { name: /negar/i }));

    await waitFor(() => {
      expect(denyMock).toHaveBeenCalled();
      expect(denyMock.mock.calls[0]?.[0]).toBe(10);
    });
  });

  it('disables both action buttons while mutation pending', async () => {
    listRequestsMock.mockResolvedValue([extraTime]);
    approveMock.mockReturnValue(new Promise(() => {})); // never resolves
    const user = userEvent.setup();
    renderComponent();

    await screen.findByText('Lucas');
    const approve = screen.getByRole('button', { name: /aprovar/i });
    const deny = screen.getByRole('button', { name: /negar/i });

    await user.click(approve);

    await waitFor(() => {
      expect(approve).toBeDisabled();
      expect(deny).toBeDisabled();
    });
  });

  it('mostra erro visível quando a decisão falha (não some mudo)', async () => {
    listRequestsMock.mockResolvedValue([extraTime]);
    approveMock.mockRejectedValue(new Error('rede caiu'));
    const user = userEvent.setup();
    renderComponent();

    await screen.findByText('Lucas');
    await user.click(screen.getByRole('button', { name: /aprovar/i }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/falha ao decidir/i);
    expect(alert).toHaveTextContent(/rede caiu/i);
  });
});
