import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { getCompanionStatus, setBlockedApps } from '../api/companion';
import { MutationError } from './MutationError';

export function AppBlocklist({ childId }: { childId: number }) {
  const queryClient = useQueryClient();
  const statusQuery = useQuery({
    queryKey: ['companion', 'status', childId],
    queryFn: () => getCompanionStatus(childId),
  });
  const [selected, setSelected] = useState<Set<string>>(new Set());

  useEffect(() => {
    if (statusQuery.data) setSelected(new Set(statusQuery.data.blockedApps));
  }, [statusQuery.data]);

  const save = useMutation({
    mutationFn: () => setBlockedApps(childId, Array.from(selected)),
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ['companion', 'status', childId] }),
  });

  const apps = statusQuery.data?.installedApps ?? [];
  if (statusQuery.data && statusQuery.data.status !== 'active') return null;

  function toggle(pkg: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(pkg)) next.delete(pkg);
      else next.add(pkg);
      return next;
    });
  }

  return (
    <div className="rounded-xl border border-outline-variant bg-surface-container-low p-4">
      <h4 className="font-display text-title-md text-on-surface">Apps bloqueados</h4>
      {statusQuery.data && !statusQuery.data.accessibilityEnabled && (
        <p role="alert" className="mt-2 rounded-lg bg-error/10 p-3 text-label-sm text-error">
          Requer Acessibilidade ativa no aparelho pra o bloqueio por-app funcionar. No
          GuardKids Companion do aparelho, toque em "Ativar bloqueio (Acessibilidade)".
        </p>
      )}
      {apps.length === 0 ? (
        <p className="mt-2 text-label-sm text-on-surface-variant">
          Aguardando o aparelho reportar os apps instalados (sincroniza periodicamente).
        </p>
      ) : (
        <>
          <ul className="mt-3 max-h-64 space-y-1 overflow-y-auto">
            {apps.map((a) => (
              <li key={a.packageName}>
                <label className="flex items-center gap-3 rounded-lg px-2 py-2 hover:bg-surface-variant/50">
                  <input
                    type="checkbox"
                    checked={selected.has(a.packageName)}
                    onChange={() => toggle(a.packageName)}
                    aria-label={a.label}
                  />
                  <span className="flex-1">
                    <span className="block text-label-md text-on-surface">{a.label}</span>
                    <span className="block text-label-sm text-on-surface-variant">
                      {a.packageName}
                    </span>
                  </span>
                </label>
              </li>
            ))}
          </ul>
          <button
            type="button"
            onClick={() => save.mutate()}
            disabled={save.isPending}
            className="mt-3 inline-flex w-full items-center justify-center rounded-xl bg-primary py-3 text-label-md font-semibold text-white shadow-ambient hover:bg-primary-container disabled:opacity-60"
          >
            {save.isPending ? 'Salvando…' : 'Salvar bloqueios'}
          </button>
          {save.isError && <MutationError error={save.error} prefix="Falha ao salvar" />}
        </>
      )}
    </div>
  );
}
