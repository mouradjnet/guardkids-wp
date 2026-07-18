import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { Child } from '../api/types';
import { RegrasDoDia } from './RegrasDoDia';

const base: Child = {
  id: 1,
  name: 'Lucas',
  age: 9,
  avatarUrl: null,
  status: 'online',
  usedMinutes: 0,
  limitMinutes: 60,
  allowedWeekdays: 'YYYYYYY', // todos liberados → hoje nunca bloqueado (neutraliza a data)
  bedtimeEnabled: false,
  bedtimeStart: null,
  bedtimeEnd: null,
} as Child;

function renderWith(overrides: Partial<Child>) {
  return render(<RegrasDoDia child={{ ...base, ...overrides }} />);
}

describe('RegrasDoDia', () => {
  it('formata o limite em horas e minutos (90 → 1h 30min)', () => {
    renderWith({ limitMinutes: 90 });
    expect(screen.getByText('1h 30min')).toBeInTheDocument();
  });

  it('limite 0 vira "Sem limite"', () => {
    renderWith({ limitMinutes: 0 });
    expect(screen.getByText('Sem limite')).toBeInTheDocument();
  });

  it('limite só de minutos (45 → "45 min")', () => {
    renderWith({ limitMinutes: 45 });
    expect(screen.getByText('45 min')).toBeInTheDocument();
  });

  it('todos os dias liberados → "Todos os dias"', () => {
    renderWith({ allowedWeekdays: 'YYYYYYY' });
    expect(screen.getByText('Todos os dias')).toBeInTheDocument();
  });

  it('lista os dias de pausa (Sáb e Dom bloqueados)', () => {
    renderWith({ allowedWeekdays: 'YYYYYNN' });
    expect(screen.getByText('Sáb · Dom')).toBeInTheDocument();
  });

  it('bedtime ligado mostra a janela; desligado mostra "Sem horário definido"', () => {
    renderWith({ bedtimeEnabled: true, bedtimeStart: '22:00', bedtimeEnd: '06:00' });
    expect(screen.getByText('22:00 → 06:00')).toBeInTheDocument();
  });

  it('bedtime desligado → "Sem horário definido"', () => {
    renderWith({ bedtimeEnabled: false });
    expect(screen.getByText('Sem horário definido')).toBeInTheDocument();
  });
});
