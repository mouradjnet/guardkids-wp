import { apiFetch } from '../api/client';

type Fetcher = (path: string, init?: RequestInit) => Promise<unknown>;

export type UsageTrackerDeps = {
  fetcher?: Fetcher;
  doc?: Document;
  now?: () => number;
  intervalMs?: number;
  minDurationSec?: number;
  capDurationSec?: number;
};

export type UsageTracker = {
  start: () => void;
  stop: () => void;
  trackSiteOpen: (domain: string) => void;
  flushSync: () => void;
};

let activeTracker: UsageTracker | null = null;

export function setActiveTracker(tracker: UsageTracker | null): void {
  activeTracker = tracker;
}

export function getActiveTracker(): UsageTracker | null {
  return activeTracker;
}

export function createUsageTracker(deps: UsageTrackerDeps = {}): UsageTracker {
  const fetcher: Fetcher = deps.fetcher ?? ((path, init) => apiFetch(path, init));
  const doc: Document = deps.doc ?? document;
  const now = deps.now ?? (() => Date.now());
  const intervalMs = deps.intervalMs ?? 60_000;
  const minDurationSec = deps.minDurationSec ?? 5;
  const capDurationSec = deps.capDurationSec ?? 90;

  let visibleSince: number | null = null;
  let intervalId: ReturnType<typeof setInterval> | null = null;

  function isVisible(): boolean {
    return doc.visibilityState === 'visible';
  }

  function flush(): void {
    if (!isVisible() || visibleSince === null) return;
    const elapsedSec = Math.floor((now() - visibleSince) / 1000);
    if (elapsedSec < minDurationSec) return;
    const capped = Math.min(elapsedSec, capDurationSec);
    visibleSince = now();
    fetcher('/child/events', {
      method: 'POST',
      body: JSON.stringify({ type: 'heartbeat', duration_seconds: capped }),
    }).catch(() => {
      /* silent */
    });
  }

  function onVisibilityChange(): void {
    if (isVisible()) {
      visibleSince = now();
    } else {
      flush();
      visibleSince = null;
    }
  }

  function flushSync(): void {
    if (visibleSince === null) return;
    const elapsedSec = Math.floor((now() - visibleSince) / 1000);
    if (elapsedSec < minDurationSec) return;
    const capped = Math.min(elapsedSec, capDurationSec);
    visibleSince = now();
    fetcher('/child/events', {
      method: 'POST',
      keepalive: true,
      body: JSON.stringify({ type: 'heartbeat', duration_seconds: capped }),
    }).catch(() => {
      /* silent */
    });
  }

  function onBeforeUnload(): void {
    flushSync();
  }

  function start(): void {
    if (isVisible()) {
      visibleSince = now();
    }
    doc.addEventListener('visibilitychange', onVisibilityChange);
    if (typeof window !== 'undefined') {
      window.addEventListener('beforeunload', onBeforeUnload);
    }
    intervalId = setInterval(flush, intervalMs);
  }

  function stop(): void {
    doc.removeEventListener('visibilitychange', onVisibilityChange);
    if (typeof window !== 'undefined') {
      window.removeEventListener('beforeunload', onBeforeUnload);
    }
    if (intervalId !== null) {
      clearInterval(intervalId);
      intervalId = null;
    }
    visibleSince = null;
  }

  function trackSiteOpen(domain: string): void {
    fetcher('/child/events', {
      method: 'POST',
      body: JSON.stringify({
        type: 'site_open',
        domain: domain.toLowerCase(),
        duration_seconds: 0,
      }),
    }).catch(() => {
      /* silent */
    });
  }

  return { start, stop, trackSiteOpen, flushSync };
}
