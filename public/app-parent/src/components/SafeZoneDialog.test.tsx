import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SafeZone } from '../api/types';

const { createMock, updateMock, geocodeMock } = vi.hoisted(() => ({
  createMock: vi.fn(),
  updateMock: vi.fn(),
  geocodeMock: vi.fn(),
}));
vi.mock('../api/safeZones', () => ({
  createSafeZone: createMock,
  updateSafeZone: updateMock,
}));
vi.mock('../api/geocode', () => ({
  geocodeAddress: geocodeMock,
}));
vi.mock('react-leaflet', () => ({
  MapContainer: ({ children }: { children: ReactNode }) => <div data-testid="map">{children}</div>,
  TileLayer: () => null,
  Marker: () => null,
  useMapEvents: () => null,
}));

import { SafeZoneDialog } from './SafeZoneDialog';

function renderDialog(props: Partial<Parameters<typeof SafeZoneDialog>[0]> = {}) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return render(
    <SafeZoneDialog open mode="create" onClose={vi.fn()} {...props} />,
    { wrapper },
  );
}

const escola: SafeZone = {
  id: 7, name: 'Escola Velha', address: 'Rua X', latitude: -8.1, longitude: -34.9,
  radiusMeters: 500, icon: null, createdAt: null, updatedAt: null,
};

describe('SafeZoneDialog', () => {
  beforeEach(() => {
    createMock.mockReset().mockResolvedValue(undefined);
    updateMock.mockReset().mockResolvedValue(undefined);
    geocodeMock
      .mockReset()
      .mockResolvedValue({ lat: -8.05, lng: -34.88, displayName: 'Recife' });
  });

  it('não renderiza nada quando open=false', () => {
    renderDialog({ open: false });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('escolher um modelo preenche o nome e avança pro passo 2', async () => {
    const user = userEvent.setup();
    renderDialog();
    expect(screen.getByText(/passo 1 de 4/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /escola/i }));
    expect(await screen.findByText(/passo 2 de 4/i)).toBeInTheDocument();
  });

  it('passo 0 sem nome mantém o Próximo desabilitado', () => {
    renderDialog();
    expect(screen.getByRole('button', { name: /próximo/i })).toBeDisabled();
  });

  it('fluxo completo cria a zona com o payload certo (raio default 100)', async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.click(screen.getByRole('button', { name: /escola/i })); // → passo 2
    await user.click(await screen.findByRole('button', { name: /próximo/i })); // passo 2 → 3 (raio)
    await user.click(await screen.findByRole('button', { name: /próximo/i })); // passo 3 → 4 (revisão)
    await user.click(await screen.findByRole('button', { name: /salvar zona/i }));

    await waitFor(() =>
      expect(createMock).toHaveBeenCalledWith({
        name: 'Escola',
        address: null,
        latitude: -8.0476,
        longitude: -34.877,
        radius_meters: 100,
        icon: null,
      }),
    );
    expect(updateMock).not.toHaveBeenCalled();
  });

  it('mostra o seletor de emoji com os presets no passo do nome', () => {
    renderDialog();
    expect(screen.getByRole('button', { name: /emoji 🏠/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /emoji 🏫/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /emoji 🏥/i })).toBeInTheDocument();
  });

  it('oferece os raios 50/100/200 no passo do raio', async () => {
    const user = userEvent.setup();
    renderDialog();
    await user.click(screen.getByRole('button', { name: /escola/i })); // → passo 2 (posição)
    await user.click(await screen.findByRole('button', { name: /próximo/i })); // → passo 3 (raio)

    expect(await screen.findByRole('button', { name: /^50m$/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^100m$/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^200m$/ })).toBeInTheDocument();
  });

  it('clicar em Buscar chama geocodeAddress com o endereço digitado', async () => {
    const user = userEvent.setup();
    renderDialog();
    await user.click(screen.getByRole('button', { name: /escola/i })); // → passo 2 (posição)

    const addressInput = await screen.findByPlaceholderText(/rua x/i);
    await user.type(addressInput, 'Av Boa Viagem 1000, Recife');
    await user.click(screen.getByRole('button', { name: /buscar/i }));

    await waitFor(() =>
      expect(geocodeMock).toHaveBeenCalledWith('Av Boa Viagem 1000, Recife'),
    );
  });

  it('modo edit prefila e salva via updateSafeZone(id, ...)', async () => {
    const user = userEvent.setup();
    renderDialog({ mode: 'edit', initial: escola });

    // vai direto até o passo final (nome já vem preenchido)
    await user.click(screen.getByRole('button', { name: /próximo/i })); // 1→2
    await user.click(await screen.findByRole('button', { name: /próximo/i })); // 2→3
    await user.click(await screen.findByRole('button', { name: /próximo/i })); // 3→4
    await user.click(await screen.findByRole('button', { name: /salvar zona/i }));

    await waitFor(() => expect(updateMock).toHaveBeenCalled());
    expect(updateMock.mock.calls[0][0]).toBe(7);
    expect(updateMock.mock.calls[0][1]).toMatchObject({ name: 'Escola Velha', radius_meters: 500 });
    expect(createMock).not.toHaveBeenCalled();
  });
});
