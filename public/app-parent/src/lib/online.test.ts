import { describe, expect, it } from 'vitest';
import { isChildOnline, ONLINE_THRESHOLD_MS } from './online';

const NOW = Date.parse('2026-07-20T17:00:00Z');
const iso = (msAgo: number) => new Date(NOW - msAgo).toISOString();

describe('isChildOnline', () => {
  it('is online when heartbeat is within the threshold', () => {
    expect(isChildOnline({ lastSeenAt: iso(2 * 60_000) }, NOW)).toBe(true);
  });

  it('is offline when heartbeat is older than the threshold', () => {
    expect(isChildOnline({ lastSeenAt: iso(ONLINE_THRESHOLD_MS + 1_000) }, NOW)).toBe(false);
  });

  it('is offline when lastSeenAt is null or missing', () => {
    expect(isChildOnline({ lastSeenAt: null }, NOW)).toBe(false);
    expect(isChildOnline({}, NOW)).toBe(false);
  });

  it('is offline when lastSeenAt is unparseable', () => {
    expect(isChildOnline({ lastSeenAt: 'not-a-date' }, NOW)).toBe(false);
  });

  it('treats exactly-at-threshold as offline (strict <)', () => {
    expect(isChildOnline({ lastSeenAt: iso(ONLINE_THRESHOLD_MS) }, NOW)).toBe(false);
  });
});
