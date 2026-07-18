import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppBlocklist } from './AppBlocklist';
import * as api from '../api/companion';

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

const status = {
  paired: true,
  status: 'active',
  deviceUuid: 'u',
  deviceName: null,
  androidVersion: '11',
  companionVersion: '0.1',
  deviceOwnerEnabled: false,
  accessibilityEnabled: false,
  deviceAdminEnabled: false,
  playStoreEnabled: true,
  lastSync: null,
  installedApps: [
    { packageName: 'com.whatsapp', label: 'WhatsApp' },
    { packageName: 'com.tiktok', label: 'TikTok' },
  ],
  blockedApps: ['com.tiktok'],
};

describe('AppBlocklist', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('marca os bloqueados e salva a seleção', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({ ...status });
    const save = vi
      .spyOn(api, 'setBlockedApps')
      .mockResolvedValue({ blockedApps: ['com.tiktok', 'com.whatsapp'] });

    wrap(<AppBlocklist childId={3} />);

    // selected é preenchido num useEffect após a query resolver, então há um
    // render intermediário com o checkbox ainda desmarcado; re-consulta o DOM
    // a cada poll (não guarda a ref) pra não travar num nó já substituído.
    await waitFor(() =>
      expect(screen.getByRole('checkbox', { name: /tiktok/i })).toBeChecked(),
    );
    const wa = screen.getByRole('checkbox', { name: /whatsapp/i });
    expect(wa).not.toBeChecked();

    fireEvent.click(wa);
    fireEvent.click(screen.getByRole('button', { name: /salvar bloqueios/i }));

    await waitFor(() =>
      expect(save).toHaveBeenCalledWith(3, expect.arrayContaining(['com.tiktok', 'com.whatsapp'])),
    );
  });

  it('mostra estado vazio quando não há apps reportados', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({ ...status, installedApps: [] });
    wrap(<AppBlocklist childId={3} />);
    expect(await screen.findByText(/aguardando o aparelho reportar/i)).toBeInTheDocument();
  });

  it('avisa quando a Acessibilidade está off no aparelho', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({ ...status, accessibilityEnabled: false });
    wrap(<AppBlocklist childId={3} />);
    expect(await screen.findByText(/requer acessibilidade ativa/i)).toBeInTheDocument();
  });

  it('não avisa quando a Acessibilidade está on', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({ ...status, accessibilityEnabled: true });
    wrap(<AppBlocklist childId={3} />);
    await screen.findByRole('checkbox', { name: /tiktok/i });
    expect(screen.queryByText(/requer acessibilidade ativa/i)).not.toBeInTheDocument();
  });

  it('mostra erro visível quando salvar bloqueios falha (não some mudo)', async () => {
    // accessibilityEnabled:true pra o único alert ser o do erro de save
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({ ...status, accessibilityEnabled: true });
    vi.spyOn(api, 'setBlockedApps').mockRejectedValue(new Error('servidor fora'));

    wrap(<AppBlocklist childId={3} />);
    fireEvent.click(await screen.findByRole('button', { name: /salvar bloqueios/i }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/falha ao salvar/i);
    expect(alert).toHaveTextContent(/servidor fora/i);
  });
});
