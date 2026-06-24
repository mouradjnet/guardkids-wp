import { apiFetch } from './client';

export type PinStatus = { pinSet: boolean };

export function getPinStatus(): Promise<PinStatus> {
  return apiFetch<PinStatus>('/security/pin');
}

export function setPin(pin: string): Promise<PinStatus> {
  return apiFetch<PinStatus>('/security/pin', {
    method: 'POST',
    body: JSON.stringify({ pin }),
  });
}

export function clearPin(): Promise<PinStatus> {
  return apiFetch<PinStatus>('/security/pin', { method: 'DELETE' });
}
