import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Circle, MapContainer, Marker, Popup, TileLayer, Tooltip } from 'react-leaflet';
import { listChildren } from '../api/children';
import { ApiError } from '../api/client';
import { listLocations } from '../api/locations';
import { listSafeZones } from '../api/safeZones';
import { listSettings } from '../api/settings';
import type { Child, LocationFix, SafeZone } from '../api/types';
import { formatRelative } from '../lib/requestDisplay';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { PremiumLock } from '../components/PremiumLock';
import { useLicense } from '../hooks/useLicense';

const ONLINE_THRESHOLD_MS = 5 * 60 * 1000;

export function Localizacao() {
  const license = useLicense();

  if (!license.isLoading && !license.can('location')) {
    return (
      <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
        <PageHeader title="Localização" subtitle="Veja onde seu filho está em tempo real." />
        <div className="min-h-[300px]">
          <PremiumLock
            featureId="location"
            title="Localização é uma feature Premium"
            description="Acompanhe em tempo real onde seu filho está, com histórico e zonas seguras."
          />
        </div>
      </main>
    );
  }

  return <LocalizacaoContent />;
}

function LocalizacaoContent() {
  const childrenQuery = useQuery({
    queryKey: ['children'],
    queryFn: listChildren,
  });
  const settingsQuery = useQuery({ queryKey: ['settings'], queryFn: listSettings });

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
  const locationEnabled = settingsQuery.data?.location_enabled === true;

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

          {child !== null && (
            <DeviceStatus child={child} locationEnabled={locationEnabled} />
          )}

          {selectedId !== null && locationQuery.isFetching && lastFix === null && <LoadingState />}
          {selectedId !== null && !locationQuery.isFetching && lastFix === null && child !== null && (
            <ActivationChecklist child={child} locationEnabled={locationEnabled} />
          )}
          {lastFix !== null && child !== null && (
            <LocationMap fix={lastFix} childName={child.name} childOnline={child.status === 'online'} />
          )}
        </>
      )}
    </main>
  );
}

function DeviceStatus({ child, locationEnabled }: { child: Child; locationEnabled: boolean }) {
  const isOnline = child.status === 'online';
  const lastSync = child.updatedAt ? formatRelative(child.updatedAt) : 'desconhecido';
  return (
    <div className="glass-panel grid grid-cols-1 gap-3 rounded-2xl p-4 sm:grid-cols-3">
      <StatusPill
        icon={isOnline ? 'wifi' : 'wifi_off'}
        label="Estado do dispositivo"
        value={isOnline ? 'Online' : 'Offline'}
        tone={isOnline ? 'good' : 'neutral'}
      />
      <StatusPill
        icon="schedule"
        label="Última sincronização"
        value={lastSync}
        tone="neutral"
      />
      <StatusPill
        icon={locationEnabled ? 'location_on' : 'location_off'}
        label="Permissão de localização"
        value={locationEnabled ? 'Liberada' : 'Bloqueada'}
        tone={locationEnabled ? 'good' : 'warn'}
      />
    </div>
  );
}

