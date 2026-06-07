import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Child, LocationFix } from '../api/types';

const { listChildrenMock, listLocationsMock } = vi.hoisted(() => ({
  listChildrenMock: vi.fn(),
  listLocationsMock: vi.fn(),
}));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: vi.fn(),
  updateChild: vi.fn(),
  pairChildDevice: vi.fn(),
}));
vi.mock('../api/locations', () => ({
  listLocations: listLocationsMock,
}));

// react-leaflet usa window APIs que jsdom não suporta — stubamos os componentes
vi.mock('react-leaflet', () => ({
  MapContainer: ({ children }: { children: ReactNode }) => (
    <div data-testid="map-container">{children}</div>
  ),
  TileLayer: () => <div data-testid="tile-layer" />,
  Marker: ({ children }: { children: ReactNode }) => (
    <div data-testid="marker">{children}</div>
  ),
  Popup: ({ children }: { children: ReactNode }) => (
    <div data-testid="popup">{children}</div>
  ),
}));

import { Localizacao } from './Localizacao';

const lucas: Child = {
  id: 1,
  slug: 'lucas',
  name: 'Lucas',
  age: 9,
  avatarUrl: null,
  device: null,
  status: 'online',
  usedMinutes: 0,
  limitMinutes: 60,
  createdAt: null,
  updatedAt: null,
};

const paloma: Child = { ...lucas, id: 2, slug: 'paloma', name: 'Paloma' };

function recentFix(overrides: Partial<LocationFix> = {}): LocationFix {
  return {
    id: 99,
    childId: 1,
    latitude: -8.0476,
    longitude: -34.877,
    accuracy: 12,
    battery: 58,
    recordedAt: new Date(Date.now() - 60_000).toISOString(),
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<Localizacao />, { wrapper });
}

describe('Localizacao page', () => {
  beforeEach(() => {
    listChildrenMock.mockReset();
    listLocationsMock.mockReset();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('shows empty state when no children exist', async () => {
    listChildrenMock.mockResolvedValue([]);
    renderPage();

    expect(await screen.findByText(/nenhuma criança cadastrada/i)).toBeInTheDocument();
  });

  it('shows "no location" state when child has no fixes', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    listLocationsMock.mockResolvedValue([]);
    renderPage();

    expect(await screen.findByText(/sem localização registrada/i)).toBeInTheDocument();
  });

  it('renders map + marker when fix available', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    listLocationsMock.mockResolvedValue([recentFix()]);
    renderPage();

    expect(await screen.findByTestId('map-container')).toBeInTheDocument();
    expect(screen.getByTestId('marker')).toBeInTheDocument();
    // "Lucas" aparece no option do select e no popup; basta confirmar que há
    expect(screen.getAllByText('Lucas').length).toBeGreaterThan(0);
    expect(screen.getByText(/bateria: 58%/i)).toBeInTheDocument();
  });

  it('marks status as Online when last fix < 5min', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    listLocationsMock.mockResolvedValue([
      recentFix({ recordedAt: new Date(Date.now() - 2 * 60_000).toISOString() }),
    ]);
    renderPage();

    expect(await screen.findByText(/^Online$/)).toBeInTheDocument();
  });

  it('marks status as Offline when last fix > 5min', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    listLocationsMock.mockResolvedValue([
      recentFix({ recordedAt: new Date(Date.now() - 10 * 60_000).toISOString() }),
    ]);
    renderPage();

    expect(await screen.findByText(/^Offline$/)).toBeInTheDocument();
  });

  it('falls back to "—" when battery is null', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    listLocationsMock.mockResolvedValue([recentFix({ battery: null })]);
    renderPage();

    // Aparece tanto no card status quanto no popup do marker (mesmo texto)
    expect(await screen.findAllByText('—')).not.toHaveLength(0);
  });

  it('switches child via dropdown and refetches', async () => {
    listChildrenMock.mockResolvedValue([lucas, paloma]);
    listLocationsMock.mockResolvedValue([]);
    const user = userEvent.setup();
    renderPage();

    const dropdown = await screen.findByLabelText(/selecionar criança/i);
    await user.selectOptions(dropdown, '2');

    expect(listLocationsMock).toHaveBeenCalledWith(2, 1);
  });
});
