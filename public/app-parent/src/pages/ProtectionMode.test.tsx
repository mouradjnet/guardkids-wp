import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Child } from '../api/types';

const { getModeMock, setModeMock, listChildrenMock } = vi.hoisted(() => ({
  getModeMock: vi.fn(),
  setModeMock: vi.fn(),
  listChildrenMock: vi.fn(),
}));
vi.mock('../api/companion', () => ({
  getProtectionMode: getModeMock,
  setProtectionMode: setModeMock,
}));
vi.mock('../api/children', () => ({ listChildren: listChildrenMock }));

// Componentes-filho viram markers pra não disparar as chamadas de API deles.
vi.mock('../components/CompanionStatusCard', () => ({
  CompanionStatusCard: ({ childName }: { childName: string }) => (
    <div data-testid="companion-status">{childName}</div>
  ),
}));
vi.mock('../components/AppBlocklist', () => ({
  AppBlocklist: () => <div data-testid="app-blocklist" />,
}));
vi.mock('../components/CompanionWizard', () => ({
  CompanionWizard: () => <div data-testid="companion-wizard" />,
}));

import { ProtectionMode } from './ProtectionMode';

const lucas: Child = {
  id: 1, slug: 'lucas', name: 'Lucas', age: 9, avatarUrl: null,
  device: null, status: 'offline', usedMinutes: 0, limitMinutes: 60,
  paired: false,
  dailyLimitEnabled: false, bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY', createdAt: null, updatedAt: null,
};

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return render(<ProtectionMode />, { wrapper });
}

describe('ProtectionMode page', () => {
  beforeEach(() => {
    getModeMock.mockReset().mockResolvedValue({ mode: 'family' });
    setModeMock.mockReset().mockResolvedValue({ mode: 'maximum' });
    listChildrenMock.mockReset().mockResolvedValue([]);
  });

  it('renderiza os dois cards de modo', async () => {
    renderPage();
    expect(await screen.findByText('Modo Familiar')).toBeInTheDocument();
    expect(screen.getByText('Proteção Máxima')).toBeInTheDocument();
  });

  it('modo family (default) marca o card Familiar como Ativo', async () => {
    renderPage();
    await screen.findByText('Modo Familiar');
    // o card selecionado tem botão "Modo ativo" desabilitado
    expect(screen.getByRole('button', { name: /modo ativo/i })).toBeDisabled();
    // o outro card oferece "Ativar"
    expect(screen.getByRole('button', { name: /^ativar$/i })).toBeEnabled();
  });

  it('clicar em Ativar na Proteção Máxima chama setProtectionMode(maximum)', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('Proteção Máxima');
    await user.click(screen.getByRole('button', { name: /^ativar$/i }));
    await waitFor(() => expect(setModeMock).toHaveBeenCalledWith('maximum'));
  });

  it('modo maximum COM filhos mostra a seção "Companion por filho"', async () => {
    getModeMock.mockResolvedValue({ mode: 'maximum' });
    listChildrenMock.mockResolvedValue([lucas]);
    renderPage();
    expect(await screen.findByText(/companion por filho/i)).toBeInTheDocument();
    expect(screen.getByTestId('companion-status')).toHaveTextContent('Lucas');
  });

  it('modo maximum SEM filhos não mostra a seção', async () => {
    getModeMock.mockResolvedValue({ mode: 'maximum' });
    listChildrenMock.mockResolvedValue([]);
    renderPage();
    await screen.findByText('Proteção Máxima');
    expect(screen.queryByText(/companion por filho/i)).not.toBeInTheDocument();
  });

  it('mostra erro visível quando trocar de modo falha', async () => {
    setModeMock.mockRejectedValue(new Error('servidor fora'));
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('Proteção Máxima');
    await user.click(screen.getByRole('button', { name: /^ativar$/i }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/servidor fora/i);
  });
});
