import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Avatar } from './Avatar';

const getAvatars = vi.fn();
const equipAvatar = vi.fn();
vi.mock('../api/avatars', () => ({
  getAvatars: () => getAvatars(),
  equipAvatar: (k: string) => equipAvatar(k),
}));

const payload = {
  equipped: 'star',
  avatars: [
    { key: 'star', emoji: '⭐', label: 'Estrela', requirementLabel: 'Grátis', unlocked: true, isEquipped: true },
    { key: 'rocket', emoji: '🚀', label: 'Foguete', requirementLabel: 'Nível 5', unlocked: false, isEquipped: false },
  ],
};

describe('Avatar', () => {
  afterEach(() => {
    getAvatars.mockReset();
    equipAvatar.mockReset();
  });

  it('mostra desbloqueados e bloqueados com requisito', async () => {
    getAvatars.mockResolvedValueOnce(payload);
    renderWithClient(<Avatar onNavigate={() => {}} />);
    expect(await screen.findByText('Estrela')).toBeInTheDocument();
    expect(screen.getByText('Nível 5')).toBeInTheDocument();
    expect(screen.getByTestId('avatar-locked-rocket')).toBeInTheDocument();
  });

  it('equipa ao tocar num desbloqueado', async () => {
    getAvatars.mockResolvedValue(payload);
    equipAvatar.mockResolvedValueOnce({ equipped: 'star' });
    renderWithClient(<Avatar onNavigate={() => {}} />);
    fireEvent.click(await screen.findByTestId('avatar-option-star'));
    await waitFor(() => expect(equipAvatar).toHaveBeenCalledWith('star'));
  });

  it('mostra erro visível quando equipar falha (não some mudo)', async () => {
    getAvatars.mockResolvedValue(payload);
    equipAvatar.mockRejectedValueOnce(new Error('servidor fora'));
    renderWithClient(<Avatar onNavigate={() => {}} />);
    fireEvent.click(await screen.findByTestId('avatar-option-star'));
    expect(await screen.findByRole('alert')).toHaveTextContent(/servidor fora/i);
  });
});
