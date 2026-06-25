import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const {
  getTwoFactorStatusMock,
  setupTwoFactorMock,
  activateTwoFactorMock,
  regenerateRecoveryCodesMock,
  disableTwoFactorMock,
} = vi.hoisted(() => ({
  getTwoFactorStatusMock: vi.fn(),
  setupTwoFactorMock: vi.fn(),
  activateTwoFactorMock: vi.fn(),
  regenerateRecoveryCodesMock: vi.fn(),
  disableTwoFactorMock: vi.fn(),
}));
vi.mock('../api/twofactor', () => ({
  getTwoFactorStatus: getTwoFactorStatusMock,
  setupTwoFactor: setupTwoFactorMock,
  activateTwoFactor: activateTwoFactorMock,
  regenerateRecoveryCodes: regenerateRecoveryCodesMock,
  disableTwoFactor: disableTwoFactorMock,
}));

vi.mock('qrcode', () => ({
  default: { toDataURL: vi.fn().mockResolvedValue('data:image/png;base64,xxx') },
}));

import { TwoFactorSection } from './TwoFactorSection';

function renderComponent() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<TwoFactorSection />, { wrapper });
}

describe('TwoFactorSection', () => {
  beforeEach(() => {
    getTwoFactorStatusMock.mockReset();
    setupTwoFactorMock.mockReset();
    activateTwoFactorMock.mockReset();
    regenerateRecoveryCodesMock.mockReset();
    disableTwoFactorMock.mockReset();
  });

  it('mostra estado desativado com botão de ativar', async () => {
    getTwoFactorStatusMock.mockResolvedValue({ enabled: false, recoveryRemaining: 0 });
    renderComponent();
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /ativar/i })).toBeInTheDocument(),
    );
  });

  it('mostra estado ativo com opções de gerenciar', async () => {
    getTwoFactorStatusMock.mockResolvedValue({ enabled: true, recoveryRemaining: 8 });
    renderComponent();
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /desativar/i })).toBeInTheDocument(),
    );
  });
});
