import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Child } from '../api/types';

const { listChildrenMock } = vi.hoisted(() => ({ listChildrenMock: vi.fn() }));
vi.mock('../api/children', () => ({ listChildren: listChildrenMock }));

// Diálogos viram markers pra não disparar API deles.
vi.mock('./AddChildDialog', () => ({
  AddChildDialog: ({ open }: { open: boolean }) =>
    open ? <div data-testid="add-child-dialog" /> : null,
}));
vi.mock('./PairDeviceDialog', () => ({
  PairDeviceDialog: ({ open, childName }: { open: boolean; childName: string }) =>
    open ? <div data-testid="pair-dialog">{childName}</div> : null,
}));

import { OnboardingChecklist } from './OnboardingChecklist';

const child = (over: Partial<Child>): Child => ({
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null, device: null,
  paired: false, status: 'offline', usedMinutes: 0, limitMinutes: 60,
  dailyLimitEnabled: false, bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY', createdAt: null, updatedAt: null, ...over,
});

function renderWith(children: Child[]) {
  listChildrenMock.mockResolvedValue(children);
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children: c }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{c}</QueryClientProvider>
  );
  return render(<OnboardingChecklist />, { wrapper });
}

describe('OnboardingChecklist', () => {
  beforeEach(() => listChildrenMock.mockReset());

  it('0 filhos: passo 1 com CTA, passo 2 travado', async () => {
    renderWith([]);
    expect(await screen.findByRole('button', { name: /adicionar filho/i })).toBeInTheDocument();
    expect(screen.getByText(/adicione um filho primeiro/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /parear dispositivo/i })).not.toBeInTheDocument();
  });

  it('1 filho não pareado: passo 1 feito, passo 2 com CTA', async () => {
    renderWith([child({ paired: false })]);
    expect(await screen.findByRole('button', { name: /parear dispositivo/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /adicionar filho/i })).not.toBeInTheDocument();
  });

  it('filho pareado: não renderiza nada', async () => {
    const { container } = renderWith([child({ paired: true })]);
    // deixa a query ['children'] resolver antes de checar a ausência
    await new Promise((r) => setTimeout(r, 80));
    expect(container.querySelector('[aria-label="Primeiros passos"]')).toBeNull();
  });

  it('CTA do passo 1 abre o AddChildDialog', async () => {
    const user = userEvent.setup();
    renderWith([]);
    await user.click(await screen.findByRole('button', { name: /adicionar filho/i }));
    expect(screen.getByTestId('add-child-dialog')).toBeInTheDocument();
  });

  it('CTA do passo 2 abre o PairDeviceDialog do primeiro filho não pareado', async () => {
    const user = userEvent.setup();
    renderWith([child({ id: 5, name: 'Ana', paired: false })]);
    await user.click(await screen.findByRole('button', { name: /parear dispositivo/i }));
    expect(screen.getByTestId('pair-dialog')).toHaveTextContent('Ana');
  });
});
