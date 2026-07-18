import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Child } from '../api/types';
import { ApiError } from '../api/client';

const {
  listChildrenMock,
  createChildMock,
  updateChildMock,
  pauseChildMock,
  resumeChildMock,
  deleteChildMock,
  uploadAvatarMock,
  pairMock,
} = vi.hoisted(() => ({
  listChildrenMock: vi.fn(),
  createChildMock: vi.fn(),
  updateChildMock: vi.fn(),
  pauseChildMock: vi.fn(),
  resumeChildMock: vi.fn(),
  deleteChildMock: vi.fn(),
  uploadAvatarMock: vi.fn(),
  pairMock: vi.fn(),
}));
vi.mock('../api/children', () => ({
  listChildren: listChildrenMock,
  createChild: createChildMock,
  updateChild: updateChildMock,
  pauseChild: pauseChildMock,
  resumeChild: resumeChildMock,
  deleteChild: deleteChildMock,
  uploadAvatar: uploadAvatarMock,
  pairChildDevice: pairMock,
}));

import { Children } from './Children';

const lucas: Child = {
  id: 1,
  slug: 'lucas',
  name: 'Lucas',
  age: 9,
  avatarUrl: null,
  device: 'Tablet',
  status: 'online',
  usedMinutes: 30,
  limitMinutes: 60,
  dailyLimitEnabled: false,
  bedtimeEnabled: false,
  bedtimeStart: null,
  bedtimeEnd: null,
  allowedWeekdays: 'YYYYYYY',
  createdAt: null,
  updatedAt: null,
};

const paloma: Child = {
  ...lucas,
  id: 2,
  slug: 'paloma',
  name: 'Paloma',
  age: 6,
  status: 'offline',
  device: null,
};

const pausedKid: Child = {
  ...lucas,
  id: 3,
  slug: 'thiago',
  name: 'Thiago',
  status: 'paused',
};

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return render(<Children />, { wrapper });
}

