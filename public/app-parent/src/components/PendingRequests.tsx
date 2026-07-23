import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { listChildren } from '../api/children';
import { approveRequest, denyRequest, listRequests } from '../api/requests';
import { accentFor, childBadge, formatRelative } from '../lib/requestDisplay';
import { Icon } from './Icon';
import { MutationError } from './MutationError';

type Decision = 'approve' | 'deny';

export function PendingRequests() {
  const childrenQuery = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const requestsQuery = useQuery({
    queryKey: ['requests', 'pending'],
    queryFn: () => listRequests('pending'),
    refetchInterval: 60_000,
  });

  const queryClient = useQueryClient();
  const decide = useMutation({
    mutationFn: ({ id, action }: { id: number; action: Decision }) =>
      action === 'approve' ? approveRequest(id) : denyRequest(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['requests'] });
    },
  });

  const items = requestsQuery.data ?? [];

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <h3 className="flex items-center gap-2 font-display text-headline-md text-on-surface">
          Solicitações Pendentes
          {items.length > 0 && (
            <span className="rounded-full bg-error px-2 py-0.5 text-xs font-bold text-on-error">
              {items.length}
            </span>
          )}
        </h3>
      </div>

      {requestsQuery.isLoading && (
        <div className="glass-panel h-24 animate-pulse rounded-xl bg-surface-container-low" />
      )}

      {requestsQuery.data && items.length === 0 && (
        <p className="rounded-xl border border-dashed border-outline-variant p-4 text-center text-label-sm text-on-surface-variant">
          Sem pedidos no momento.
        </p>
      )}

      <div className="space-y-3">
        {items.map((req) => {
          const accent = accentFor(req.kind);
          const accentBorder =
            accent === 'tertiary' ? 'border-l-tertiary-container' : 'border-l-primary';
          const highlightText =
            accent === 'tertiary' ? 'text-tertiary-container' : 'text-primary';
          const badge = childBadge(req, childrenQuery.data);
          const busy = decide.isPending && decide.variables?.id === req.id;

          return (
            <div
              key={req.id}
              className={`glass-panel rounded-xl border-l-4 p-4 transition-shadow hover:shadow-md ${accentBorder}`}
            >
              <div className="mb-3 flex items-center gap-3">
                {badge.avatarUrl ? (
                  <img
                    src={badge.avatarUrl}
                    alt={badge.name}
                    className="h-8 w-8 rounded-full object-cover"
                  />
                ) : (
                  <div className="flex h-8 w-8 items-center justify-center rounded-full bg-surface-container font-display text-label-sm font-semibold text-on-surface-variant">
                    {badge.name.charAt(0).toUpperCase()}
                  </div>
                )}
                <div className="flex-1">
                  <div className="text-label-md font-semibold text-on-surface">{badge.name}</div>
                  <div className="text-label-sm text-on-surface-variant">
                    {req.description ?? req.kind}
                    {req.highlight ? (
                      <>
                        {' '}
                        <span className={`font-bold ${highlightText}`}>{req.highlight}</span>
                      </>
                    ) : null}
                  </div>
                </div>
                <span className="text-label-sm text-on-surface-variant">
                  {formatRelative(req.createdAt)}
                </span>
              </div>
              <div className="flex gap-2">
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => decide.mutate({ id: req.id, action: 'approve' })}
                  className="flex flex-1 items-center justify-center gap-1 rounded-lg bg-secondary py-2 text-label-sm font-semibold text-white transition-colors hover:bg-secondary/90 disabled:opacity-60"
                >
                  <Icon
                    name={busy ? 'progress_activity' : 'check'}
                    className={`text-sm ${busy ? 'animate-spin' : ''}`}
                  />
                  Aprovar
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => decide.mutate({ id: req.id, action: 'deny' })}
                  className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline bg-transparent py-2 text-label-sm font-semibold text-on-surface transition-colors hover:bg-surface-variant disabled:opacity-60"
                >
                  <Icon name="close" className="text-sm" />
                  Negar
                </button>
              </div>
              {decide.isError && decide.variables?.id === req.id && (
                <MutationError error={decide.error} prefix="Falha ao decidir" />
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
