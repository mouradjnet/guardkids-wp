import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Blocked } from './Blocked';

const { verifyPinMock } = vi.hoisted(() => ({ verifyPinMock: vi.fn() }));
vi.mock('../api/child', () => ({ verifyPin: verifyPinMock }));

const NOW = new Date('2026-06-09T00:00:00Z');
const UNLOCK_10H = new Date(NOW.getTime() + 36_000_000).toISOString(); // +10h

describe('Blocked', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(NOW);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('renderiza countdown derivado de unlockAt em HH:MM:SS', () => {
    render(<Blocked onNavigate={() => {}} reason="bedtime" unlockAt={UNLOCK_10H} />);
    expect(screen.getByText('10:00:00')).toBeInTheDocument();
  });

  it('decrementa o timer a cada segundo (recalcula contra Date.now)', () => {
    render(<Blocked onNavigate={() => {}} reason="bedtime" unlockAt={UNLOCK_10H} />);
    act(() => {
      vi.advanceTimersByTime(3000);
    });
    expect(screen.getByText('09:59:57')).toBeInTheDocument();
  });

  it('countdown vira 00:00:00 quando unlockAt é null', () => {
    render(<Blocked onNavigate={() => {}} reason="bedtime" unlockAt={null} />);
    expect(screen.getByText('00:00:00')).toBeInTheDocument();
  });

  it('chama onNavigate("home") ao clicar no botão Voltar (preview mode)', () => {
    const onNavigate = vi.fn();
    render(<Blocked onNavigate={onNavigate} reason="bedtime" unlockAt={UNLOCK_10H} />);
    fireEvent.click(screen.getByRole('button', { name: /voltar/i }));
    expect(onNavigate).toHaveBeenCalledWith('home');
  });

  it('lockedMode esconde o botão Voltar (bloqueio real)', () => {
    render(
      <Blocked
        onNavigate={() => {}}
        reason="bedtime"
        unlockAt={UNLOCK_10H}
        lockedMode
      />,
    );
    expect(screen.queryByRole('button', { name: /voltar/i })).toBeNull();
  });

  it('chama onNavigate("requests") ao clicar em "Solicitar acesso"', () => {
    const onNavigate = vi.fn();
    render(<Blocked onNavigate={onNavigate} reason="bedtime" unlockAt={UNLOCK_10H} />);
    fireEvent.click(
      screen.getByRole('button', { name: /solicitar acesso/i }),
    );
    expect(onNavigate).toHaveBeenCalledWith('requests');
  });

  it('reason=bedtime mostra mensagem e label de bedtime', () => {
    render(<Blocked onNavigate={() => {}} reason="bedtime" unlockAt={UNLOCK_10H} />);
    expect(
      screen.getByText('A hora de dormir começou. Descansa que amanhã tem mais!'),
    ).toBeInTheDocument();
    expect(screen.getByText(/Modo Bedtime/)).toBeInTheDocument();
  });

  it('reason=weekday mostra mensagem e label de dia de pausa', () => {
    render(<Blocked onNavigate={() => {}} reason="weekday" unlockAt={UNLOCK_10H} />);
    expect(
      screen.getByText('Hoje é dia de pausa de tela. Aproveita pra fazer outras coisas!'),
    ).toBeInTheDocument();
    expect(screen.getByText(/Modo Dia de pausa/)).toBeInTheDocument();
  });

  it('reason=limit mostra mensagem e label de tempo esgotado', () => {
    render(<Blocked onNavigate={() => {}} reason="limit" unlockAt={UNLOCK_10H} />);
    expect(
      screen.getByText('Você usou todo o tempo de tela de hoje. Amanhã recarrega!'),
    ).toBeInTheDocument();
    expect(screen.getByText(/Modo Tempo esgotado/)).toBeInTheDocument();
  });

  it('reason=null cai no fallback bedtime', () => {
    render(<Blocked onNavigate={() => {}} reason={null} unlockAt={UNLOCK_10H} />);
    expect(screen.getByText(/Modo Bedtime/)).toBeInTheDocument();
  });

  it('mostra as alternativas sugeridas', () => {
    render(<Blocked onNavigate={() => {}} reason="bedtime" unlockAt={UNLOCK_10H} />);
    expect(screen.getByText('Ler um livro')).toBeInTheDocument();
    expect(screen.getByText('Montar quebra-cabeça')).toBeInTheDocument();
    expect(screen.getByText('Descansar os olhos')).toBeInTheDocument();
  });
});

describe('Blocked · desbloqueio por PIN', () => {
  beforeEach(() => verifyPinMock.mockReset());

  it('não mostra o desbloqueio por PIN sem lockedMode + pinUnlockEnabled', () => {
    render(<Blocked onNavigate={() => {}} reason="bedtime" unlockAt={null} lockedMode />);
    expect(screen.queryByText(/desbloquear com pin/i)).toBeNull();
  });

  it('PIN correto chama onPinUnlock', async () => {
    verifyPinMock.mockResolvedValue({ ok: true });
    const onPinUnlock = vi.fn();
    const user = userEvent.setup();
    render(
      <Blocked
        onNavigate={() => {}}
        reason="bedtime"
        unlockAt={null}
        lockedMode
        pinUnlockEnabled
        onPinUnlock={onPinUnlock}
      />,
    );

    await user.click(screen.getByRole('button', { name: /desbloquear com pin/i }));
    await user.type(screen.getByLabelText(/pin dos responsáveis/i), '1234');
    await user.click(screen.getByRole('button', { name: /liberar/i }));

    await waitFor(() => expect(onPinUnlock).toHaveBeenCalled());
    expect(verifyPinMock).toHaveBeenCalledWith('1234');
  });

  it('PIN incorreto mostra erro e não libera', async () => {
    verifyPinMock.mockResolvedValue({ ok: false });
    const onPinUnlock = vi.fn();
    const user = userEvent.setup();
    render(
      <Blocked
        onNavigate={() => {}}
        reason="bedtime"
        unlockAt={null}
        lockedMode
        pinUnlockEnabled
        onPinUnlock={onPinUnlock}
      />,
    );

    await user.click(screen.getByRole('button', { name: /desbloquear com pin/i }));
    await user.type(screen.getByLabelText(/pin dos responsáveis/i), '9999');
    await user.click(screen.getByRole('button', { name: /liberar/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/pin incorreto/i);
    expect(onPinUnlock).not.toHaveBeenCalled();
  });
});
