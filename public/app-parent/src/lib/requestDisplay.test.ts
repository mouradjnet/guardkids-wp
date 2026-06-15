import { describe, expect, it } from 'vitest';
import type { ApprovalRequest, Child } from '../api/types';
import { accentFor, childBadge, formatRelative } from './requestDisplay';

const baseRequest: ApprovalRequest = {
  id: 1,
  childId: 5,
  kind: 'extra_time',
  description: null,
  highlight: null,
  reason: null,
  status: 'pending',
  decidedAt: null,
  decidedBy: null,
  createdAt: null,
};

const baseChild: Child = {
  id: 5,
  slug: 'lucas',
  name: 'Lucas',
  age: 9,
  avatarUrl: 'https://example.com/lucas.jpg',
  device: null,
  status: 'offline',
  usedMinutes: 0,
  limitMinutes: 60,
  dailyLimitEnabled: false,
  bedtimeEnabled: false,
  bedtimeStart: null,
  bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
  createdAt: null,
  updatedAt: null,
};

describe('accentFor', () => {
  it('returns tertiary for unblock_site and site', () => {
    expect(accentFor('unblock_site')).toBe('tertiary');
    expect(accentFor('site')).toBe('tertiary');
  });

  it('returns primary for extra_time and unknown kinds', () => {
    expect(accentFor('extra_time')).toBe('primary');
    expect(accentFor('anything_else')).toBe('primary');
  });
});

describe('formatRelative', () => {
  it('returns empty string for null', () => {
    expect(formatRelative(null)).toBe('');
  });

  it('returns empty string for invalid date', () => {
    expect(formatRelative('not-a-date')).toBe('');
  });

  it('returns "agora" within a minute', () => {
    const now = new Date().toISOString();
    expect(formatRelative(now)).toBe('agora');
  });

  it('returns "há N min" for minutes', () => {
    const five = new Date(Date.now() - 5 * 60_000).toISOString();
    expect(formatRelative(five)).toBe('há 5 min');
  });

  it('returns "há Nh" for hours', () => {
    const threeH = new Date(Date.now() - 3 * 60 * 60_000).toISOString();
    expect(formatRelative(threeH)).toBe('há 3h');
  });

  it('returns "há Nd" for days under a week', () => {
    const twoD = new Date(Date.now() - 2 * 24 * 60 * 60_000).toISOString();
    expect(formatRelative(twoD)).toBe('há 2d');
  });

  it('falls back to locale date for old dates', () => {
    const old = new Date(Date.now() - 30 * 24 * 60 * 60_000).toISOString();
    // não amarra ao formato locale exato, só garante que não está "há X"
    expect(formatRelative(old)).not.toMatch(/^há/);
    expect(formatRelative(old)).not.toBe('');
  });
});

describe('childBadge', () => {
  it('returns name + avatarUrl from matching child', () => {
    const badge = childBadge(baseRequest, [baseChild]);
    expect(badge).toEqual({ name: 'Lucas', avatarUrl: 'https://example.com/lucas.jpg' });
  });

  it('falls back when child list is undefined', () => {
    const badge = childBadge(baseRequest, undefined);
    expect(badge).toEqual({ name: 'Filho #5', avatarUrl: null });
  });

  it('falls back when no match', () => {
    const badge = childBadge({ ...baseRequest, childId: 999 }, [baseChild]);
    expect(badge).toEqual({ name: 'Filho #999', avatarUrl: null });
  });
});
