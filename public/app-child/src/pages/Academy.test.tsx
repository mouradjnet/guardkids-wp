import { fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Academy } from './Academy';

const getAcademy = vi.fn();
const completeLesson = vi.fn();
vi.mock('../api/academy', () => ({
  getAcademy: () => getAcademy(),
  completeLesson: (key: string) => completeLesson(key),
}));

const wallet = { xp: 0, coins: 0, level: 1, xpIntoLevel: 0, xpForNextLevel: 100, streakDays: 0 };
const emptyState = { completedKeys: [], progression: wallet };

// Abre a 1ª aula da trilha "Meu Mundo Digital Seguro".
async function openFirstLesson(): Promise<void> {
  fireEvent.click(await screen.findByText('Meu Mundo Digital Seguro'));
  fireEvent.click(await screen.findByText('O que é ficar seguro?'));
}

describe('Academy (criança)', () => {
  afterEach(() => {
    getAcademy.mockReset();
    completeLesson.mockReset();
  });

  it('lista as trilhas com progresso 0/4', async () => {
    getAcademy.mockResolvedValueOnce(emptyState);
    renderWithClient(<Academy onNavigate={() => {}} />);

    expect(await screen.findByText('Meu Mundo Digital Seguro')).toBeInTheDocument();
    expect(screen.getByText('Meu Tempo de Tela')).toBeInTheDocument();
    expect(screen.getAllByText('0 de 4 aulas')).toHaveLength(2);
  });

  it('concluir uma aula credita XP e mostra a celebração', async () => {
    getAcademy.mockResolvedValue(emptyState);
    completeLesson.mockResolvedValueOnce({
      completedKeys: ['seguranca-intro'],
      progression: { ...wallet, xp: 25, coins: 15 },
      awarded: { justCompleted: true, xp: 25, coins: 15 },
    });
    renderWithClient(<Academy onNavigate={() => {}} />);

    await openFirstLesson();
    fireEvent.click(await screen.findByRole('button', { name: 'Concluí!' }));

    // a celebração só existe DEPOIS que o POST resolve (não é verdade de partida)
    expect(await screen.findByText(/concluiu a aula/i)).toBeInTheDocument();
    expect(screen.getByText(/\+25 XP/)).toBeInTheDocument();
    expect(completeLesson).toHaveBeenCalledWith('seguranca-intro');
  });

  it('aula já concluída mostra selo e esconde o botão de concluir', async () => {
    getAcademy.mockResolvedValueOnce({ ...emptyState, completedKeys: ['seguranca-intro'] });
    renderWithClient(<Academy onNavigate={() => {}} />);

    await openFirstLesson();

    expect(await screen.findByText('Aula concluída')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Concluí!' })).not.toBeInTheDocument();
  });

  it('erro ao concluir aparece visível (não some mudo)', async () => {
    getAcademy.mockResolvedValue(emptyState);
    completeLesson.mockRejectedValueOnce(new Error('deu ruim'));
    renderWithClient(<Academy onNavigate={() => {}} />);

    await openFirstLesson();
    fireEvent.click(await screen.findByRole('button', { name: 'Concluí!' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/deu ruim/i);
  });
});
