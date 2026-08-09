import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const getAcademyProgressMock = vi.fn();
const completeLessonMock = vi.fn();
vi.mock('../api/academy', () => ({
  getAcademyProgress: () => getAcademyProgressMock(),
  completeLesson: (id: string) => completeLessonMock(id),
}));

import { Academy } from './Academy';

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return render(<Academy />, { wrapper });
}

describe('Academy page', () => {
  beforeEach(() => {
    getAcademyProgressMock.mockReset().mockResolvedValue({ completed: [], dismissed: [] });
    completeLessonMock.mockReset().mockResolvedValue({ completed: [], dismissed: [] });
  });

  it('mostra as trilhas disponíveis e as "Em breve"', async () => {
    renderPage();
    expect(await screen.findByRole('heading', { name: 'Primeiros Passos' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Tempo de Tela' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Segurança Digital' })).toBeInTheDocument();
    // as coming-soon trazem o selo
    expect(screen.getAllByText('Em breve').length).toBeGreaterThanOrEqual(2);
  });

  it('reflete o progresso concluído na contagem da trilha', async () => {
    getAcademyProgressMock.mockResolvedValue({
      completed: ['primeiros-passos', 'instalacao-inicial'],
      dismissed: [],
    });
    renderPage();
    // 8 aulas na trilha Primeiros Passos, 2 concluídas
    expect(await screen.findByText('2 de 8 aulas')).toBeInTheDocument();
  });

  it('trilha vazia começa em "Começar" e abre a primeira aula', async () => {
    renderPage();
    const começar = await screen.findAllByRole('button', { name: 'Começar' });
    await userEvent.click(começar[0]); // Primeiros Passos
    const dialog = await screen.findByRole('dialog');
    // primeira aula da trilha Primeiros Passos — o motivo (summary) é único
    expect(
      within(dialog).getByText('Conheça o GuardKids e dê o primeiro passo na proteção da família.'),
    ).toBeInTheDocument();
  });

  it('"Continuar" abre a próxima aula não concluída', async () => {
    getAcademyProgressMock.mockResolvedValue({ completed: ['primeiros-passos'], dismissed: [] });
    renderPage();
    const continuar = await screen.findByRole('button', { name: 'Continuar' });
    await userEvent.click(continuar);
    const dialog = await screen.findByRole('dialog');
    // 2ª aula = Instalação Inicial (motivo/summary único)
    expect(
      within(dialog).getByText('Prepare os dispositivos do responsável e da criança.'),
    ).toBeInTheDocument();
  });

  it('concluir uma aula chama completeLesson com o id certo', async () => {
    renderPage();
    const começar = await screen.findAllByRole('button', { name: 'Começar' });
    await userEvent.click(começar[0]);
    await userEvent.click(await screen.findByRole('button', { name: 'Concluí' }));
    await waitFor(() => expect(completeLessonMock).toHaveBeenCalledWith('primeiros-passos'));
  });

  it('no painel da Academia não há botão "Agora não"', async () => {
    renderPage();
    const começar = await screen.findAllByRole('button', { name: 'Começar' });
    await userEvent.click(começar[0]);
    await screen.findByRole('dialog');
    expect(screen.queryByRole('button', { name: 'Agora não' })).not.toBeInTheDocument();
  });
});
