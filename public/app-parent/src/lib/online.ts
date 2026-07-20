import type { Child } from '../api/types';

/**
 * Janela em que um heartbeat recente conta como "online". O app-child manda
 * heartbeat a cada ~60s enquanto visível, então 5 min tolera algumas perdas
 * sem piscar. Também usada pra recência do último fix de localização.
 */
export const ONLINE_THRESHOLD_MS = 5 * 60 * 1000;

/**
 * Online = o filho mandou heartbeat dentro da janela. Deriva de `lastSeenAt`
 * (o campo `status` do backend nunca vale 'online' — ver lib/online). `paused`
 * é ortogonal: quem precisar de pausa checa `status === 'paused'` à parte.
 */
export function isChildOnline(
  child: Pick<Child, 'lastSeenAt'>,
  now: number = Date.now(),
): boolean {
  if (!child.lastSeenAt) return false;
  const seen = Date.parse(child.lastSeenAt);
  if (Number.isNaN(seen)) return false;
  return now - seen < ONLINE_THRESHOLD_MS;
}
