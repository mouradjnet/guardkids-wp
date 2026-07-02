import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Child } from '../api/types';
import { renderWithClient } from '../test/queryClient';
import { Header } from './Header';

const getMe = vi.fn();
vi.mock('../api/child', () => ({ getMe: () => getMe() }));

const child: Child = {
  id: 1,
  slug: 'lucas',
  name: 'Lucas',
  age: 9,
  avatarUrl: null,
  device: 'Tablet',
  status: 'online',
  usedMinutes: 32,
  limitMinutes: 60,
};

describe('Header', () => {
  afterEach(() => getMe.mockReset());

  it('abre o perfil ao clicar no ícone do boneco', async () => {
    getMe.mockResolvedValue(child);
    renderWithClient(<Header activePage="home" onNavigate={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: 'Perfil' }));
    expect(await screen.findByText('Aparelho protegido')).toBeInTheDocument();
    expect(screen.getByText('Lucas')).toBeInTheDocument();
    expect(screen.getByText('32/60 min')).toBeInTheDocument();
  });

  it('fecha o perfil ao clicar em Fechar', async () => {
    getMe.mockResolvedValue(child);
    renderWithClient(<Header activePage="home" onNavigate={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: 'Perfil' }));
    await screen.findByText('Aparelho protegido');
    fireEvent.click(screen.getByRole('button', { name: 'Fechar' }));
    await waitFor(() =>
      expect(screen.queryByText('Aparelho protegido')).not.toBeInTheDocument(),
    );
  });

  it('o sininho navega para os avisos', () => {
    getMe.mockResolvedValue(child);
    const onNavigate = vi.fn();
    renderWithClient(<Header activePage="home" onNavigate={onNavigate} />);
    fireEvent.click(screen.getByRole('button', { name: 'Notificações' }));
    expect(onNavigate).toHaveBeenCalledWith('alerts');
  });
});
