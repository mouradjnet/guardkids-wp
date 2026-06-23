import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { DeleteAccountDialog } from './DeleteAccountDialog';

describe('DeleteAccountDialog', () => {
  it('keeps confirm disabled until EXCLUIR is typed', async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    render(
      <DeleteAccountDialog open onClose={() => {}} onConfirm={onConfirm} pending={false} />,
    );

    const btn = screen.getByRole('button', { name: /excluir tudo/i });
    expect(btn).toBeDisabled();

    await user.type(screen.getByLabelText(/digite/i), 'EXCLUIR');
    expect(btn).toBeEnabled();

    await user.click(btn);
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('does not render when closed', () => {
    render(
      <DeleteAccountDialog open={false} onClose={() => {}} onConfirm={() => {}} pending={false} />,
    );
    expect(screen.queryByLabelText(/digite/i)).not.toBeInTheDocument();
  });
});
