import { apiFetch, apiFetchWithToken } from './client';
import type { Child, CreateRequestInput, MyRequest } from './types';

export function getMe(): Promise<Child> {
  return apiFetch<Child>('/child/me');
}

/** Valida um token avulso (sem persistir em storage) chamando /child/me. */
export function validateToken(token: string): Promise<Child> {
  return apiFetchWithToken<Child>(token, '/child/me');
}

export function listMyRequests(): Promise<MyRequest[]> {
  return apiFetch<MyRequest[]>('/child/requests');
}

export function createRequest(input: CreateRequestInput): Promise<MyRequest> {
  return apiFetch<MyRequest>('/child/requests', {
    method: 'POST',
    body: JSON.stringify(input),
  });
}

/** Confere o PIN dos pais pra destravar o ambiente seguro neste aparelho. */
export function verifyPin(pin: string): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/child/security/pin/verify', {
    method: 'POST',
    body: JSON.stringify({ pin }),
  });
}

export type BlockDetail = 'bedtime' | 'weekday' | 'limit';

export function reportScheduleBlock(detail: BlockDetail): Promise<unknown> {
  return apiFetch('/child/events', {
    method: 'POST',
    body: JSON.stringify({ type: 'schedule_block', detail, duration_seconds: 0 }),
  });
}