function StatusPill({
  icon,
  label,
  value,
  tone,
}: {
  icon: string;
  label: string;
  value: string;
  tone: 'good' | 'neutral' | 'warn';
}) {
  const toneClass =
    tone === 'good'
      ? 'text-secondary'
      : tone === 'warn'
        ? 'text-tertiary-container'
        : 'text-on-surface';
  return (
    <div className="flex items-center gap-3">
      <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-surface-container-high ${toneClass}`}>
        <Icon name={icon} className="text-lg" filled />
      </div>
      <div className="min-w-0">
        <div className="truncate text-label-sm text-on-surface-variant">{label}</div>
        <div className={`truncate font-display text-label-md font-bold ${toneClass}`}>{value}</div>
      </div>
    </div>
  );
}

function ActivationChecklist({
  child,
  locationEnabled,
}: {
  child: Child;
  locationEnabled: boolean;
}) {
  const items = [
    { id: 'install', label: 'Instalar o GuardKids no dispositivo da criança', done: true },
    { id: 'pair', label: 'Fazer pareamento com token do painel', done: true },
    { id: 'permission', label: 'Autorizar permissão de localização', done: locationEnabled },
    { id: 'open', label: 'Abrir o aplicativo e mantê-lo ativo', done: child.status === 'online' },
  ];
  const pending = items.filter((i) => !i.done).length;

  return (
    <div className="glass-panel flex flex-col gap-4 rounded-2xl p-6">
      <header>
        <h3 className="font-display text-headline-md text-on-surface">
          Vamos ativar a localização?
        </h3>
        <p className="mt-1 text-label-md text-on-surface-variant">
          {pending === 0
            ? 'Tudo pronto — a localização vai aparecer assim que o app reportar uma posição.'
            : `Faltam ${pending} ${pending === 1 ? 'passo' : 'passos'} pra começar a ver a posição em tempo real.`}
        </p>
      </header>
      <ul className="space-y-2">
        {items.map((item) => (
          <li
            key={item.id}
            className="flex items-center gap-3 rounded-lg bg-surface-container-low p-3"
          >
            <span
              className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${
                item.done ? 'bg-secondary-container text-secondary' : 'border-2 border-outline-variant text-on-surface-variant'
              }`}
              aria-hidden
            >
              {item.done ? <Icon name="check" className="text-base" filled /> : null}
            </span>
            <span
              className={`flex-1 text-label-md ${
                item.done ? 'text-on-surface-variant line-through' : 'text-on-surface'
              }`}
            >
              {item.label}
            </span>
          </li>
        ))}
      </ul>
    </div>
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

function LocationMap({
  fix,
  childName,
  childOnline,
}: {
  fix: LocationFix;
  childName: string;
  childOnline: boolean;
}) {
  // As zonas eram cadastro sem consumidor: ninguém as desenhava e nada no
  // backend as lia. Aqui elas ganham a função mínima que justifica existirem —
  // o pai OLHA o mapa e vê se o filho está dentro da escola. Sem geofencing:
  // quem compara é o olho, não o servidor.
  const zonesQuery = useQuery({ queryKey: ['safe-zones'], queryFn: listSafeZones });
  const zones: SafeZone[] = zonesQuery.data ?? [];

  const position: [number, number] = [fix.latitude, fix.longitude];
  const recordedAtMs = Date.parse(fix.recordedAt);
  const online = childOnline && Date.now() - recordedAtMs < ONLINE_THRESHOLD_MS;
  const ageMin = Math.max(1, Math.floor((Date.now() - recordedAtMs) / 60_000));
  const ageLabel =
    ageMin < 60 ? `${ageMin} min atrás` : `${Math.floor(ageMin / 60)} h atrás`;

  return (
    <>
      <div className="relative glass-panel overflow-hidden rounded-2xl" style={{ height: '60vh', minHeight: 360 }}>
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
          {zones.map((zone) => (
            <Circle
              key={zone.id}
              center={[zone.latitude, zone.longitude]}
              radius={zone.radiusMeters}
              pathOptions={{ color: '#1e3a8a', fillColor: '#1e3a8a', fillOpacity: 0.08, weight: 2 }}
            >
              <Tooltip>{zone.name}</Tooltip>
            </Circle>
          ))}
          <Marker position={position}>
            <Popup>
              <strong>{childName}</strong>
              <br />
              {fix.battery !== null ? `Bateria: ${fix.battery}%` : 'Bateria: —'}
              <br />
              Atualizado {ageLabel}
            </Popup>
          </Marker>
        </MapContainer>
        {!online && (
          <div
            role="status"
            className="pointer-events-none absolute left-3 top-3 z-[1000] flex items-center gap-2 rounded-full border border-outline-variant bg-surface/95 px-3 py-1.5 shadow-sm"
          >
            <Icon name="cloud_off" className="text-base text-tertiary-container" filled />
            <span className="text-label-sm font-semibold text-on-surface">
              Última posição conhecida — {ageLabel}
            </span>
          </div>
        )}
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

