import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { MissionsCard } from './MissionsCard';

const getMissions = vi.fn();
vi.mock('../api/gamification', () => ({ getMissions: () => getMissions() }));

const missions = [
  { key: 'explore_3', title: 'Explorador do dia', description: 'Abra 3 conteúdos hoje', icon: 'explore', target: 3, progress: 1, completed: false, justCompleted: false, xpReward: 15, coinsReward: 10 },
  { key: 'categories_2', title: 'Curioso', description: 'Explore 2 categorias diferentes hoje', icon: 'category', target: 2, progress: 2, completed: true, justCompleted: false, xpReward: 15, coinsReward: 10 },
  { key: 'streak_today', title: 'Presença', description: 'Volte e mantenha sua sequência hoje', icon: 'local_fire_department', target: 1, progress: 0, completed: false, justCompleted: false, xpReward: 10, coinsReward: 5 },
];

describe('MissionsCard', () => {
  afterEach(() => getMissions.mockReset());

  it('lista as 3 missões com título', async () => {
    getMissions.mockResolvedValueOnce(missions);
    renderWithClient(<MissionsCard />);
    expect(await screen.findByText('Explorador do dia')).toBeInTheDocument();
    expect(screen.getByText('Curioso')).toBeInTheDocument();
    expect(screen.getByText('Presença')).toBeInTheDocument();
  });

  it('mostra o progresso e marca a missão concluída', async () => {
    getMissions.mockResolvedValueOnce(missions);
    renderWithClient(<MissionsCard />);
    expect(await screen.findByText('1/3')).toBeInTheDocument();
    // a missão completa expõe o marcador de concluída
    expect(screen.getByTestId('mission-completed-categories_2')).toBeInTheDocument();
  });

  it('não renderiza nada quando não há missões', async () => {
    getMissions.mockResolvedValueOnce([]);
    const { container } = renderWithClient(<MissionsCard />);
    // espera o fetch resolver
    await screen.findByTestId('missions-empty');
    expect(container.querySelector('[data-testid="mission-row"]')).toBeNull();
  });
});
