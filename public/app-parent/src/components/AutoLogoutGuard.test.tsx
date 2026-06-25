import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AutoLogoutGuard } from './AutoLogoutGuard';
import * as settingsApi from '../api/settings';

// Captura as options passadas pro hook (cujos timers já têm teste próprio),
// pra exercitar só o wiring do Guard sem depender de timers reais.
const state = vi.hoisted(() => ({
  opts: null as null | {
    enabled: boolean;
    minutes: number;
    onWarn: () => void;
    onTimeout: () => void;
    onActivityWhileWarned?: () => void;
  },
}));
vi.mock('../hooks/useIdleTimeout', () => ({
  useIdleTimeout: (opts: typeof state.opts) => {
    state.opts = opts;
    return { reset: () => {} };
  },
}));

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('AutoLogoutGuard', () => {
  beforeEach(() => {
    state.opts = null;
    vi.restoreAllMocks();
    (window as { guardkidsApi?: unknown }).guardkidsApi = {
      nonce: 'x',
      root: '/',
      logoutUrl: 'https://site.test/logout',
    };
  });

  it('passa enabled=false e minutes default quando desligado', async () => {
    vi.spyOn(settingsApi, 'listSettings').mockResolvedValue({ 'security.auto_logout': false });
    wrap(<AutoLogoutGuard />);
    await waitFor(() => expect(state.opts?.enabled).toBe(false));
    expect(state.opts?.minutes).toBe(15);
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
  });

  it('mostra o aviso no onWarn e some no "Continuar logado"', async () => {
    vi.spyOn(settingsApi, 'listSettings').mockResolvedValue({
      'security.auto_logout': true,
      'security.auto_logout_minutes': 30,
    });
    wrap(<AutoLogoutGuard />);
    await waitFor(() => expect(state.opts?.enabled).toBe(true));
    expect(state.opts?.minutes).toBe(30);

    act(() => state.opts!.onWarn());
    expect(screen.getByRole('alertdialog')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /continuar logado/i }));
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
  });
});
