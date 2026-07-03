import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { GamificationDashboard } from './GamificationDashboard';

const listChildren = vi.fn();
const getChildProgression = vi.fn();
vi.mock('../api/children', () => ({ listChildren: () => listChildren() }));
vi.mock('../api/gamification', () => ({ getChildProgression: (id: number) => getChildProgression(id) }));

describe('GamificationDashboard', () => {
  afterEach(() => {
    listChildren.mockReset();
    getChildProgression.mockReset();
  });

  it('mostra um card por filho com nível, coins e medalhas', async () => {
    listChildren.mockResolvedValueOnce([{ id: 5, name: 'Lucas' }]);
    getChildProgression.mockResolvedValue({ xp: 150, coins: 20, level: 2, streakDays: 3, missionsCompleted: 0, medalsUnlocked: 4 });
    renderWithClient(<GamificationDashboard />);
    expect(await screen.findByText('Lucas')).toBeInTheDocument();
    expect(await screen.findByText(/nível 2/i)).toBeInTheDocument();
    expect(await screen.findByText('Medalhas')).toBeInTheDocument();
    expect(await screen.findByText('4')).toBeInTheDocument();
  });

  it('mostra estado vazio sem filhos', async () => {
    listChildren.mockResolvedValueOnce([]);
    renderWithClient(<GamificationDashboard />);
    expect(await screen.findByText(/nenhum filho/i)).toBeInTheDocument();
  });
});
