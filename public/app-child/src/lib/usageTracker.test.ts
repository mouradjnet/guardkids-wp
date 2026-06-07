import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createUsageTracker } from './usageTracker';

function makeDoc(initialVisible = true) {
  let visibility: DocumentVisibilityState = initialVisible ? 'visible' : 'hidden';
  const listeners: Record<string, Array<() => void>> = {};
  return {
    get visibilityState() { return visibility; },
    setVisibility(v: DocumentVisibilityState) {
      visibility = v;
      (listeners['visibilitychange'] || []).forEach(fn => fn());
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

describe('usageTracker', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  it('envia heartbeat após 60s visible', async () => {
    const fetcher = vi.fn().mockResolvedValue({ id: 1, createdAt: '2026-06-06T00:00:00' });
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    vi.advanceTimersByTime(60_000);
    await Promise.resolve();

    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(fetcher.mock.calls[0][0]).toBe('/child/events');
    const body = JSON.parse((fetcher.mock.calls[0][1] as RequestInit).body as string);
    expect(body.type).toBe('heartbeat');
    expect(body.duration_seconds).toBeGreaterThanOrEqual(55);
    expect(body.duration_seconds).toBeLessThanOrEqual(60);

    tracker.stop();
  });

  it('não envia se < 5s acumulados (threshold)', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    vi.advanceTimersByTime(4_000);
    doc.setVisibility('hidden');
    await Promise.resolve();

    expect(fetcher).not.toHaveBeenCalled();
    tracker.stop();
  });

  it('limita duration_seconds em 90s mesmo após sleep simulado', async () => {
    let mockTime = 0;
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => mockTime });
    tracker.start();

    mockTime = 3_600_000;
    vi.advanceTimersByTime(60_000);

    await Promise.resolve();
    expect(fetcher).toHaveBeenCalledTimes(1);
    const body = JSON.parse((fetcher.mock.calls[0][1] as RequestInit).body as string);
    expect(body.duration_seconds).toBe(90);

    tracker.stop();
  });

  it('pausa heartbeats em hidden', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    doc.setVisibility('hidden');
    fetcher.mockClear();
    vi.advanceTimersByTime(120_000);

    expect(fetcher).not.toHaveBeenCalled();
    tracker.stop();
  });

  it('retoma após voltar a visible', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    doc.setVisibility('hidden');
    fetcher.mockClear();
    doc.setVisibility('visible');
    vi.advanceTimersByTime(60_000);
    await Promise.resolve();

    expect(fetcher).toHaveBeenCalledTimes(1);
    tracker.stop();
  });

  it('silent fail no fetcher rejection', async () => {
    const fetcher = vi.fn().mockRejectedValue(new Error('offline'));
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    vi.advanceTimersByTime(60_000);
    await Promise.resolve();
    await Promise.resolve();

    expect(fetcher).toHaveBeenCalled();
    tracker.stop();
  });

  it('trackSiteOpen dispara POST com type=site_open', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();

    tracker.trackSiteOpen('youtube.com');
    await Promise.resolve();

    expect(fetcher).toHaveBeenCalledTimes(1);
    const body = JSON.parse((fetcher.mock.calls[0][1] as RequestInit).body as string);
    expect(body).toEqual({ type: 'site_open', domain: 'youtube.com', duration_seconds: 0 });

    tracker.stop();
  });

  it('stop limpa interval', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();
    tracker.stop();
    vi.advanceTimersByTime(120_000);
    await Promise.resolve();
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('flushSync no beforeunload usa keepalive', async () => {
    const fetcher = vi.fn().mockResolvedValue({});
    const doc = makeDoc(true);
    const tracker = createUsageTracker({ fetcher, doc, now: () => Date.now() });
    tracker.start();
    vi.advanceTimersByTime(10_000);
    tracker.flushSync();
    await Promise.resolve();

    expect(fetcher).toHaveBeenCalledTimes(1);
    const init = fetcher.mock.calls[0][1] as RequestInit & { keepalive?: boolean };
    expect(init.keepalive).toBe(true);
    tracker.stop();
  });
});
