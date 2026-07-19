import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Report } from '../api/reports';
import type { Child } from '../api/types';

const { getReportMock, listChildrenMock } = vi.hoisted(() => ({
  getReportMock: vi.fn(),
  listChildrenMock: vi.fn(),
}));
vi.mock('../api/reports', () => ({ getReport: getReportMock }));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: vi.fn(),
  updateChild: vi.fn(),
  pairChildDevice: vi.fn(),
}));

import { Reports } from './Reports';

const lucas: Child = {
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null,
  device: null, status: 'online', usedMinutes: 0, limitMinutes: 60,
  paired: false,
  dailyLimitEnabled: false,
  bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
  createdAt: null, updatedAt: null,
};

const sampleReport: Report = {
  range: 'week',
  from: '2026-05-30T00:00:00',
  to: '2026-06-06T00:00:00',
  kpis: {
    totalMinutes: 720,
    avgMinutesPerDay: 103,
    percentOfLimit: 0.74,
    deltaPctVsPrevious: -0.12,
  },
  dailyByChild: [
    { day: '2026-05-30', byChild: { 1: 90 } },
    { day: '2026-05-31', byChild: { 1: 120 } },
    { day: '2026-06-01', byChild: { 1: 80 } },
    { day: '2026-06-02', byChild: { 1: 100 } },
    { day: '2026-06-03', byChild: { 1: 110 } },
    { day: '2026-06-04', byChild: { 1: 110 } },
    { day: '2026-06-05', byChild: { 1: 110 } },
  ],
  topSites: [
    { domain: 'youtube.com', opens: 14, topChildId: 1 },
    { domain: 'khanacademy.org', opens: 8, topChildId: 1 },
  ],
  perChild: [
    { childId: 1, name: 'Lucas', totalMinutes: 720, avgMinutesPerDay: 103 },
  ],
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<Reports />, { wrapper });
}

