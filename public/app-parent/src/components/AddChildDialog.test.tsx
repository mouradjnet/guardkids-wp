import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AddChildDialog } from './AddChildDialog';

const { createChildMock } = vi.hoisted(() => ({
  createChildMock: vi.fn(),
}));
vi.mock('../api/children', () => ({
  listChildren: vi.fn().mockResolvedValue([]),
  createChild: createChildMock,
  updateChild: vi.fn(),
  pairChildDevice: vi.fn(),
}));
import { ApiError } from '../api/client';

function renderDialog(open = true, onClose = vi.fn()) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  const utils = render(<AddChildDialog open={open} onClose={onClose} />, { wrapper });
  return { ...utils, client, onClose };
}

describe('AddChildDialog', () => {
  beforeEach(() => {
    createChildMock.mockReset();
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('does not render when open is false', () => {
    renderDialog(false);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('renders form when open', () => {
    renderDialog(true);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByLabelText(/nome \*/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /adicionar/i })).toBeDisabled();
  });

  it('enables submit when name is filled', async () => {
    const user = userEvent.setup();
    renderDialog(true);

    await user.type(screen.getByLabelText(/nome \*/i), 'Lucas');

    expect(screen.getByRole('button', { name: /adicionar/i })).toBeEnabled();
  });

  it('calls createChild with parsed values on submit', async () => {
    createChildMock.mockResolvedValue({
      id: 10,
      slug: 'lucas',
      name: 'Lucas',
      age: 9,
      avatarUrl: null,
      device: 'Tablet do Lucas',
      status: 'offline',
      usedMinutes: 0,
      limitMinutes: 90,
      createdAt: null,
      updatedAt: null,
    });
    const user = userEvent.setup();
    const onClose = vi.fn();
    renderDialog(true, onClose);

    const nameInput = screen.getByLabelText(/nome \*/i) as HTMLInputElement;
    const ageInput = screen.getByLabelText(/idade/i) as HTMLInputElement;
    const limitInput = screen.getByLabelText(/limite di[áa]rio/i) as HTMLInputElement;
    const deviceInput = screen.getByLabelText(/^dispositivo$/i) as HTMLInputElement;

    await user.type(nameInput, 'Lucas');
    await user.type(ageInput, '9');
    await user.clear(limitInput);
    await user.type(limitInput, '90');
    await user.type(deviceInput, 'Tablet do Lucas');

    // sanity check do estado dos inputs antes de submeter
    expect(nameInput.value).toBe('Lucas');
    expect(ageInput.value).toBe('9');
    expect(limitInput.value).toBe('90');
    expect(deviceInput.value).toBe('Tablet do Lucas');

    fireEvent.submit(nameInput.closest('form')!);

    await waitFor(() => {
      expect(createChildMock).toHaveBeenCalled();
      expect(createChildMock.mock.calls[0]?.[0]).toEqual({
        name: 'Lucas',
        age: 9,
        device: 'Tablet do Lucas',
        limit_minutes: 90,
      });
    });
    await waitFor(() => expect(onClose).toHaveBeenCalled());
  });

  it('sends null for empty optional fields', async () => {
    createChildMock.mockResolvedValue({
      id: 1,
      slug: '',
      name: '',
      age: null,
      avatarUrl: null,
      device: null,
      status: 'offline',
      usedMinutes: 0,
      limitMinutes: 60,
      createdAt: null,
      updatedAt: null,
    });
    const user = userEvent.setup();
    renderDialog(true);

    const nameInput = screen.getByLabelText(/nome \*/i) as HTMLInputElement;
    await user.type(nameInput, 'Paloma');
    fireEvent.submit(nameInput.closest('form')!);

    await waitFor(() => {
      expect(createChildMock).toHaveBeenCalled();
      expect(createChildMock.mock.calls[0]?.[0]).toEqual({
        name: 'Paloma',
        age: null,
        device: null,
        limit_minutes: 60,
      });
    });
  });

  it('shows ApiError message + status when mutation fails', async () => {
    createChildMock.mockRejectedValue(
      new ApiError('invalid_payload', 'Slug duplicado.', 422),
    );
    const user = userEvent.setup();
    renderDialog(true);

    const nameInput = screen.getByLabelText(/nome \*/i) as HTMLInputElement;
    await user.type(nameInput, 'Rafael');
    fireEvent.submit(nameInput.closest('form')!);

    expect(await screen.findByRole('alert')).toHaveTextContent('Slug duplicado. (422)');
  });
});
