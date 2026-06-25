import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SessionsSection } from './SessionsSection';
import * as api from '../api/sessions';

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

describe('SessionsSection', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('lista sessões com selo da atual e botão de encerrar', async () => {
    vi.spyOn(api, 'listSessions').mockResolvedValue({
      sessions: [
        { device: 'Chrome · Windows', browser: 'Chrome', os: 'Windows', ip: '1.1.1.1', lastAccess: 300, current: true },
        { device: 'Firefox · Linux', browser: 'Firefox', os: 'Linux', ip: '2.2.2.2', lastAccess: 100, current: false },
      ],
    });
    wrap(<SessionsSection />);
    expect(await screen.findByText('Chrome · Windows')).toBeInTheDocument();
    expect(screen.getByText(/esta sessão/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /outros aparelhos/i })).toBeInTheDocument();
  });

  it('esconde o botão quando só há a sessão atual', async () => {
    vi.spyOn(api, 'listSessions').mockResolvedValue({
      sessions: [
        { device: 'Chrome · Windows', browser: 'Chrome', os: 'Windows', ip: '1.1.1.1', lastAccess: 300, current: true },
      ],
    });
    wrap(<SessionsSection />);
    await screen.findByText('Chrome · Windows');
    expect(screen.queryByRole('button', { name: /outros aparelhos/i })).not.toBeInTheDocument();
  });

  it('encerra as outras após confirmar', async () => {
    vi.spyOn(api, 'listSessions').mockResolvedValue({
      sessions: [
        { device: 'Chrome · Windows', browser: 'Chrome', os: 'Windows', ip: '1.1.1.1', lastAccess: 300, current: true },
        { device: 'Firefox · Linux', browser: 'Firefox', os: 'Linux', ip: '2.2.2.2', lastAccess: 100, current: false },
      ],
    });
    const destroy = vi
      .spyOn(api, 'destroyOtherSessions')
      .mockResolvedValue({ destroyed: 1, sessions: [] });
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    wrap(<SessionsSection />);
    fireEvent.click(await screen.findByRole('button', { name: /outros aparelhos/i }));
    await waitFor(() => expect(destroy).toHaveBeenCalledTimes(1));
  });
});
