import { apiFetchWithToken } from './client';

export type LocationFixPayload = {
  latitude: number;
  longitude: number;
  accuracy?: number;
  battery?: number;
};

export type LocationFixAck = {
  id: number;
  recordedAt: string;
};

export function postLocation(token: string, fix: LocationFixPayload): Promise<LocationFixAck> {
  return apiFetchWithToken<LocationFixAck>(token, '/child/location', {
    method: 'POST',
    body: JSON.stringify(fix),
  });
}
