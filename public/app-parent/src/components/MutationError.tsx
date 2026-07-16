import { ApiError } from '../api/client';

/**
 * Erro de mutation, visível pro usuário.
 *
 * Existia duplicado em Settings.tsx e SitesRules.tsx com assinaturas diferentes;
 * unificado aqui. `prefix` é opcional porque nem toda falha é "ao salvar" — uma
 * permissão negada no push, por exemplo, não é falha de save.
 *
 * O `role="alert"` não é decoração: é o que faz o leitor de tela anunciar, e é
 * por onde os testes provam que a falha não é silenciosa.
 */
export function MutationError({ error, prefix }: { error: unknown; prefix?: string }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'erro desconhecido';

  return (
    <p role="alert" className="mt-3 rounded-lg bg-error/10 p-2 text-label-sm text-error">
      {prefix ? `${prefix}: ${message}` : message}
    </p>
  );
}
