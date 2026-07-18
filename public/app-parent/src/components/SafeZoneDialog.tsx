import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { MapContainer, Marker, TileLayer, useMapEvents } from 'react-leaflet';
import { ApiError } from '../api/client';
import { createSafeZone, updateSafeZone } from '../api/safeZones';
import type { SafeZone } from '../api/types';
import { Icon } from './Icon';

type Props = {
  open: boolean;
  mode: 'create' | 'edit';
  initial?: SafeZone | null;
  onClose: () => void;
};

const DEFAULT_LAT = -8.0476;
const DEFAULT_LNG = -34.877;

type StepIdx = 0 | 1 | 2 | 3;
const STEPS: { title: string; subtitle: string }[] = [
  { title: 'Tipo da zona', subtitle: 'Escolha um modelo ou personalize' },
  { title: 'Onde fica?', subtitle: 'Endereço + localização no mapa' },
  { title: 'Raio da zona', subtitle: 'Margem de tolerância em volta do ponto' },
  { title: 'Confirmação', subtitle: 'Revise antes de salvar' },
];

type Template = {
  id: string;
  icon: string;
  label: string;
  defaultName: string;
};

const TEMPLATES: Template[] = [
  { id: 'home', icon: 'home', label: '🏠 Casa', defaultName: 'Casa' },
  { id: 'school', icon: 'school', label: '🏫 Escola', defaultName: 'Escola' },
  { id: 'grandma', icon: 'elderly_woman', label: '👵 Casa da avó', defaultName: 'Casa da avó' },
  { id: 'custom', icon: 'edit', label: '✏️ Personalizada', defaultName: '' },
];

const RADIUS_OPTIONS = [100, 250, 500, 1000] as const;

const inputClass =
  'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-base text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none';

