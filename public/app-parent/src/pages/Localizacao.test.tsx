import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Child, LocationFix } from '../api/types';

const { listChildrenMock, listLocationsMock, listSettingsMock, getLicenseMock, listSafeZonesMock } =
  vi.hoisted(() => ({
    listChildrenMock: vi.fn(),
    listLocationsMock: vi.fn(),
    listSettingsMock: vi.fn(),
    getLicenseMock: vi.fn(),
    listSafeZonesMock: vi.fn(),
  }));
vi.mock('../api/safeZones', () => ({
  listSafeZones: listSafeZonesMock,
  createSafeZone: vi.fn(),
  updateSafeZone: vi.fn(),
  deleteSafeZone: vi.fn(),
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
vi.mock('../api/settings', () => ({
  listSettings: listSettingsMock,
  updateSettings: vi.fn(),
}));
vi.mock('../api/license', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/license')>();
  return { ...actual, getLicense: getLicenseMock };
});

import { ACTIVE_PREMIUM_SNAPSHOT, FREE_NONE_SNAPSHOT } from '../test/licenseMock';

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
  Circle: ({ children, center, radius }: { children?: ReactNode; center: [number, number]; radius: number }) => (
    <div data-testid="circle" data-center={center.join(',')} data-radius={radius}>
      {children}
    </div>
  ),
  Tooltip: ({ children }: { children: ReactNode }) => <div data-testid="tooltip">{children}</div>,
}));

import { Localizacao } from './Localizacao';

const lucas: Child = {
  id: 1,
  slug: 'lucas',
  name: 'Lucas',
  age: 9,
  avatarUrl: null,
  device: null,
  paired: false,
  status: 'online',
  usedMinutes: 0,
  limitMinutes: 60,
  dailyLimitEnabled: false,
  bedtimeEnabled: false,
  bedtimeStart: null,
  bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
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
    listSettingsMock.mockReset().mockResolvedValue({ location_enabled: true });
    // Default: licença premium ativa (libera o conteúdo da página).
    // Tests específicos de bloqueio sobrescrevem com FREE_NONE_SNAPSHOT.
    getLicenseMock.mockReset().mockResolvedValue(ACTIVE_PREMIUM_SNAPSHOT);
    listSafeZonesMock.mockReset().mockResolvedValue([]);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('mostra PremiumLock overlay quando licença está em estado Free', async () => {
    getLicenseMock.mockResolvedValue(FREE_NONE_SNAPSHOT);
    listChildrenMock.mockResolvedValue([lucas]);
    renderPage();

    expect(
      await screen.findByText(/localização é uma feature premium/i),
    ).toBeInTheDocument();
    // Conteúdo interno (children list, map) não deve aparecer
    expect(screen.queryByText(/sem localização registrada/i)).not.toBeInTheDocument();
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

    expect(await screen.findByText(/vamos ativar a localização/i)).toBeInTheDocument();
  });

  it('desenha as zonas seguras como círculos no mapa', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    listLocationsMock.mockResolvedValue([recentFix()]);
    listSafeZonesMock.mockResolvedValue([
      { id: 1, name: 'Escola', address: null, latitude: -23.5, longitude: -46.6, radiusMeters: 200, createdAt: null, updatedAt: null },
      { id: 2, name: 'Casa', address: null, latitude: -23.6, longitude: -46.7, radiusMeters: 80, createdAt: null, updatedAt: null },
    ]);
    renderPage();

    await screen.findByTestId('map-container');
    const circles = await screen.findAllByTestId('circle');

    expect(circles).toHaveLength(2);
    expect(circles[0]).toHaveAttribute('data-center', '-23.5,-46.6');
    expect(circles[0]).toHaveAttribute('data-radius', '200');
    // o nome da zona precisa estar no mapa, senao o circulo nao diz nada
    expect(screen.getByText('Escola')).toBeInTheDocument();
    expect(screen.getByText('Casa')).toBeInTheDocument();
  });

  it('nao quebra o mapa quando nao ha zonas cadastradas', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    listLocationsMock.mockResolvedValue([recentFix()]);
    listSafeZonesMock.mockResolvedValue([]);
    renderPage();

    expect(await screen.findByTestId('map-container')).toBeInTheDocument();
    expect(screen.queryAllByTestId('circle')).toHaveLength(0);
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

  it('marks status as Online when last heartbeat < 5min', async () => {
    listChildrenMock.mockResolvedValue([
      { ...lucas, lastSeenAt: new Date(Date.now() - 2 * 60_000).toISOString() },
    ]);
    listLocationsMock.mockResolvedValue([recentFix({})]);
    renderPage();

    expect(await screen.findByText(/^Online$/)).toBeInTheDocument();
  });

  it('marks status as Offline when last heartbeat > 5min', async () => {
    listChildrenMock.mockResolvedValue([
      { ...lucas, lastSeenAt: new Date(Date.now() - 10 * 60_000).toISOString() },
    ]);
    listLocationsMock.mockResolvedValue([recentFix({})]);
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
