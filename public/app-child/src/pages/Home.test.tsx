import { screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import type { Child } from '../api/types';
import { renderWithClient } from '../test/queryClient';
import { Home } from './Home';

const getMe = vi.fn();
const clearStoredToken = vi.fn();

vi.mock('../api/child', () => ({
  getMe: () => getMe(),
  listMyRequests: () => Promise.resolve([]),
}));

vi.mock('../api/token', () => ({
  clearStoredToken: () => clearStoredToken(),
}));

const sampleChild: Child = {
  id: 7,
  slug: 'sam',
  name: 'Sam',
  age: 9,
  avatarUrl: null,
  device: null,
  status: 'online',
  usedMinutes: 30,
  limitMinutes: 120,
};

describe('Home', () => {
  const originalReload = window.location.reload;

  beforeEach(() => {
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, reload: vi.fn() },
    });
  });

  afterEach(() => {
    getMe.mockReset();
    clearStoredToken.mockReset();
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, reload: originalReload },
    });
  });

  it('exibe spinner durante o loading', () => {
    getMe.mockImplementation(() => new Promise(() => {}));
    renderWithClient(<Home onNavigate={() => {}} />);
    expect(document.querySelector('.animate-spin')).toBeTruthy();
  });

  it('renderiza o nome da criança quando getMe responde', async () => {
    getMe.mockResolvedValueOnce(sampleChild);
    renderWithClient(<Home onNavigate={() => {}} />);
    await waitFor(() => {
      expect(screen.getByText(/sam/i)).toBeInTheDocument();
    });
  });

  it('em 401, limpa o token e força reload', async () => {
    getMe.mockRejectedValueOnce(
      new ApiError('child_auth_required', 'Token inválido.', 401),
    );
    renderWithClient(<Home onNavigate={() => {}} />);
    await waitFor(() => {
      expect(clearStoredToken).toHaveBeenCalledTimes(1);
      expect(window.location.reload).toHaveBeenCalledTimes(1);
    });
  });

  it('em erro genérico, mostra mensagem amigável', async () => {
    getMe.mockRejectedValueOnce(new Error('offline'));
    renderWithClient(<Home onNavigate={() => {}} />);
    expect(
      await screen.findByText(/não foi possível carregar o seu perfil/i),
    ).toBeInTheDocument();
    expect(screen.getByText('offline')).toBeInTheDocument();
  });
});
