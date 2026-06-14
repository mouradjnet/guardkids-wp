import { describe, expect, it } from 'vitest';
import { mergeEvents } from './EventsTimeline';
import type { RecentBlock } from '../api/reports';
import type { ApprovalRequest } from '../api/types';

const child = { id: 1, name: 'Lucas' };

function block(id: number, createdAt: string): RecentBlock {
  return { id, childId: 1, childName: 'Lucas', detail: 'bedtime', createdAt };
}

function req(id: number, status: 'approved' | 'denied' | 'pending', decidedAt: string | null, createdAt: string): ApprovalRequest {
  return {
    id,
    childId: 1,
    kind: 'extra_time',
    description: 'Mais tempo',
    highlight: '+30',
    reason: null,
    status,
    decidedAt,
    decidedBy: 1,
    createdAt,
  };
}

describe('mergeEvents', () => {
  it('returns empty when no blocks and no requests', () => {
    expect(mergeEvents([], [], [child])).toEqual([]);
  });

  it('ignores pending requests (those live in PendingRequests panel)', () => {
    const events = mergeEvents(
      [],
      [req(10, 'pending', null, '2026-06-14T10:00:00Z')],
      [child],
    );
    expect(events).toEqual([]);
  });

  it('sorts events by createdAt DESC across kinds', () => {
    const events = mergeEvents(
      [block(1, '2026-06-14T08:00:00Z'), block(2, '2026-06-14T12:00:00Z')],
      [req(10, 'approved', '2026-06-14T10:00:00Z', '2026-06-14T09:00:00Z')],
      [child],
    );
    expect(events.map((e) => e.id)).toEqual(['block-2', 'req-10', 'block-1']);
  });

  it('uses decidedAt for request createdAt when available', () => {
    const events = mergeEvents(
      [],
      [req(99, 'denied', '2026-06-14T15:00:00Z', '2026-06-14T08:00:00Z')],
      [child],
    );
    expect(events[0]?.createdAt).toBe('2026-06-14T15:00:00Z');
  });

  it('resolves childName via children list when block has empty name', () => {
    const events = mergeEvents(
      [{ ...block(1, '2026-06-14T08:00:00Z'), childName: '' }],
      [],
      [child],
    );
    expect(events[0]?.childName).toBe('Lucas');
  });

  it('respects limit param', () => {
    const blocks = Array.from({ length: 15 }, (_, i) =>
      block(i + 1, `2026-06-${String(14 - (i % 14)).padStart(2, '0')}T08:00:00Z`),
    );
    expect(mergeEvents(blocks, [], [child], 5)).toHaveLength(5);
  });
});