describe('Children page', () => {
  beforeEach(() => {
    listChildrenMock.mockReset();
    createChildMock.mockReset();
    updateChildMock.mockReset();
    pauseChildMock.mockReset();
    resumeChildMock.mockReset();
    deleteChildMock.mockReset();
    uploadAvatarMock.mockReset();
    pairMock.mockReset();
  });

  it('shows loading state initially', () => {
    listChildrenMock.mockReturnValue(new Promise(() => {})); // never resolves
    renderPage();
    expect(screen.getByText(/carregando filhos/i)).toBeInTheDocument();
  });

  it('renders grid with cards for each child', async () => {
    listChildrenMock.mockResolvedValue([lucas, paloma]);
    renderPage();

    expect(await screen.findByText('Lucas')).toBeInTheDocument();
    expect(screen.getByText('Paloma')).toBeInTheDocument();
    expect(screen.getByText(/online agora/i)).toBeInTheDocument();
    expect(screen.getByText(/^offline$/i)).toBeInTheDocument();
  });

  it('renders error state when listChildren fails', async () => {
    listChildrenMock.mockRejectedValue(new Error('network'));
    renderPage();

    expect(await screen.findByText(/falha ao carregar/i)).toBeInTheDocument();
  });

  it('renders only AddChildCard when list is empty', async () => {
    listChildrenMock.mockResolvedValue([]);
    renderPage();

    expect(await screen.findAllByText(/adicionar filho/i)).not.toHaveLength(0);
  });

  it('opens add dialog from header button', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Lucas');
    const headerBtn = screen.getAllByRole('button', { name: /adicionar filho/i })[0];
    await user.click(headerBtn);

    expect(screen.getByRole('dialog', { name: /adicionar filho/i })).toBeInTheDocument();
  });

  it('opens pair dialog from card icon button', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Lucas');
    const pairBtn = screen.getByRole('button', { name: /parear dispositivo/i });
    await user.click(pairBtn);

    expect(screen.getByRole('dialog', { name: /parear dispositivo/i })).toBeInTheDocument();
  });

  it('shows fallback initial when avatarUrl is null', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    renderPage();

    await screen.findByText('Lucas');
    expect(screen.getByText('L', { selector: 'div' })).toBeInTheDocument();
  });

  it('renders age + device when both available', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    renderPage();

    expect(await screen.findByText(/9 anos.*tablet/i)).toBeInTheDocument();
  });

  it('shows "Idade não informada" when age is null', async () => {
    listChildrenMock.mockResolvedValue([{ ...lucas, age: null }]);
    renderPage();

    await waitFor(() => {
      expect(screen.getByText(/idade não informada/i)).toBeInTheDocument();
    });
  });

  it('opens edit dialog with child name pre-filled when clicking Editar button', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Lucas');
    const editBtn = screen.getByRole('button', { name: /^editar$/i });
    await user.click(editBtn);

    expect(screen.getByRole('dialog', { name: /editar lucas/i })).toBeInTheDocument();
    expect(screen.getByDisplayValue('Lucas')).toBeInTheDocument();
  });

  it('calls pauseChild when clicking Pausar', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    pauseChildMock.mockResolvedValue({ ...lucas, status: 'paused' });
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Lucas');
    const pauseBtn = screen.getByRole('button', { name: /^pausar$/i });
    await user.click(pauseBtn);

    await waitFor(() => expect(pauseChildMock).toHaveBeenCalledWith(1));
  });

  it('shows Retomar and calls resumeChild when child is paused', async () => {
    listChildrenMock.mockResolvedValue([pausedKid]);
    resumeChildMock.mockResolvedValue({ ...pausedKid, status: 'offline' });
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Thiago');
    expect(screen.getByText(/^pausado$/i)).toBeInTheDocument();
    const resumeBtn = screen.getByRole('button', { name: /^retomar$/i });
    await user.click(resumeBtn);

    await waitFor(() => expect(resumeChildMock).toHaveBeenCalledWith(3));
    expect(pauseChildMock).not.toHaveBeenCalled();
  });

  it('opens the 3-dots menu with 4 items', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Lucas');
    const moreBtn = screen.getByRole('button', { name: /mais ações/i });
    await user.click(moreBtn);

    const menu = screen.getByRole('menu');
    const items = menu.querySelectorAll('[role="menuitem"]');
    expect(items).toHaveLength(4);
  });

  it('confirms before deleting and calls deleteChild', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    deleteChildMock.mockResolvedValue({ deleted: true, id: 1 });
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderPage();

    await screen.findByText('Lucas');
    await user.click(screen.getByRole('button', { name: /mais ações/i }));
    await user.click(screen.getByRole('menuitem', { name: /excluir/i }));

    expect(confirmSpy).toHaveBeenCalled();
    await waitFor(() => expect(deleteChildMock).toHaveBeenCalledWith(1));
    confirmSpy.mockRestore();
  });

  it('surfaces an error when deleteChild fails', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    deleteChildMock.mockRejectedValue(
      new ApiError('rest_cookie_invalid_nonce', 'Cookie nonce inválido', 403),
    );
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
    renderPage();

    await screen.findByText('Lucas');
    await user.click(screen.getByRole('button', { name: /mais ações/i }));
    await user.click(screen.getByRole('menuitem', { name: /excluir/i }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/falha ao excluir/i);
    expect(alert).toHaveTextContent(/403/);
    confirmSpy.mockRestore();
  });

  it('does not call deleteChild when confirm is canceled', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    const user = userEvent.setup();
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
    renderPage();

    await screen.findByText('Lucas');
    await user.click(screen.getByRole('button', { name: /mais ações/i }));
    await user.click(screen.getByRole('menuitem', { name: /excluir/i }));

    expect(confirmSpy).toHaveBeenCalled();
    expect(deleteChildMock).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it('uploads avatar and updates child when avatar button is clicked', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    uploadAvatarMock.mockResolvedValue('https://cdn/example.jpg');
    updateChildMock.mockResolvedValue({ ...lucas, avatarUrl: 'https://cdn/example.jpg' });
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Lucas');
    const avatarBtn = screen.getByRole('button', { name: /trocar foto/i });
    const input = avatarBtn.parentElement?.querySelector('input[type="file"]') as HTMLInputElement;
    expect(input).toBeTruthy();
    const file = new File(['x'], 'avatar.png', { type: 'image/png' });
    await user.upload(input, file);

    await waitFor(() => expect(uploadAvatarMock).toHaveBeenCalledWith(file));
    await waitFor(() =>
      expect(updateChildMock).toHaveBeenCalledWith(1, { avatar_url: 'https://cdn/example.jpg' }),
    );
  });

  it('mostra erro visível quando pausar o filho falha (não some mudo)', async () => {
    listChildrenMock.mockResolvedValue([lucas]);
    pauseChildMock.mockRejectedValue(new Error('rede caiu'));
    const user = userEvent.setup();
    renderPage();

    await screen.findByText('Lucas');
    await user.click(screen.getByRole('button', { name: /pausar/i }));

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent(/falha ao pausar/i);
    expect(alert).toHaveTextContent(/rede caiu/i);
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });
});
