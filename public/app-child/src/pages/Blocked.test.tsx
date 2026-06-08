import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Blocked } from './Blocked';

describe('Blocked', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('renderiza countdown inicial formatado em HH:MM:SS', () => {
    render(<Blocked onNavigate={() => {}} />);
    // unlockInSeconds = 36000 → 10:00:00
    expect(screen.getByText('10:00:00')).toBeInTheDocument();
  });

  it('decrementa o timer a cada segundo', () => {
    render(<Blocked onNavigate={() => {}} />);
    act(() => {
      vi.advanceTimersByTime(3000);
    });
    expect(screen.getByText('09:59:57')).toBeInTheDocument();
  });

  it('chama onNavigate("home") ao clicar no botão Voltar', () => {
    const onNavigate = vi.fn();
    render(<Blocked onNavigate={onNavigate} />);
    fireEvent.click(screen.getByRole('button', { name: /voltar/i }));
    expect(onNavigate).toHaveBeenCalledWith('home');
  });

  it('chama onNavigate("requests") ao clicar em "Pedir mais tempo pros pais"', () => {
    const onNavigate = vi.fn();
    render(<Blocked onNavigate={onNavigate} />);
    fireEvent.click(
      screen.getByRole('button', { name: /pedir mais tempo pros pais/i }),
    );
    expect(onNavigate).toHaveBeenCalledWith('requests');
  });

  it('mostra a mensagem do mock e as alternativas sugeridas', () => {
    render(<Blocked onNavigate={() => {}} />);
    expect(
      screen.getByText('A hora de dormir começou. Descansa que amanhã tem mais!'),
    ).toBeInTheDocument();
    expect(screen.getByText('Ler um livro')).toBeInTheDocument();
    expect(screen.getByText('Montar quebra-cabeça')).toBeInTheDocument();
    expect(screen.getByText('Descansar os olhos')).toBeInTheDocument();
  });
});
