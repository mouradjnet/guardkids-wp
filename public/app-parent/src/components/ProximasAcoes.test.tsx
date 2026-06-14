import { describe, expect, it } from 'vitest';
import { buildActions } from './ProximasAcoes';

const lucas = { id: 1, name: 'Lucas', status: 'online', usedMinutes: 30, limitMinutes: 60 };

describe('buildActions', () => {
  it('returns single action when no children registered (high priority)', () => {
    const actions = buildActions([], [], {});
    expect(actions).toHaveLength(1);
    expect(actions[0].id).toBe('no-children');
    expect(actions[0].priority).toBe('high');
  });

  it('returns location-off high priority when children exist but location_enabled is false', () => {
    const actions = buildActions([lucas], [], { location_enabled: false });
    expect(actions[0].id).toBe('location-off');
    expect(actions[0].priority).toBe('high');
  });

  it('does not flag location-off when location_enabled is true', () => {
    const actions = buildActions([lucas], [], { location_enabled: true });
    expect(actions.find((a) => a.id === 'location-off')).toBeUndefined();
  });

  it('flags pending requests with medium priority', () => {
    const actions = buildActions(
      [lucas],
      [{ childId: 1 }, { childId: 1 }],
      { location_enabled: true },
    );
    const pending = actions.find((a) => a.id === 'pending-requests');
    expect(pending).toBeDefined();
    expect(pending?.title).toContain('2 pedidos pendentes');
  });

  it('flags near-limit when usage >= 80% of limit', () => {
    const heavy = { ...lucas, usedMinutes: 50, limitMinutes: 60 };
    const actions = buildActions([heavy], [], { location_enabled: true });
    const near = actions.find((a) => a.id === 'near-limit');
    expect(near).toBeDefined();
    expect(near?.priority).toBe('medium');
    expect(near?.title).toContain('Lucas');
  });

  it('returns all-ok green action when nothing to do', () => {
    const actions = buildActions([lucas], [], { location_enabled: true });
    expect(actions).toHaveLength(1);
    expect(actions[0].id).toBe('all-ok');
    expect(actions[0].priority).toBe('ok');
  });

  it('sorts actions by priority high -> medium -> low -> ok', () => {
    const actions = buildActions(
      [{ ...lucas, usedMinutes: 50 }],
      [{ childId: 1 }],
      { location_enabled: false },
    );
    expect(actions[0].priority).toBe('high');
    expect(actions[actions.length - 1].priority).not.toBe('high');
  });
});