export function SafeZoneDialog({ open, mode, initial, onClose }: Props) {
  const queryClient = useQueryClient();
  const [step, setStep] = useState<StepIdx>(0);
  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
  const [lat, setLat] = useState(DEFAULT_LAT);
  const [lng, setLng] = useState(DEFAULT_LNG);
  const [radius, setRadius] = useState<number>(250);

  useEffect(() => {
    if (!open) return;
    if (mode === 'edit' && initial) {
      setName(initial.name);
      setAddress(initial.address ?? '');
      setLat(initial.latitude);
      setLng(initial.longitude);
      setRadius(initial.radiusMeters);
      setStep(0); // edit também começa no step 0 pra permitir editar o nome
    } else {
      setName('');
      setAddress('');
      setLat(DEFAULT_LAT);
      setLng(DEFAULT_LNG);
      setRadius(250);
      setStep(0);
    }
  }, [open, mode, initial]);

  const mutation = useMutation({
    mutationFn: (input: {
      name: string;
      address: string | null;
      latitude: number;
      longitude: number;
      radius_meters: number;
    }) =>
      mode === 'edit' && initial
        ? updateSafeZone(initial.id, input)
        : createSafeZone(input),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['safe-zones'] });
      onClose();
    },
  });

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onClose]);

  if (!open) return null;

  function pickTemplate(t: Template) {
    if (mode === 'edit') return;
    if (!name || isTemplateName(name)) setName(t.defaultName);
    setStep(1);
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    const trimmed = name.trim();
    if (!trimmed) return;
    mutation.mutate({
      name: trimmed,
      address: address.trim() || null,
      latitude: lat,
      longitude: lng,
      radius_meters: radius,
    });
  }

  const errorMessage =
    mutation.error instanceof ApiError
      ? `${mutation.error.message} (${mutation.error.status})`
      : mutation.error instanceof Error
        ? mutation.error.message
        : null;

  const canAdvance =
    (step === 0 && name.trim() !== '') ||
    (step === 1 && true) ||
    (step === 2 && radius > 0) ||
    step === 3;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="safe-zone-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-on-surface/40 p-4"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <form
        onSubmit={submit}
        className="glass-panel w-full max-w-lg rounded-2xl bg-surface p-6 shadow-ambient"
      >
        <div className="flex items-start justify-between">
          <div>
            <h2 id="safe-zone-title" className="font-display text-headline-md text-on-surface">
              {mode === 'edit' ? 'Editar zona' : 'Nova zona segura'}
            </h2>
            <p className="mt-0.5 text-label-sm text-on-surface-variant">
              Passo {step + 1} de 4 — {STEPS[step].subtitle}
            </p>
          </div>
          <button
            type="button"
            aria-label="Fechar"
            onClick={onClose}
            className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50 hover:text-primary"
          >
            <Icon name="close" />
          </button>
        </div>

        <Stepper current={step} />

        <div className="mt-5 min-h-[280px]">
          {step === 0 && (
            <StepTemplate
              name={name}
              onNameChange={setName}
              onPick={pickTemplate}
            />
          )}
          {step === 1 && (
            <StepLocation
              address={address}
              onAddressChange={setAddress}
              lat={lat}
              lng={lng}
              onPick={(la, ln) => {
                setLat(la);
                setLng(ln);
              }}
            />
          )}
          {step === 2 && <StepRadius radius={radius} onChange={setRadius} />}
          {step === 3 && (
            <StepReview name={name} address={address} lat={lat} lng={lng} radius={radius} />
          )}
        </div>

        {errorMessage && (
          <p role="alert" className="mt-4 rounded-lg bg-error/10 p-3 text-label-sm text-error">
            {errorMessage}
          </p>
        )}

        <div className="mt-6 flex justify-between gap-2">
          <button
            type="button"
            onClick={() => (step === 0 ? onClose() : setStep((step - 1) as StepIdx))}
            disabled={mutation.isPending}
            className="rounded-lg border border-outline-variant bg-surface-container px-4 py-2 text-label-md font-semibold text-on-surface hover:bg-surface-variant disabled:opacity-50"
          >
            {step === 0 ? 'Cancelar' : 'Voltar'}
          </button>
          {step < 3 ? (
            <button
              type="button"
              onClick={() => canAdvance && setStep((step + 1) as StepIdx)}
              disabled={!canAdvance}
              className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container disabled:opacity-60"
            >
              Próximo
              <Icon name="arrow_forward" className="text-sm" />
            </button>
          ) : (
            <button
              type="submit"
              disabled={mutation.isPending || !name.trim()}
              className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container disabled:opacity-60"
            >
              {mutation.isPending ? (
                <>
                  <Icon name="progress_activity" className="animate-spin text-sm" />
                  Salvando…
                </>
              ) : (
                <>
                  <Icon name="check" className="text-sm" filled />
                  Salvar zona
                </>
              )}
            </button>
          )}
        </div>
      </form>
    </div>
  );
}

function isTemplateName(n: string): boolean {
  return TEMPLATES.some((t) => t.defaultName === n);
}

function Stepper({ current }: { current: StepIdx }) {
  return (
    <ol aria-label="Etapas do wizard" className="mt-4 flex items-center gap-2">
      {STEPS.map((_, i) => (
        <li
          key={i}
          aria-current={i === current ? 'step' : undefined}
          className={`h-1.5 flex-1 rounded-full transition-colors ${
            i <= current ? 'bg-primary' : 'bg-outline-variant/50'
          }`}
        />
      ))}
    </ol>
  );
}

function StepTemplate({
  name,
  onNameChange,
  onPick,
}: {
  name: string;
  onNameChange: (v: string) => void;
  onPick: (t: Template) => void;
}) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3">
        {TEMPLATES.map((t) => (
          <button
            key={t.id}
            type="button"
            onClick={() => onPick(t)}
            className="flex flex-col items-start gap-2 rounded-xl border border-outline-variant bg-surface-container-low p-4 text-left hover:border-primary hover:bg-primary-container/20"
          >
            <Icon name={t.icon} className="text-2xl text-primary" filled />
            <span className="text-label-md font-semibold text-on-surface">{t.label}</span>
            {t.defaultName && (
              <span className="text-label-sm text-on-surface-variant">{t.defaultName}</span>
            )}
          </button>
        ))}
      </div>

      <Field label="Nome *" htmlFor="sz-name">
        <input
          id="sz-name"
          type="text"
          required
          value={name}
          onChange={(e) => onNameChange(e.target.value)}
          className={inputClass}
          placeholder="Ex.: Casa"
          autoFocus
        />
      </Field>
    </div>
  );
}

