import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createLocationTracker, type GeoLike } from './locationTracker';

function makeDoc(initialVisible = true) {
  let visibility: DocumentVisibilityState = initialVisible ? 'visible' : 'hidden';
  const listeners: Record<string, Array<() => void>> = {};
  return {
    get visibilityState() {
      return visibility;
    },
    setVisibility(v: DocumentVisibilityState) {
      visibility = v;
      (listeners['visibilitychange'] || []).forEach((fn) => fn());
    },
    addEventListener(type: string, fn: () => void) {
      listeners[type] = listeners[type] || [];
      listeners[type].push(fn);
    },
    removeEventListener(type: string, fn: () => void) {
      const list = listeners[type] || [];
      const i = list.indexOf(fn);
      if (i >= 0) list.splice(i, 1);
    },
  } as unknown as Document & { setVisibility: (v: DocumentVisibilityState) => void };
}

function makeGeo() {
  let nextId = 1;
  const watchers: Map<number, (pos: { coords: { latitude: number; longitude: number; accuracy: number } }) => void> = new Map();
  const clearCalls: number[] = [];

  const geo: GeoLike = {
    watchPosition(success) {
      const id = nextId++;
      watchers.set(id, success);
      return id;
    },
    clearWatch(id) {
      clearCalls.push(id);
      watchers.delete(id);
    },
  };

  return {
    geo,
    fireFix(lat: number, lng: number, accuracy = 10) {
      watchers.forEach((fn) =>
        fn({ coords: { latitude: lat, longitude: lng, accuracy } }),
      );
    },
    watcherCount: () => watchers.size,
    clearCalls,
  };
}

describe('locationTracker', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  it('envia primeira posição imediatamente após start', () => {
    let mockTime = 0;
    const sender = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const { geo, fireFix } = makeGeo();
    const tracker = createLocationTracker('tok', {
      sender,
      geo,
      doc,
      now: () => mockTime,
    });
    tracker.start();

    fireFix(-8.0476, -34.877);
    expect(sender).toHaveBeenCalledTimes(1);
    expect(sender.mock.calls[0][0]).toMatchObject({
      latitude: -8.0476,
      longitude: -34.877,
      accuracy: 10,
    });

    tracker.stop();
  });

  it('throttle: 2a posição idêntica antes de 60s não envia', () => {
    let mockTime = 0;
    const sender = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const { geo, fireFix } = makeGeo();
    const tracker = createLocationTracker('tok', {
      sender,
      geo,
      doc,
      now: () => mockTime,
    });
    tracker.start();

    fireFix(-8.0476, -34.877);
    mockTime = 30_000;
    fireFix(-8.0476, -34.877);

    expect(sender).toHaveBeenCalledTimes(1);
    tracker.stop();
  });

  it('throttle: 2a posição idêntica após 60s envia novamente', () => {
    let mockTime = 0;
    const sender = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const { geo, fireFix } = makeGeo();
    const tracker = createLocationTracker('tok', {
      sender,
      geo,
      doc,
      now: () => mockTime,
    });
    tracker.start();

    fireFix(-8.0476, -34.877);
    mockTime = 60_001;
    fireFix(-8.0476, -34.877);

    expect(sender).toHaveBeenCalledTimes(2);
    tracker.stop();
  });

  it('throttle por distância: deslocamento > 50m antes de 60s envia', () => {
    let mockTime = 0;
    const sender = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const { geo, fireFix } = makeGeo();
    const tracker = createLocationTracker('tok', {
      sender,
      geo,
      doc,
      now: () => mockTime,
    });
    tracker.start();

    fireFix(-8.0476, -34.877);
    mockTime = 5_000;
    // ~111m ao norte
    fireFix(-8.0466, -34.877);

    expect(sender).toHaveBeenCalledTimes(2);
    tracker.stop();
  });

  it('pausa watch quando document fica hidden', () => {
    const sender = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const { geo, watcherCount, clearCalls } = makeGeo();
    const tracker = createLocationTracker('tok', { sender, geo, doc, now: () => Date.now() });
    tracker.start();

    expect(watcherCount()).toBe(1);
    doc.setVisibility('hidden');
    expect(watcherCount()).toBe(0);
    expect(clearCalls).toHaveLength(1);

    tracker.stop();
  });

  it('retoma watch quando volta visible', () => {
    const sender = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const { geo, watcherCount } = makeGeo();
    const tracker = createLocationTracker('tok', { sender, geo, doc, now: () => Date.now() });
    tracker.start();

    doc.setVisibility('hidden');
    doc.setVisibility('visible');
    expect(watcherCount()).toBe(1);

    tracker.stop();
  });

  it('stop limpa watch e listener', () => {
    const sender = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const { geo, watcherCount, clearCalls } = makeGeo();
    const tracker = createLocationTracker('tok', { sender, geo, doc, now: () => Date.now() });
    tracker.start();
    tracker.stop();

    expect(watcherCount()).toBe(0);
    expect(clearCalls).toHaveLength(1);
  });

  it('inclui battery quando função battery() retorna número', () => {
    const sender = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const { geo, fireFix } = makeGeo();
    const tracker = createLocationTracker('tok', {
      sender,
      geo,
      doc,
      now: () => Date.now(),
      battery: () => 73,
    });
    tracker.start();

    fireFix(0, 0);
    expect(sender.mock.calls[0][0].battery).toBe(73);

    tracker.stop();
  });

  it('omite battery quando battery() retorna null', () => {
    const sender = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const { geo, fireFix } = makeGeo();
    const tracker = createLocationTracker('tok', {
      sender,
      geo,
      doc,
      now: () => Date.now(),
      battery: () => null,
    });
    tracker.start();

    fireFix(0, 0);
    expect(sender.mock.calls[0][0]).not.toHaveProperty('battery');

    tracker.stop();
  });

  it('silent fail no sender rejection', async () => {
    const sender = vi.fn().mockRejectedValue(new Error('403'));
    const doc = makeDoc(true);
    const { geo, fireFix } = makeGeo();
    const tracker = createLocationTracker('tok', {
      sender,
      geo,
      doc,
      now: () => Date.now(),
    });
    tracker.start();

    fireFix(0, 0);
    await Promise.resolve();

    expect(sender).toHaveBeenCalled();
    tracker.stop();
  });
});
