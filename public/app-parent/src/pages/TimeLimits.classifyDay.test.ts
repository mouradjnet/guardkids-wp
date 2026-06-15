import { describe, expect, it } from 'vitest';
import type { Child } from '../api/types';
import type { UsageHourly } from '../api/reports';
import { classifyDay } from './TimeLimits';

const baseChild: Child = {
  id: 1,
  slug: 'lucas',
  name: 'Lucas',
  age: 9,
  avatarUrl: null,
  device: null,
  status: 'online',
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

function emptyHours(): UsageHourly {
  return {
    date: '2026-06-14',
    hours: Array.from({ length: 24 }, (_, h) => ({ hour: h, minutes: 0 })),
  };
}

describe('classifyDay', () => {
  it('returns single free block when nothing happens and bedtime off', () => {
    const blocks = classifyDay(baseChild, emptyHours());
    expect(blocks).toEqual([{ start: 0, end: 24, kind: 'free', label: 'Livre' }]);
  });

  it('marks bedtime range from same-day bedtime (21:00 → 23:00)', () => {
    const child = {
      ...baseChild,
      bedtimeEnabled: true,
      bedtimeStart: '21:00',
      bedtimeEnd: '23:00',
    };
    const blocks = classifyDay(child, emptyHours());
    expect(blocks).toEqual([
      { start: 0, end: 21, kind: 'free', label: 'Livre' },
      { start: 21, end: 23, kind: 'bedtime', label: 'Bloqueado' },
      { start: 23, end: 24, kind: 'free', label: 'Livre' },
    ]);
  });

  it('marks cross-midnight bedtime (21:00 → 07:00) as two segments', () => {
    const child = {
      ...baseChild,
      bedtimeEnabled: true,
      bedtimeStart: '21:00',
      bedtimeEnd: '07:00',
    };
    const blocks = classifyDay(child, emptyHours());
    expect(blocks).toEqual([
      { start: 0, end: 7, kind: 'bedtime', label: 'Bloqueado' },
      { start: 7, end: 21, kind: 'free', label: 'Livre' },
      { start: 21, end: 24, kind: 'bedtime', label: 'Bloqueado' },
    ]);
  });

  it('marks hours with minutes>0 as used', () => {
    const data = emptyHours();
    data.hours[14] = { hour: 14, minutes: 30 };
    data.hours[15] = { hour: 15, minutes: 15 };
    const blocks = classifyDay(baseChild, data);
    expect(blocks).toEqual([
      { start: 0, end: 14, kind: 'free', label: 'Livre' },
      { start: 14, end: 16, kind: 'used', label: 'Em uso' },
      { start: 16, end: 24, kind: 'free', label: 'Livre' },
    ]);
  });

  it('bedtime overrides used minutes (kid sneaked past bedtime)', () => {
    const child = {
      ...baseChild,
      bedtimeEnabled: true,
      bedtimeStart: '22:00',
      bedtimeEnd: '23:00',
    };
    const data = emptyHours();
    data.hours[22] = { hour: 22, minutes: 30 };
    const blocks = classifyDay(child, data);
    const at22 = blocks.find((b) => b.start <= 22 && 22 < b.end);
    expect(at22?.kind).toBe('bedtime');
  });

  it('flags whole day as bedtime when today is blocked weekday', () => {
    // weekdays string is Mon..Sun; pick today's idx and set to 'N'
    const todayIdx = (new Date().getDay() + 6) % 7;
    const allowed = 'YYYYYYY'.split('');
    allowed[todayIdx] = 'N';
    const child = { ...baseChild, allowedWeekdays: allowed.join('') };

    const data = emptyHours();
    data.hours[10] = { hour: 10, minutes: 20 };

    const blocks = classifyDay(child, data);
    expect(blocks).toEqual([{ start: 0, end: 24, kind: 'bedtime', label: 'Bloqueado' }]);
  });
});