function StepLocation({
  address,
  onAddressChange,
  lat,
  lng,
  onPick,
}: {
  address: string;
  onAddressChange: (v: string) => void;
  lat: number;
  lng: number;
  onPick: (lat: number, lng: number) => void;
}) {
  return (
    <div className="space-y-4">
      <Field label="Endereço (opcional)" htmlFor="sz-address">
        <input
          id="sz-address"
          type="text"
          value={address}
          onChange={(e) => onAddressChange(e.target.value)}
          className={inputClass}
          placeholder="Rua X, 123"
        />
      </Field>

      <div>
        <span className="mb-1 block text-label-sm font-semibold text-on-surface-variant">
          Localização (clique no mapa para mover o marcador)
        </span>
        <div
          className="overflow-hidden rounded-lg border border-outline-variant"
          style={{ height: 220 }}
        >
          <MapContainer
            center={[lat, lng]}
            zoom={15}
            scrollWheelZoom
            style={{ height: '100%', width: '100%' }}
          >
            <TileLayer
              attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
              url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
            />
            <Marker position={[lat, lng]} />
            <MapClickHandler onPick={onPick} />
          </MapContainer>
        </div>
        <p className="mt-1 text-label-sm text-on-surface-variant">
          Lat {lat.toFixed(5)} · Lng {lng.toFixed(5)}
        </p>
      </div>
    </div>
  );
}

function StepRadius({ radius, onChange }: { radius: number; onChange: (n: number) => void }) {
  return (
    <div className="space-y-4">
      <p className="text-label-md text-on-surface-variant">
        Define o tamanho da área desenhada em volta do ponto no mapa.
      </p>
      <div className="grid grid-cols-2 gap-2">
        {RADIUS_OPTIONS.map((r) => (
          <button
            key={r}
            type="button"
            onClick={() => onChange(r)}
            aria-pressed={radius === r}
            className={
              radius === r
                ? 'rounded-xl bg-primary py-3 text-label-md font-bold text-white shadow-sm'
                : 'rounded-xl border border-outline-variant bg-surface-container-low py-3 text-label-md font-semibold text-on-surface hover:bg-surface-variant'
            }
          >
            {r >= 1000 ? `${r / 1000}km` : `${r}m`}
          </button>
        ))}
      </div>
    </div>
  );
}

function StepReview({
  name,
  address,
  lat,
  lng,
  radius,
}: {
  name: string;
  address: string;
  lat: number;
  lng: number;
  radius: number;
}) {
  return (
    <div className="space-y-3 rounded-xl border border-outline-variant bg-surface-container-low p-4">
      <ReviewRow icon="label" label="Nome" value={name || '—'} />
      <ReviewRow icon="place" label="Endereço" value={address.trim() || 'Sem endereço'} />
      <ReviewRow
        icon="map"
        label="Coordenadas"
        value={`${lat.toFixed(5)}, ${lng.toFixed(5)}`}
      />
      <ReviewRow
        icon="radio_button_checked"
        label="Raio"
        value={radius >= 1000 ? `${radius / 1000}km` : `${radius}m`}
      />
    </div>
  );
}

function ReviewRow({ icon, label, value }: { icon: string; label: string; value: string }) {
  return (
    <div className="flex items-center gap-3">
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-container/40 text-primary">
        <Icon name={icon} className="text-lg" filled />
      </div>
      <div className="min-w-0 flex-1">
        <div className="truncate text-label-sm text-on-surface-variant">{label}</div>
        <div className="truncate text-label-md font-semibold text-on-surface">{value}</div>
      </div>
    </div>
  );
}

function MapClickHandler({ onPick }: { onPick: (lat: number, lng: number) => void }) {
  useMapEvents({
    click(e) {
      onPick(e.latlng.lat, e.latlng.lng);
    },
  });
  return null;
}

function Field({
  label,
  htmlFor,
  children,
}: {
  label: string;
  htmlFor: string;
  children: ReactNode;
}) {
  return (
    <label htmlFor={htmlFor} className="block">
      <span className="mb-1 block text-label-sm font-semibold text-on-surface-variant">
        {label}
      </span>
      {children}
    </label>
  );
}
