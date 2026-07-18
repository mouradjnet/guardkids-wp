import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { Child } from '../api/types';
import { ScreenTime } from './ScreenTime';

const base: Child = {
  id: 1, name: 'Lucas', age: 9, avatarUrl: null, status: 'online',
  usedMinutes: 0, limitMinutes: 60, allowedWeekdays: 'YYYYYYY',
  bedtimeEnabled: false, bedtimeStart: null, bedtimeEnd: null,
} as Child;

function renderWith(overrides: Partial<Child>) {
  return render(<ScreenTime child={{ ...base, ...overrides }} />);
}

describe('ScreenTime', () => {
  it('mostra o tempo restante (limite 60, usado 20 → 40)', () => {
    renderWith({ limitMinutes: 60, usedMinutes: 20 });
    expect(screen.getByText('40')).toBeInTheDocument();
    expect(screen.getByText(/min restantes/i)).toBeInTheDocument();
  });

  it('clampa o restante em 0 quando usou mais que o limite', () => {
    renderWith({ limitMinutes: 60, usedMinutes: 90 });
    expect(screen.getByText('0')).toBeInTheDocument();
  });

  it('rótulo do total: 120 min → "2 horas"', () => {
    renderWith({ limitMinutes: 120, usedMinutes: 0 });
    expect(screen.getByText(/2 horas de limite diário/i)).toBeInTheDocument();
  });

  it('rótulo do total: 90 min → "1 hora 30 min"', () => {
    renderWith({ limitMinutes: 90, usedMinutes: 0 });
    expect(screen.getByText(/1 hora 30 min de limite diário/i)).toBeInTheDocument();
  });

  it('rótulo do total: 45 min → "45 min"', () => {
    renderWith({ limitMinutes: 45, usedMinutes: 0 });
    expect(screen.getByText(/45 min de limite diário/i)).toBeInTheDocument();
  });
});
