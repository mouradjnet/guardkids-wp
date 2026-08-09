import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { AcademyPanel } from './AcademyPanel';

function setup(overrides: Partial<Parameters<typeof AcademyPanel>[0]> = {}) {
  const onClose = vi.fn();
  const onComplete = vi.fn();
  const onDismiss = vi.fn();
  render(
    <AcademyPanel
      title="Primeiros Passos"
      reason="Você ainda não cadastrou nenhum filho."
      body={'# Conteúdo da aula\n\n- Acesse sua conta'}
      onClose={onClose}
      onComplete={onComplete}
      onDismiss={onDismiss}
      {...overrides}
    />,
  );
  return { onClose, onComplete, onDismiss };
}

describe('AcademyPanel', () => {
  it('mostra título, motivo e corpo em markdown', () => {
    setup();
    expect(screen.getByRole('dialog', { name: 'Primeiros Passos' })).toBeInTheDocument();
    expect(screen.getByText('Você ainda não cadastrou nenhum filho.')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Conteúdo da aula' })).toBeInTheDocument();
    expect(screen.getByText('Acesse sua conta')).toBeInTheDocument();
  });

  it('"Concluí" dispara onComplete', async () => {
    const { onComplete } = setup();
    await userEvent.click(screen.getByRole('button', { name: 'Concluí' }));
    expect(onComplete).toHaveBeenCalledOnce();
  });

  it('"Agora não" dispara onDismiss', async () => {
    const { onDismiss } = setup();
    await userEvent.click(screen.getByRole('button', { name: 'Agora não' }));
    expect(onDismiss).toHaveBeenCalledOnce();
  });

  it('fechar dispara onClose', async () => {
    const { onClose } = setup();
    await userEvent.click(screen.getByRole('button', { name: 'Fechar' }));
    expect(onClose).toHaveBeenCalledOnce();
  });

  it('quando busy, as ações ficam desabilitadas', () => {
    setup({ busy: true });
    expect(screen.getByRole('button', { name: 'Concluí' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Agora não' })).toBeDisabled();
  });
});
