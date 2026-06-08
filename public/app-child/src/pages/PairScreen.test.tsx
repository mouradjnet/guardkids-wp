import { fireEvent, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import { renderWithClient } from '../test/queryClient';
import { PairScreen } from './PairScreen';

const validateToken = vi.fn();
vi.mock('../api/child', () => ({
  validateToken: (token: string) => validateToken(token),
}));

const VALID_TOKEN = 'a'.repeat(64);

describe('PairScreen', () => {
  afterEach(() => {
    validateToken.mockReset();
  });

  it('mantém o botão Conectar desabilitado enquanto o token está incompleto', () => {
    renderWithClient(<PairScreen onPaired={() => {}} />);
    const button = screen.getByRole('button', { name: /conectar/i });
    expect(button).toBeDisabled();
  });

  it('habilita Conectar quando o token tem 64 chars hex válidos', () => {
    renderWithClient(<PairScreen onPaired={() => {}} />);
    fireEvent.change(screen.getByLabelText(/token/i), {
      target: { value: VALID_TOKEN },
    });
    expect(screen.getByRole('button', { name: /conectar/i })).toBeEnabled();
  });

  it('chama onPaired com o token normalizado quando a validação dá certo', async () => {
    validateToken.mockResolvedValueOnce({ id: 1, name: 'Sam' });
    const onPaired = vi.fn();
    renderWithClient(<PairScreen onPaired={onPaired} />);

    fireEvent.change(screen.getByLabelText(/token/i), {
      target: { value: `  ${VALID_TOKEN.toUpperCase()}  ` },
    });
    fireEvent.click(screen.getByRole('button', { name: /conectar/i }));

    await waitFor(() => {
      expect(validateToken).toHaveBeenCalledWith(VALID_TOKEN);
      expect(onPaired).toHaveBeenCalledWith(VALID_TOKEN);
    });
  });

  it('exibe mensagem de erro quando o backend rejeita o token', async () => {
    validateToken.mockRejectedValueOnce(
      new ApiError('child_auth_required', 'Token inválido.', 401),
    );
    renderWithClient(<PairScreen onPaired={() => {}} />);

    fireEvent.change(screen.getByLabelText(/token/i), {
      target: { value: VALID_TOKEN },
    });
    fireEvent.click(screen.getByRole('button', { name: /conectar/i }));

    expect(
      await screen.findByText(/token rejeitado: token inválido/i),
    ).toBeInTheDocument();
  });

  it('não chama a API quando o token tem caracteres inválidos', () => {
    renderWithClient(<PairScreen onPaired={() => {}} />);
    fireEvent.change(screen.getByLabelText(/token/i), {
      target: { value: 'z'.repeat(64) }, // z não é hex
    });
    fireEvent.click(screen.getByRole('button', { name: /conectar/i }));
    expect(validateToken).not.toHaveBeenCalled();
  });
});
