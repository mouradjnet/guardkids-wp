import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SafeZone } from '../api/types';

const {
  listSafeZonesMock,
  createSafeZoneMock,
  updateSafeZoneMock,
  deleteSafeZoneMock,
} = vi.hoisted(() => ({
  listSafeZonesMock: vi.fn(),
  createSafeZoneMock: vi.fn(),
  updateSafeZoneMock: vi.fn(),
  deleteSafeZoneMock: vi.fn(),
}));
vi.mock('../api/safeZones', () => ({
  listSafeZones: listSafeZonesMock,
  createSafeZone: createSafeZoneMock,
  updateSafeZone: updateSafeZoneMock,
  deleteSafeZone: deleteSafeZoneMock,
}));

vi.mock('react-leaflet', () => ({
  MapContainer: ({ children }: { children: ReactNode }) => (
    <div data-testid="map-container">{children}</div>
  ),
  TileLayer: () => <div data-testid="tile-layer" />,
  Marker: () => <div data-testid="marker" />,
  useMapEvents: () => null,
}));

import { ZonasSeguras } from './ZonasSeguras';

const casa: SafeZone = {
  id: 1,
  name: 'Casa',
  address: 'Rua X, 123',
  latitude: -8.05,
  longitude: -34.88,
  radiusMeters: 100,
  createdAt: null,
  updatedAt: null,
};

const escola: SafeZone = {
  ...casa,
  id: 2,
  name: 'Escola',
  address: null,
  radiusMeters: 200,
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<ZonasSeguras />, { wrapper });
}

describe('ZonasSeguras page', () => {
  beforeEach(() => {
    listSafeZonesMock.mockReset();
    createSafeZoneMock.mockReset();
    updateSafeZoneMock.mockReset();
    deleteSafeZoneMock.mockReset();
  });

  it('renders empty state with CTA when list is empty', async () => {
    listSafeZonesMock.mockResolvedValue([]);
    renderPage();

    expect(await screen.findByText(/nenhuma zona cadastrada/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /criar primeira zona/i })).toBeInTheDocument();
  });

  it('renders list of zones with address fallback', async () => {
    listSafeZonesMock.mockResolvedValue([casa, escola]);
    renderPage();

    expect(await screen.findByText('Casa')).toBeInTheDocument();
    expect(screen.getByText('Escola')).toBeInTheDocument();
    expect(screen.getByText('Rua X, 123')).toBeInTheDocument();
    expect(screen.getByText('Raio 200m')).toBeInTheDocument();
  });

  it('opens create dialog from header button', async () => {
    listSafeZonesMock.mockResolvedValue([casa]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Casa');
    const newButtons = screen.getAllByRole('button', { name: /nova zona/i });
    await user.click(newButtons[0]);

    expect(screen.getByRole('dialog', { name: /nova zona segura/i })).toBeInTheDocument();
  });

  it('opens edit dialog pre-populated from card button', async () => {
    listSafeZonesMock.mockResolvedValue([casa]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Casa');
    await user.click(screen.getByRole('button', { name: /editar/i }));

    expect(screen.getByRole('dialog', { name: /editar zona/i })).toBeInTheDocument();
    const nameInput = screen.getByLabelText(/nome \*/i) as HTMLInputElement;
    expect(nameInput.value).toBe('Casa');
  });

  it('opens confirm modal on excluir and triggers deleteSafeZone on confirm', async () => {
    listSafeZonesMock.mockResolvedValue([casa]);
    deleteSafeZoneMock.mockResolvedValue(undefined);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Casa');
    await user.click(screen.getByRole('button', { name: /excluir/i }));

    const dialog = screen.getByRole('dialog', { name: /excluir zona/i });
    await user.click(within(dialog).getByRole('button', { name: /excluir/i }));

    await waitFor(() => {
      expect(deleteSafeZoneMock).toHaveBeenCalled();
      expect(deleteSafeZoneMock.mock.calls[0][0]).toBe(1);
    });
  });

  it('renders error state when listSafeZones fails', async () => {
    listSafeZonesMock.mockRejectedValue(new Error('boom'));
    renderPage();

    expect(await screen.findByText(/falha ao carregar/i)).toBeInTheDocument();
  });
});
