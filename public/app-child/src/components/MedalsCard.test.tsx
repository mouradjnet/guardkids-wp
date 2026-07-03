import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { MedalsCard } from './MedalsCard';

const getMedals = vi.fn();
vi.mock('../api/gamification', () => ({ getMedals: () => getMedals() }));

const medals = [
  { key: 'explorer_10', title: 'Explorador', description: 'Abriu 10 conteúdos', icon: 'explore', target: 10, progress: 10, unlocked: true, justUnlocked: false, xpReward: 30, coinsReward: 20 },
  { key: 'devourer_50', title: 'Devorador', description: 'Abriu 50 conteúdos', icon: 'auto_stories', target: 50, progress: 12, unlocked: false, justUnlocked: false, xpReward: 60, coinsReward: 40 },
  { key: 'achiever_10', title: 'Cumpridor', description: 'Completou 10 missões', icon: 'task_alt', target: 10, progress: 3, unlocked: false, justUnlocked: false, xpReward: 40, coinsReward: 25 },
  { key: 'faithful_7', title: 'Fiel', description: '7 dias de sequência', icon: 'local_fire_department', target: 7, progress: 7, unlocked: true, justUnlocked: false, xpReward: 40, coinsReward: 25 },
  { key: 'veteran_10', title: 'Veterano', description: 'Alcançou o nível 10', icon: 'military_tech', target: 10, progress: 2, unlocked: false, justUnlocked: false, xpReward: 50, coinsReward: 30 },
  { key: 'curious_master_5', title: 'Curioso Master', description: 'Explorou 5 categorias', icon: 'category', target: 5, progress: 5, unlocked: true, justUnlocked: false, xpReward: 40, coinsReward: 25 },
];

describe('MedalsCard', () => {
  afterEach(() => getMedals.mockReset());

  it('mostra o contador de desbloqueadas X/6', async () => {
    getMedals.mockResolvedValueOnce(medals);
    renderWithClient(<MedalsCard />);
    expect(await screen.findByText('3/6')).toBeInTheDocument();
  });

  it('marca desbloqueadas e mostra progresso das bloqueadas', async () => {
    getMedals.mockResolvedValueOnce(medals);
    renderWithClient(<MedalsCard />);
    expect(await screen.findByTestId('medal-unlocked-explorer_10')).toBeInTheDocument();
    expect(screen.getByTestId('medal-locked-devourer_50')).toBeInTheDocument();
    expect(screen.getByText('12/50')).toBeInTheDocument();
  });

  it('não renderiza nada quando não há medalhas', async () => {
    getMedals.mockResolvedValueOnce([]);
    renderWithClient(<MedalsCard />);
    await screen.findByTestId('medals-empty');
    expect(screen.queryByTestId('medal-tile')).toBeNull();
  });
});
