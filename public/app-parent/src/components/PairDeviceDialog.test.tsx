import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PairDeviceDialog } from './PairDeviceDialog';

const { pairMock } = vi.hoisted(() => ({ pairMock: vi.fn() }));
vi.mock('../api/children', () => ({
  listChildren: vi.fn().mockResolvedValue([]),
  createChild: vi.fn(),
  updateChild: vi.fn(),
  pairChildDevice: pairMock,
}));
import { ApiError } from '../api/client';

const TOKEN = 'a'.repeat(64);

function renderDialog(open = true, onClose = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  const utils = render(
    <PairDeviceDialog childId={7} childName="Lucas" open={open} onClose={onClose} />,
    { wrapper },
  );
  return { ...utils, onClose };
}

describe('PairDeviceDialog', () => {
  beforeEach(() => {
    pairMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('does not render when open is false', () => {
    renderDialog(false);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('renders label form first then shows token after submit', async () => {
    pairMock.mockResolvedValue({
      token: TOKEN,
      childId: 7,
      label: 'Tablet do Lucas',
      createdAt: '2026-06-05T12:00:00Z',
      notice: 'Anote o token agora — ele não é exibido de novo.',
    });
    const user = userEvent.setup();
    renderDialog(true);

    expect(screen.getByText(/Gera um token único pro app-child do/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/nome do dispositivo/i)).toBeInTheDocument();

    const labelInput = screen.getByLabelText(/nome do dispositivo/i) as HTMLInputElement;
    await user.type(labelInput, 'Tablet do Lucas');
    fireEvent.submit(labelInput.closest('form')!);

    await waitFor(() => {
      expect(pairMock).toHaveBeenCalled();
      expect(pairMock.mock.calls[0]?.[0]).toBe(7);
      expect(pairMock.mock.calls[0]?.[1]).toBe('Tablet do Lucas');
    });

    expect(await screen.findByText(TOKEN)).toBeInTheDocument();
    expect(screen.getByText(/Anote o token agora/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /copiar/i })).toBeInTheDocument();
  });

  it('omits label arg when input is empty', async () => {
    pairMock.mockResolvedValue({
      token: TOKEN,
      childId: 7,
      label: null,
      createdAt: '2026-06-05T12:00:00Z',
      notice: 'ok',
    });
    renderDialog(true);

    const form = screen.getByLabelText(/nome do dispositivo/i).closest('form')!;
    fireEvent.submit(form);

    await waitFor(() => expect(pairMock).toHaveBeenCalled());
    expect(pairMock.mock.calls[0]?.[0]).toBe(7);
    expect(pairMock.mock.calls[0]?.[1]).toBeUndefined();
  });

  it('shows ApiError when pair fails', async () => {
    pairMock.mockRejectedValue(new ApiError('not_found', 'Filho não encontrado.', 404));
    renderDialog(true);

    const form = screen.getByLabelText(/nome do dispositivo/i).closest('form')!;
    fireEvent.submit(form);

    expect(await screen.findByRole('alert')).toHaveTextContent('Filho não encontrado. (404)');
    // Continua mostrando o form, não o token
    expect(screen.queryByRole('button', { name: /copiar/i })).toBeNull();
  });

  it('copy button switches to "Copiado" label after click', async () => {
    pairMock.mockResolvedValue({
      token: TOKEN,
      childId: 7,
      label: null,
      createdAt: '2026-06-05T12:00:00Z',
      notice: 'ok',
    });
    // Stub execCommand pra cobrir o fallback caso clipboard reject
    document.execCommand = vi.fn().mockReturnValue(true) as unknown as typeof document.execCommand;

    const user = userEvent.setup();
    renderDialog(true);
    fireEvent.submit(screen.getByLabelText(/nome do dispositivo/i).closest('form')!);

    const copyBtn = await screen.findByRole('button', { name: /copiar/i });
    await user.click(copyBtn);

    // Behavioral assertion: o estado visual passa pra "Copiado" independente da
    // rota (clipboard real ou fallback execCommand).
    expect(await screen.findByRole('button', { name: /copiado/i })).toBeInTheDocument();
  });
});
