import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
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

const inputClass =
  'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-base text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none';

export function SafeZoneDialog({ open, mode, initial, onClose }: Props) {
  const queryClient = useQueryClient();
  const nameRef = useRef<HTMLInputElement>(null);

  const [name, setName] = useState('');
  const [address, setAddress] = useState('');
  const [lat, setLat] = useState(DEFAULT_LAT);
  const [lng, setLng] = useState(DEFAULT_LNG);
  const [radius, setRadius] = useState(100);

  useEffect(() => {
    if (!open) return;
    if (mode === 'edit' && initial) {
      setName(initial.name);
      setAddress(initial.address ?? '');
      setLat(initial.latitude);
      setLng(initial.longitude);
      setRadius(initial.radiusMeters);
    } else {
      setName('');
      setAddress('');
      setLat(DEFAULT_LAT);
      setLng(DEFAULT_LNG);
      setRadius(100);
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
    const t = window.setTimeout(() => nameRef.current?.focus(), 50);
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => {
      window.clearTimeout(t);
      window.removeEventListener('keydown', onKey);
    };
  }, [open, onClose]);

  if (!open) return null;

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
          <h2 id="safe-zone-title" className="font-display text-headline-md text-on-surface">
            {mode === 'edit' ? 'Editar zona' : 'Nova zona segura'}
          </h2>
          <button
            type="button"
            aria-label="Fechar"
            onClick={onClose}
            className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50 hover:text-primary"
          >
            <Icon name="close" />
          </button>
        </div>

        <div className="mt-5 space-y-4">
          <Field label="Nome *" htmlFor="sz-name">
            <input
              ref={nameRef}
              id="sz-name"
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              className={inputClass}
              placeholder="Ex.: Casa"
            />
          </Field>

          <Field label="Endereço (opcional)" htmlFor="sz-address">
            <input
              id="sz-address"
              type="text"
              value={address}
              onChange={(e) => setAddress(e.target.value)}
              className={inputClass}
              placeholder="Rua X, 123"
            />
          </Field>

          <div>
            <span className="mb-1 block text-label-sm font-semibold text-on-surface-variant">
              Localização (clique no mapa para mover o marker)
            </span>
            <div className="overflow-hidden rounded-lg border border-outline-variant" style={{ height: 240 }}>
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
                <MapClickHandler onPick={(la, ln) => { setLat(la); setLng(ln); }} />
              </MapContainer>
            </div>
            <p className="mt-1 text-label-sm text-on-surface-variant">
              Lat {lat.toFixed(5)} · Lng {lng.toFixed(5)}
            </p>
          </div>

          <Field label={`Raio: ${radius}m`} htmlFor="sz-radius">
            <input
              id="sz-radius"
              type="range"
              min={50}
              max={500}
              step={10}
              value={radius}
              onChange={(e) => setRadius(Number(e.target.value))}
              className="w-full accent-primary"
            />
          </Field>
        </div>

        {errorMessage && (
          <p role="alert" className="mt-4 rounded-lg bg-error/10 p-3 text-label-sm text-error">
            {errorMessage}
          </p>
        )}

        <div className="mt-6 flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={mutation.isPending}
            className="rounded-lg border border-outline-variant bg-surface-container px-4 py-2 text-label-md font-semibold text-on-surface hover:bg-surface-variant disabled:opacity-50"
          >
            Cancelar
          </button>
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
              'Salvar'
            )}
          </button>
        </div>
      </form>
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

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: ReactNode }) {
  return (
    <label htmlFor={htmlFor} className="block">
      <span className="mb-1 block text-label-sm font-semibold text-on-surface-variant">{label}</span>
      {children}
    </label>
  );
}
