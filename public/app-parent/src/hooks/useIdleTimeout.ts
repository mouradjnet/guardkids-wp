import { useCallback, useEffect, useRef } from 'react';

const ACTIVITY_EVENTS = ['mousemove', 'keydown', 'touchstart', 'scroll', 'click'] as const;
const STORAGE_KEY = 'gk_last_activity';
const THROTTLE_MS = 1_000;

type Options = {
  enabled: boolean;
  minutes: number;
  warningSeconds?: number;
  onWarn: () => void;
  onTimeout: () => void;
  /** Chamado quando há atividade enquanto o aviso está visível (pra dispensá-lo). */
  onActivityWhileWarned?: () => void;
};

/**
 * Auto-logout por inatividade. Arma timers de aviso + timeout, escuta atividade
 * (throttled) e sincroniza o "último uso" entre abas via localStorage. Tudo
 * client-side; ver caveat no spec.
 */
export function useIdleTimeout({
  enabled,
  minutes,
  warningSeconds = 30,
  onWarn,
  onTimeout,
  onActivityWhileWarned,
}: Options): { reset: () => void } {
  const warnTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const logoutTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const warnedRef = useRef(false);
  const lastWriteRef = useRef(0);

  // Refs pros callbacks pra não re-armar o efeito a cada render.
  const cbs = useRef({ onWarn, onTimeout, onActivityWhileWarned });
  cbs.current = { onWarn, onTimeout, onActivityWhileWarned };

  const clearTimers = useCallback(() => {
    if (warnTimer.current) clearTimeout(warnTimer.current);
    if (logoutTimer.current) clearTimeout(logoutTimer.current);
    warnTimer.current = null;
    logoutTimer.current = null;
  }, []);

  const schedule = useCallback(() => {
    clearTimers();
    warnedRef.current = false;
    if (!enabled) return;
    const totalMs = minutes * 60 * 1000;
    const warnMs = Math.max(0, totalMs - warningSeconds * 1000);
    warnTimer.current = setTimeout(() => {
      warnedRef.current = true;
      cbs.current.onWarn();
    }, warnMs);
    logoutTimer.current = setTimeout(() => {
      cbs.current.onTimeout();
    }, totalMs);
  }, [enabled, minutes, warningSeconds, clearTimers]);

  const reset = useCallback(() => {
    if (warnedRef.current) cbs.current.onActivityWhileWarned?.();
    schedule();
  }, [schedule]);

  useEffect(() => {
    if (!enabled) {
      clearTimers();
      return;
    }
    const onActivity = () => {
      const now = Date.now();
      if (now - lastWriteRef.current < THROTTLE_MS) return;
      lastWriteRef.current = now;
      try {
        localStorage.setItem(STORAGE_KEY, String(now));
      } catch {
        // localStorage indisponível — segue só com timers locais.
      }
      reset();
    };
    const onStorage = (e: StorageEvent) => {
      if (e.key === STORAGE_KEY) reset();
    };

    ACTIVITY_EVENTS.forEach((evt) => window.addEventListener(evt, onActivity, { passive: true }));
    window.addEventListener('storage', onStorage);
    schedule();

    return () => {
      ACTIVITY_EVENTS.forEach((evt) => window.removeEventListener(evt, onActivity));
      window.removeEventListener('storage', onStorage);
      clearTimers();
    };
  }, [enabled, minutes, warningSeconds, schedule, reset, clearTimers]);

  return { reset };
}