describe('Reports page', () => {
  beforeEach(() => {
    getReportMock.mockReset();
    listChildrenMock.mockReset().mockResolvedValue([lucas]);
    // Reset URL pra evitar leak entre tests do estado de querystring
    window.history.replaceState(null, '', '/');
  });

  it('renders loading skeleton initially', () => {
    getReportMock.mockReturnValue(new Promise(() => {}));
    renderPage();
    expect(screen.getByText(/relatórios/i)).toBeInTheDocument();
  });

  it('renders KPI cards with formatted values', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    renderPage();

    expect(await screen.findByText('720')).toBeInTheDocument();
    // delta aparece em 2 cards (Tempo total delta + Delta vs anterior value)
    expect(screen.getAllByText(/-12%/).length).toBeGreaterThanOrEqual(1);
  });

  it('renders chart bars one per day', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    const { container } = renderPage();
    // aguarda heading Lucas (h3 do PerChildSection — único nesse role/name)
    await screen.findByRole('heading', { name: 'Lucas' });
    const dayLabels = container.querySelectorAll('[data-testid="chart-day"]');
    expect(dayLabels.length).toBe(7);
  });

  it('renders top sites with "X aberturas"', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    renderPage();

    expect(await screen.findByText('youtube.com')).toBeInTheDocument();
    expect(screen.getByText(/14 aberturas/i)).toBeInTheDocument();
  });

  it('renders per-child summary card', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    renderPage();
    expect(await screen.findByRole('heading', { name: 'Lucas' })).toBeInTheDocument();
    // total semana = 720m = 12h
    expect(screen.getByText(/12h/i)).toBeInTheDocument();
  });

  it('switching to Mês refetches', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole('heading', { name: 'Lucas' });

    await user.click(screen.getByRole('button', { name: /^mês$/i }));
    await waitFor(() => {
      expect(getReportMock).toHaveBeenCalledWith('month', 0);
    });
  });

  it('shows empty state when dailyByChild is empty', async () => {
    getReportMock.mockResolvedValue({ ...sampleReport, dailyByChild: [], topSites: [], perChild: [] });
    renderPage();
    expect(await screen.findByText(/ainda não há dados de uso/i)).toBeInTheDocument();
  });

  it('shows error state when getReport fails', async () => {
    getReportMock.mockRejectedValue(new Error('boom'));
    renderPage();
    expect(await screen.findByText(/falha ao carregar relatórios/i)).toBeInTheDocument();
  });

  it('inicializa range e child_id a partir do querystring', async () => {
    window.history.replaceState(null, '', '/?range=month&child_id=2');
    listChildrenMock.mockResolvedValue([
      lucas,
      { ...lucas, id: 2, slug: 'sofia', name: 'Sofia' },
    ]);
    getReportMock.mockResolvedValue(sampleReport);
    renderPage();

    await screen.findByRole('heading', { name: 'Lucas' });
    expect(getReportMock).toHaveBeenCalledWith('month', 2);
    expect(
      (screen.getByRole('combobox', { name: /filtrar por filho/i }) as HTMLSelectElement).value,
    ).toBe('2');
  });

  it('trocar range atualiza querystring via replaceState', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole('heading', { name: 'Lucas' });

    await user.click(screen.getByRole('button', { name: /^mês$/i }));

    await waitFor(() => {
      expect(window.location.search).toContain('range=month');
    });
  });

  it('child_id=0 nao aparece no querystring', async () => {
    window.history.replaceState(null, '', '/?range=week&child_id=5');
    listChildrenMock.mockResolvedValue([
      lucas,
      { ...lucas, id: 5, slug: 'x', name: 'X' },
    ]);
    getReportMock.mockResolvedValue(sampleReport);
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole('heading', { name: 'Lucas' });

    await user.selectOptions(
      screen.getByRole('combobox', { name: /filtrar por filho/i }),
      '0',
    );

    await waitFor(() => {
      expect(window.location.search).not.toContain('child_id');
    });
  });

  it('renders "—" when percentOfLimit is null', async () => {
    getReportMock.mockResolvedValue({
      ...sampleReport,
      kpis: { ...sampleReport.kpis, percentOfLimit: null },
    });
    renderPage();
    await screen.findByText('720');
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
  });

  it('botao Exportar fica disabled em loading e empty', async () => {
    getReportMock.mockReturnValue(new Promise(() => {}));
    renderPage();
    const button = await screen.findByRole('button', { name: /exportar/i });
    expect(button).toBeDisabled();
  });

  it('selector de filho refetcha report com child_id', async () => {
    getReportMock.mockResolvedValue(sampleReport);
    listChildrenMock.mockResolvedValue([
      lucas,
      { ...lucas, id: 2, slug: 'sofia', name: 'Sofia' },
    ]);
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole('heading', { name: 'Lucas' });

    await user.selectOptions(
      screen.getByRole('combobox', { name: /filtrar por filho/i }),
      '2',
    );
    await waitFor(() => {
      expect(getReportMock).toHaveBeenCalledWith('week', 2);
    });
  });

  it('click em Exportar dispara download CSV', async () => {
    getReportMock.mockResolvedValue(sampleReport);

    const createObjectURL = vi.fn().mockReturnValue('blob:mock-url');
    const revokeObjectURL = vi.fn();
    Object.defineProperty(globalThis.URL, 'createObjectURL', { writable: true, value: createObjectURL });
    Object.defineProperty(globalThis.URL, 'revokeObjectURL', { writable: true, value: revokeObjectURL });
    const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

    const user = userEvent.setup();
    renderPage();
    await screen.findByRole('heading', { name: 'Lucas' });

    await user.click(screen.getByRole('button', { name: /exportar/i }));

    expect(createObjectURL).toHaveBeenCalledTimes(1);
    expect(clickSpy).toHaveBeenCalledTimes(1);
    expect(revokeObjectURL).toHaveBeenCalledTimes(1);

    clickSpy.mockRestore();
  });
});
