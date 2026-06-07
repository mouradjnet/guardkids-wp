import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { MapContainer, Marker, Popup, TileLayer } from 'react-leaflet';
import { listChildren } from '../api/children';
import { ApiError } from '../api/client';
import { listLocations } from '../api/locations';
import type { LocationFix } from '../api/types';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';

const ONLINE_THRESHOLD_MS = 5 * 60 * 1000;

export function Localizacao() {
  const childrenQuery = useQuery({
    queryKey: ['children'],
    queryFn: listChildren,
  });

  const [selectedId, setSelectedId] = useState<number | null>(null);

  useEffect(() => {
    if (selectedId === null && childrenQuery.data && childrenQuery.data.length > 0) {
      setSelectedId(childrenQuery.data[0].id);
    }
  }, [childrenQuery.data, selectedId]);

  const childId = selectedId ?? 0;
  const locationQuery = useQuery({
    queryKey: ['location', childId],
    queryFn: () => listLocations(childId, 1),
    enabled: childId > 0,
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
  });

  const lastFix = locationQuery.data?.[0] ?? null;
  const child = childrenQuery.data?.find((c) => c.id === selectedId) ?? null;

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Localização"
        subtitle="Veja onde seus filhos estão quando o app deles está aberto."
      />

      {childrenQuery.isLoading && <LoadingState />}
      {childrenQuery.error && <ErrorState error={childrenQuery.error} />}
      {childrenQuery.data && childrenQuery.data.length === 0 && <NoChildrenState />}

      {childrenQuery.data && childrenQuery.data.length > 0 && (
        <>
          <ChildSelector
            children={childrenQuery.data}
            value={selectedId}
            onChange={setSelectedId}
          />

          {selectedId !== null && locationQuery.isFetching && lastFix === null && <LoadingState />}
          {selectedId !== null && !locationQuery.isFetching && lastFix === null && <EmptyLocationState />}
          {lastFix !== null && child !== null && <LocationMap fix={lastFix} childName={child.name} />}
        </>
      )}
    </main>
  );
}

function ChildSelector({
  children,
  value,
  onChange,
}: {
  children: Array<{ id: number; name: string }>;
  value: number | null;
  onChange: (id: number) => void;
}) {
  return (
    <label className="flex items-center gap-3">
      <span className="text-label-md font-semibold text-on-surface-variant">Criança:</span>
      <select
        aria-label="Selecionar criança"
        value={value ?? ''}
        onChange={(e) => onChange(Number(e.target.value))}
        className="rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-on-surface focus:border-primary focus:outline-none"
      >
        {children.map((c) => (
          <option key={c.id} value={c.id}>
            {c.name}
          </option>
        ))}
      </select>
    </label>
  );
}

function LocationMap({ fix, childName }: { fix: LocationFix; childName: string }) {
  const position: [number, number] = [fix.latitude, fix.longitude];
  const recordedAtMs = Date.parse(fix.recordedAt);
  const online = Date.now() - recordedAtMs < ONLINE_THRESHOLD_MS;
  const ageMin = Math.max(1, Math.floor((Date.now() - recordedAtMs) / 60_000));

  return (
    <>
      <div className="glass-panel overflow-hidden rounded-2xl" style={{ height: '60vh', minHeight: 360 }}>
        <MapContainer
          center={position}
          zoom={16}
          scrollWheelZoom
          style={{ height: '100%', width: '100%' }}
        >
          <TileLayer
            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          />
          <Marker position={position}>
            <Popup>
              <strong>{childName}</strong>
              <br />
              {fix.battery !== null ? `Bateria: ${fix.battery}%` : 'Bateria: —'}
              <br />
              Atualizado {ageMin}min atrás
            </Popup>
          </Marker>
        </MapContainer>
      </div>

      <div className="grid grid-cols-1 gap-gutter md:grid-cols-3">
        <StatusCard
          label="Status"
          value={online ? 'Online' : 'Offline'}
          icon={online ? 'wifi' : 'wifi_off'}
          tone={online ? 'good' : 'neutral'}
        />
        <StatusCard
          label="Bateria"
          value={fix.battery !== null ? `${fix.battery}%` : '—'}
          icon="battery_full"
          tone="neutral"
        />
        <StatusCard
          label="Precisão"
          value={fix.accuracy !== null ? `±${fix.accuracy}m` : '—'}
          icon="my_location"
          tone="neutral"
        />
      </div>
    </>
  );
}

function StatusCard({
  label,
  value,
  icon,
  tone,
}: {
  label: string;
  value: string;
  icon: string;
  tone: 'good' | 'neutral';
}) {
  return (
    <div className="glass-panel rounded-2xl p-4">
      <div className="flex items-center gap-2 text-on-surface-variant">
        <Icon name={icon} className="text-base" />
        <span className="text-label-sm">{label}</span>
      </div>
      <p
        className={`mt-1 font-display text-headline-md ${
          tone === 'good' ? 'text-secondary' : 'text-on-surface'
        }`}
      >
        {value}
      </p>
    </div>
  );
}

function LoadingState() {
  return (
    <div className="glass-panel flex h-40 items-center justify-center rounded-2xl text-on-surface-variant">
      <Icon name="progress_activity" className="animate-spin text-2xl" />
      <span className="ml-2 text-label-md">Carregando…</span>
    </div>
  );
}

function ErrorState({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}

function NoChildrenState() {
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl p-8 text-on-surface-variant">
      <Icon name="child_care" className="text-3xl" />
      <p className="text-label-md font-semibold">Nenhuma criança cadastrada</p>
      <p className="text-center text-label-sm">
        Vá em <span className="font-semibold text-primary">Filhos</span> e adicione um perfil pra ver localização aqui.
      </p>
    </div>
  );
}

function EmptyLocationState() {
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl p-8 text-on-surface-variant">
      <Icon name="location_off" className="text-3xl" />
      <p className="text-label-md font-semibold">Sem localização registrada</p>
      <p className="text-center text-label-sm">
        A criança precisa autorizar localização no app dela e mantê-lo aberto.
      </p>
    </div>
  );
}
