import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CompanionStatusCard } from './CompanionStatusCard';
import * as api from '../api/companion';

function wrap(ui: ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

const pairedStatus = {
  paired: true,
  status: 'active',
  deviceUuid: 'u1',
  deviceName: 'Moto',
  androidVersion: '13',
  companionVersion: '1.0',
  deviceOwnerEnabled: true,
  accessibilityEnabled: true,
  deviceAdminEnabled: true,
  playStoreEnabled: false,
  lastSync: null,
  installedApps: [],
  blockedApps: [],
};

describe('CompanionStatusCard', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('mostra "Revogar" quando pareado e chama a API ao confirmar', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({ ...pairedStatus });
    const revoke = vi.spyOn(api, 'revokeCompanion').mockResolvedValue({ revoked: true });
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    wrap(<CompanionStatusCard childId={7} childName="Lucas" />);
    fireEvent.click(await screen.findByRole('button', { name: /revogar/i }));
    await waitFor(() => expect(revoke).toHaveBeenCalledWith(7));
  });

  it('não mostra "Revogar" quando não pareado', async () => {
    vi.spyOn(api, 'getCompanionStatus').mockResolvedValue({
      ...pairedStatus,
      paired: false,
      status: 'unpaired',
    });
    wrap(<CompanionStatusCard childId={7} childName="Lucas" />);
    await screen.findByText(/status do companion/i);
    expect(screen.queryByRole('button', { name: /revogar/i })).not.toBeInTheDocument();
  });
});
