import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { ProgressCard } from './ProgressCard';

const getProgression = vi.fn();
vi.mock('../api/gamification', () => ({ getProgression: () => getProgression() }));

describe('ProgressCard', () => {
  afterEach(() => getProgression.mockReset());

  it('mostra nível, coins e streak', async () => {
    getProgression.mockResolvedValueOnce({
      xp: 150,
      coins: 20,
      level: 2,
      xpIntoLevel: 50,
      xpForNextLevel: 200,
      streakDays: 3,
    });
    renderWithClient(<ProgressCard />);
    expect(await screen.findByText(/nível 2/i)).toBeInTheDocument();
    expect(screen.getByText('20')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();
  });
});
