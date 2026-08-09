import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { AcademyContext } from '../academy/engine';

// Contexto controlável por teste — o engine (recommend) roda de verdade em cima
// dele, então testamos botão + engine juntos.
let mockContext: AcademyContext;
vi.mock('../academy/useAcademyContext', () => ({
  useAcademyContext: () => mockContext,
}));

const completeLessonMock = vi.fn();
const dismissLessonMock = vi.fn();
vi.mock('../api/academy', () => ({
  completeLesson: (id: string) => completeLessonMock(id),
  dismissLesson: (id: string) => dismissLessonMock(id),
}));

import { AcademyButton } from './AcademyButton';

function ctx(overrides: Partial<AcademyContext>): AcademyContext {
  return {
    screen: 'dashboard',
    family: {
      childrenCount: 0,
      hasPairedDevice: false,
      hasSiteRules: false,
      hasTimeLimits: false,
    },
    completedLessonIds: [],
    dismissed: [],
    ...overrides,
  };
}

function renderButton() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
  return render(<AcademyButton screen={mockContext.screen} />, { wrapper });
}

describe('AcademyButton', () => {
  beforeEach(() => {
    completeLessonMock.mockReset().mockResolvedValue({ completed: [], dismissed: [] });
    dismissLessonMock.mockReset().mockResolvedValue({ completed: [], dismissed: [] });
  });

  it('não renderiza nada quando não há recomendação para a tela', () => {
    mockContext = ctx({ screen: 'reports' });
    const { container } = renderButton();
    expect(container).toBeEmptyDOMElement();
  });

  it('mostra a pílula contextual quando há recomendação', () => {
    mockContext = ctx({ screen: 'dashboard' }); // 0 filhos -> Primeiros Passos
    renderButton();
    expect(screen.getByRole('button', { name: /Primeiros Passos/ })).toBeInTheDocument();
  });

  it('abre o painel e "Concluí" chama completeLesson com o lessonId certo', async () => {
    mockContext = ctx({ screen: 'dashboard' });
    renderButton();

    await userEvent.click(screen.getByRole('button', { name: /Primeiros Passos/ }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Concluí' }));
    await waitFor(() => expect(completeLessonMock).toHaveBeenCalledWith('primeiros-passos'));
  });

  it('"Agora não" chama dismissLesson', async () => {
    mockContext = ctx({ screen: 'children', family: {
      childrenCount: 1,
      hasPairedDevice: false,
      hasSiteRules: false,
      hasTimeLimits: false,
    } }); // -> dispositivo-filho
    renderButton();

    await userEvent.click(screen.getByRole('button', { name: /Proteja o primeiro aparelho/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Agora não' }));
    await waitFor(() => expect(dismissLessonMock).toHaveBeenCalledWith('dispositivo-filho'));
  });
});
