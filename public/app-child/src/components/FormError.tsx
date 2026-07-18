import { ApiError } from '../api/client';

/**
 * Erro de ação (mutation) visível pro filho. `role="alert"` faz o leitor de
 * tela anunciar e é por onde os testes provam que a falha não é silenciosa.
 */
export function FormError({ error }: { error: unknown }) {
  if (!error) return null;
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <p role="alert" className="mt-3 rounded-lg bg-error/10 p-2 text-label-sm text-error">
      {message}
    </p>
  );
}
