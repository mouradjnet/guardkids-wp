import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Icon } from './Icon';
import { MutationError } from './MutationError';
import { destroyOtherSessions, listSessions } from '../api/sessions';

function formatAccess(ts: number): string {
  if (!ts) return '—';
  return new Date(ts * 1000).toLocaleString('pt-BR');
}

export function SessionsSection() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['sessions'], queryFn: listSessions });
  const destroy = useMutation({
    mutationFn: destroyOtherSessions,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['sessions'] }),
  });

  const sessions = data?.sessions ?? [];
  const hasOthers = sessions.some((s) => !s.current);

  return (
    <div className="rounded-xl border border-outline-variant bg-surface-container-low p-4">
      <div className="mb-2 flex items-center gap-2">
        <Icon name="devices" className="text-primary" />
        <h4 className="font-display text-label-md font-bold text-on-surface">Sessões ativas</h4>
      </div>

      {isLoading ? (
        <p className="text-label-sm text-on-surface-variant">Carregando…</p>
      ) : sessions.length === 0 ? (
        <p className="text-label-sm text-on-surface-variant">Nenhuma sessão ativa.</p>
      ) : (
        <ul className="flex flex-col gap-2">
          {sessions.map((s, i) => (
            <li
              key={i}
              className="flex items-center justify-between gap-3 rounded-lg bg-surface p-3"
            >
              <div>
                <p className="text-body-md text-on-surface">
                  {s.device}
                  {s.current ? (
                    <span className="ml-2 rounded bg-primary/10 px-2 py-0.5 text-label-sm text-primary">
                      Esta sessão
                    </span>
                  ) : null}
                </p>
                <p className="text-label-sm text-on-surface-variant">
                  {s.ip} · {formatAccess(s.lastAccess)}
                </p>
              </div>
            </li>
          ))}
        </ul>
      )}

      {hasOthers ? (
        <button
          type="button"
          className="mt-3 rounded-lg border border-error/40 bg-error/10 px-4 py-2 text-label-lg text-error"
          disabled={destroy.isPending}
          onClick={() => {
            if (
              window.confirm(
                'Encerrar todas as outras sessões? Os outros aparelhos precisarão logar de novo.',
              )
            ) {
              destroy.mutate();
            }
          }}
        >
          {destroy.isPending ? 'Encerrando…' : 'Sair de todos os outros aparelhos'}
        </button>
      ) : null}

      {destroy.error ? (
        <MutationError prefix="Falha ao encerrar sessões" error={destroy.error} />
      ) : null}
    </div>
  );
}
