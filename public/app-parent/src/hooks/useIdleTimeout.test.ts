import { renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useIdleTimeout } from './useIdleTimeout';

describe('useIdleTimeout', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => {
    vi.runOnlyPendingTimers();
    vi.useRealTimers();
    localStorage.clear();
  });

  it('dispara onWarn antes do timeout e onTimeout no fim', () => {
    const onWarn = vi.fn();
    const onTimeout = vi.fn();
    renderHook(() =>
      useIdleTimeout({ enabled: true, minutes: 1, warningSeconds: 30, onWarn, onTimeout }),
    );

    vi.advanceTimersByTime(29_000);
    expect(onWarn).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1_000); // 30s → warn (60-30)
    expect(onWarn).toHaveBeenCalledTimes(1);
    expect(onTimeout).not.toHaveBeenCalled();
    vi.advanceTimersByTime(30_000); // 60s → timeout
    expect(onTimeout).toHaveBeenCalledTimes(1);
  });

  it('reset() reinicia a contagem', () => {
    const onWarn = vi.fn();
    const onTimeout = vi.fn();
    const { result } = renderHook(() =>
      useIdleTimeout({ enabled: true, minutes: 1, warningSeconds: 30, onWarn, onTimeout }),
    );

    vi.advanceTimersByTime(29_000); // quase no aviso (30s)
    result.current.reset(); // reinicia a contagem
    vi.advanceTimersByTime(29_000);
    expect(onWarn).not.toHaveBeenCalled(); // recomeçou: ainda não avisou
    vi.advanceTimersByTime(1_000); // 30s desde o reset → aviso
    expect(onWarn).toHaveBeenCalledTimes(1);
  });

  it('não arma nada quando enabled=false', () => {
    const onWarn = vi.fn();
    const onTimeout = vi.fn();
    renderHook(() =>
      useIdleTimeout({ enabled: false, minutes: 1, warningSeconds: 30, onWarn, onTimeout }),
    );
    vi.advanceTimersByTime(120_000);
    expect(onWarn).not.toHaveBeenCalled();
    expect(onTimeout).not.toHaveBeenCalled();
  });

  it('atividade do usuário (evento) reseta os timers', () => {
    const onWarn = vi.fn();
    const onTimeout = vi.fn();
    renderHook(() =>
      useIdleTimeout({ enabled: true, minutes: 1, warningSeconds: 30, onWarn, onTimeout }),
    );
    vi.advanceTimersByTime(20_000); // antes do aviso (30s)
    window.dispatchEvent(new Event('keydown')); // reseta a contagem
    vi.advanceTimersByTime(29_000);
    expect(onWarn).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1_000); // 30s desde o reset → aviso
    expect(onWarn).toHaveBeenCalledTimes(1);
  });
});
