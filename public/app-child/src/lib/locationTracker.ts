import { postLocation, type LocationFixPayload } from '../api/location';

type Sender = (fix: LocationFixPayload) => Promise<unknown>;

export type GeoLike = {
  watchPosition: (
    success: (position: { coords: { latitude: number; longitude: number; accuracy: number } }) => void,
    error?: (err: { code: number; message: string }) => void,
    options?: PositionOptions,
  ) => number;
  clearWatch: (id: number) => void;
};

export type BatterySource = () => number | null;

export type LocationTrackerDeps = {
  sender?: Sender;
  geo?: GeoLike;
  doc?: Document;
  now?: () => number;
  minIntervalMs?: number;
  minDistanceM?: number;
  battery?: BatterySource;
};

export type LocationTracker = {
  start: () => void;
  stop: () => void;
};

const EARTH_RADIUS_M = 6371000;

function haversine(a: { lat: number; lng: number }, b: { lat: number; lng: number }): number {
  const toRad = (deg: number) => (deg * Math.PI) / 180;
  const dLat = toRad(b.lat - a.lat);
  const dLng = toRad(b.lng - a.lng);
  const sinDLat = Math.sin(dLat / 2);
  const sinDLng = Math.sin(dLng / 2);
  const h =
    sinDLat * sinDLat +
    Math.cos(toRad(a.lat)) * Math.cos(toRad(b.lat)) * sinDLng * sinDLng;
  return 2 * EARTH_RADIUS_M * Math.asin(Math.sqrt(h));
}

export function createLocationTracker(token: string, deps: LocationTrackerDeps = {}): LocationTracker {
  const sender: Sender = deps.sender ?? ((fix) => postLocation(token, fix));
  const geo: GeoLike | null =
    deps.geo ?? (typeof navigator !== 'undefined' ? navigator.geolocation : null);
  const doc: Document = deps.doc ?? document;
  const now = deps.now ?? (() => Date.now());
  const minIntervalMs = deps.minIntervalMs ?? 60_000;
  const minDistanceM = deps.minDistanceM ?? 50;
  const battery = deps.battery ?? (() => null);

  let watchId: number | null = null;
  let lastSentAt = 0;
  let lastFix: { lat: number; lng: number } | null = null;

  function shouldSend(lat: number, lng: number): boolean {
    if (now() - lastSentAt >= minIntervalMs) return true;
    if (lastFix === null) return true;
    return haversine(lastFix, { lat, lng }) >= minDistanceM;
  }

  function onPosition(pos: { coords: { latitude: number; longitude: number; accuracy: number } }): void {
    const { latitude, longitude, accuracy } = pos.coords;
    if (!shouldSend(latitude, longitude)) return;

    lastSentAt = now();
    lastFix = { lat: latitude, lng: longitude };

    const payload: LocationFixPayload = { latitude, longitude };
    if (Number.isFinite(accuracy)) payload.accuracy = Math.round(accuracy);
    const batteryPct = battery();
    if (batteryPct !== null && Number.isFinite(batteryPct)) payload.battery = batteryPct;

    sender(payload).catch(() => {
      /* silent — 403 (setting off), 401, ou rede; UI lida com isso separadamente */
    });
  }

  function onError(): void {
    /* silent: usuário pode ter negado permissão, GPS indisponível, etc. */
  }

  function isVisible(): boolean {
    return doc.visibilityState === 'visible';
  }

  function startWatch(): void {
    if (geo === null || watchId !== null) return;
    watchId = geo.watchPosition(onPosition, onError, {
      enableHighAccuracy: true,
      maximumAge: 30_000,
    });
  }

  function stopWatch(): void {
    if (geo === null || watchId === null) return;
    geo.clearWatch(watchId);
    watchId = null;
  }

  function onVisibilityChange(): void {
    if (isVisible()) startWatch();
    else stopWatch();
  }

  function start(): void {
    if (isVisible()) startWatch();
    doc.addEventListener('visibilitychange', onVisibilityChange);
  }

  function stop(): void {
    doc.removeEventListener('visibilitychange', onVisibilityChange);
    stopWatch();
    lastSentAt = 0;
    lastFix = null;
  }

  return { start, stop };
}
