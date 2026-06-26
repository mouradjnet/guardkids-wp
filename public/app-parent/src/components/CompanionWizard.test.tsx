import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CompanionWizard } from './CompanionWizard';
import * as api from '../api/companion';

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

const activeStatus = {
  paired: true,
  status: 'active',
  deviceUuid: 'u1',
  deviceName: null,
  androidVersion: '13',
  companionVersion: '1.0',
  deviceOwnerEnabled: false,
  accessibilityEnabled: false,
  deviceAdminEnabled: false,
  playStoreEnabled: true,
  lastSync: null,
  installedApps: [],
  blockedApps: [],
};

const pairResponse = {
  token: 't',
  deviceUuid: 'u1',
  endpoint: 'e',
  expiresAt: 'x',
  qrPayload: '{}',
  notice: '',
};

describe('CompanionWizard — guard de re-pareamento', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('exige confirmação antes de re-parear um aparelho já conectado', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({ ...activeStatus });
    const pair = vi.spyOn(api, 'pairCompanion').mockResolvedValue({ ...pairResponse });

    wrap(<CompanionWizard childId={7} childName="Lucas" onClose={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: /tenho o companion instalado/i }));

    // status active → botão vira "Gerar novo QR"
    fireEvent.click(await screen.findByRole('button', { name: /gerar novo qr/i }));

    // 1º clique mostra confirmação e NÃO pareia
    expect(await screen.findByText(/desconectar o aparelho atual/i)).toBeInTheDocument();
    expect(pair).not.toHaveBeenCalled();

    // confirmar pareia (revoga o aparelho atual)
    fireEvent.click(screen.getByRole('button', { name: /desconectar e gerar novo qr/i }));
    await waitFor(() => expect(pair).toHaveBeenCalledWith(7));
  });

  it('pareia direto quando não há aparelho conectado', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({
      ...activeStatus,
      paired: false,
      status: 'unpaired',
    });
    const pair = vi.spyOn(api, 'pairCompanion').mockResolvedValue({ ...pairResponse });

    wrap(<CompanionWizard childId={7} childName="Lucas" onClose={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: /tenho o companion instalado/i }));
    fireEvent.click(await screen.findByRole('button', { name: /gerar qr code/i }));

    await waitFor(() => expect(pair).toHaveBeenCalledWith(7));
  });
});
