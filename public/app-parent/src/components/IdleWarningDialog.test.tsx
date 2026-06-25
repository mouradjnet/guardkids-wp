import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { IdleWarningDialog } from './IdleWarningDialog';

describe('IdleWarningDialog', () => {
  it('mostra a contagem e os botões', () => {
    render(<IdleWarningDialog secondsLeft={30} onStay={() => {}} onLogout={() => {}} />);
    expect(screen.getByText(/30s/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /continuar logado/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sair agora/i })).toBeInTheDocument();
  });

  it('chama onStay e onLogout', async () => {
    const onStay = vi.fn();
    const onLogout = vi.fn();
    const user = userEvent.setup();
    render(<IdleWarningDialog secondsLeft={10} onStay={onStay} onLogout={onLogout} />);
    await user.click(screen.getByRole('button', { name: /continuar logado/i }));
    await user.click(screen.getByRole('button', { name: /sair agora/i }));
    expect(onStay).toHaveBeenCalledTimes(1);
    expect(onLogout).toHaveBeenCalledTimes(1);
  });
});
