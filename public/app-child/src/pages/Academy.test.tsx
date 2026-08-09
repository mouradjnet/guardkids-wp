import { fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithClient } from '../test/queryClient';
import { Academy } from './Academy';

const getAcademy = vi.fn();
const submitQuiz = vi.fn();
vi.mock('../api/academy', () => ({
  getAcademy: () => getAcademy(),
  submitQuiz: (key: string, answers: number[]) => submitQuiz(key, answers),
}));

const wallet = { xp: 0, coins: 0, level: 1, xpIntoLevel: 0, xpForNextLevel: 100, streakDays: 0 };
const emptyState = { completedKeys: [], progression: wallet };

// Abre a 1ª aula ("O que é ficar seguro?") da trilha "Meu Mundo Digital Seguro".
async function openFirstLesson(): Promise<void> {
  fireEvent.click(await screen.findByText('Meu Mundo Digital Seguro'));
  fireEvent.click(await screen.findByText('O que é ficar seguro?'));
}

// Seleciona uma alternativa em cada uma das 3 questões (índices [1,1,1] p/
// seguranca-intro). Os textos são as alternativas do quizzes.ts.
function answerQuiz(): void {
  fireEvent.click(screen.getByRole('button', { name: 'Saber com quem você fala' }));
  fireEvent.click(screen.getByRole('button', { name: 'Pedir ajuda a um adulto de confiança' }));
  fireEvent.click(screen.getByRole('button', { name: 'Só quem você conhece e confia' }));
}

describe('Academy (criança)', () => {
  afterEach(() => {
    getAcademy.mockReset();
    submitQuiz.mockReset();
  });

  it('lista as trilhas com progresso 0/4', async () => {
    getAcademy.mockResolvedValueOnce(emptyState);
    renderWithClient(<Academy onNavigate={() => {}} />);

    expect(await screen.findByText('Meu Mundo Digital Seguro')).toBeInTheDocument();
    expect(screen.getByText('Meu Tempo de Tela')).toBeInTheDocument();
    expect(screen.getAllByText('0 de 4 aulas')).toHaveLength(2);
  });

  it('o botão de enviar só habilita depois de responder todas as questões', async () => {
    getAcademy.mockResolvedValue(emptyState);
    renderWithClient(<Academy onNavigate={() => {}} />);

    await openFirstLesson();
    expect(await screen.findByRole('button', { name: 'Enviar respostas' })).toBeDisabled();
    answerQuiz();
    expect(screen.getByRole('button', { name: 'Enviar respostas' })).toBeEnabled();
  });

  it('aprovar no quiz credita XP e mostra a celebração', async () => {
    getAcademy.mockResolvedValue(emptyState);
    submitQuiz.mockResolvedValueOnce({
      passed: true,
      correct: 3,
      total: 3,
      completedKeys: ['seguranca-intro'],
      progression: { ...wallet, xp: 25, coins: 15 },
      awarded: { justCompleted: true, xp: 25, coins: 15 },
    });
    renderWithClient(<Academy onNavigate={() => {}} />);

    await openFirstLesson();
    answerQuiz();
    fireEvent.click(screen.getByRole('button', { name: 'Enviar respostas' }));

    // a celebração só existe DEPOIS que o POST resolve (não é verdade de partida)
    expect(await screen.findByText(/concluiu a aula/i)).toBeInTheDocument();
    expect(screen.getByText(/\+25 XP/)).toBeInTheDocument();
    expect(submitQuiz).toHaveBeenCalledWith('seguranca-intro', [1, 1, 1]);
  });

  it('reprovar não credita e mostra "tentar de novo"', async () => {
    getAcademy.mockResolvedValue(emptyState);
    submitQuiz.mockResolvedValueOnce({
      passed: false,
      correct: 2,
      total: 3,
      completedKeys: [],
      progression: wallet,
      awarded: { justCompleted: false, xp: 0, coins: 0 },
    });
    renderWithClient(<Academy onNavigate={() => {}} />);

    await openFirstLesson();
    answerQuiz();
    fireEvent.click(screen.getByRole('button', { name: 'Enviar respostas' }));

    expect(await screen.findByText(/Você acertou 2 de 3/i)).toBeInTheDocument();
    expect(screen.queryByText(/concluiu a aula/i)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Tentar de novo' })).toBeInTheDocument();
  });

  it('aula já concluída mostra selo e não pede quiz', async () => {
    getAcademy.mockResolvedValueOnce({ ...emptyState, completedKeys: ['seguranca-intro'] });
    renderWithClient(<Academy onNavigate={() => {}} />);

    await openFirstLesson();

    expect(await screen.findByText('Aula concluída')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Enviar respostas' })).not.toBeInTheDocument();
  });

  it('erro ao enviar aparece visível (não some mudo)', async () => {
    getAcademy.mockResolvedValue(emptyState);
    submitQuiz.mockRejectedValueOnce(new Error('deu ruim'));
    renderWithClient(<Academy onNavigate={() => {}} />);

    await openFirstLesson();
    answerQuiz();
    fireEvent.click(screen.getByRole('button', { name: 'Enviar respostas' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/deu ruim/i);
  });
});
