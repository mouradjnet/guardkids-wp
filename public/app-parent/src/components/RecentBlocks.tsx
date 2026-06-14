import { useQuery } from '@tanstack/react-query';
import { getRecentBlocks, type BlockDetail, type RecentBlock } from '../api/reports';
import { ApiError } from '../api/client';
import { formatRelative } from '../lib/requestDisplay';
import { Icon } from './Icon';

const DETAIL_LABEL: Record<BlockDetail, string> = {
  bedtime: 'Hora de dormir',
  weekday: 'Dia bloqueado',
  limit: 'Limite de tempo',
};

const DETAIL_ICON: Record<BlockDetail, string> = {
  bedtime: 'bedtime',
  weekday: 'event_busy',
  limit: 'hourglass_bottom',
};

export function RecentBlocks() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['blocks', 'recent'],
    queryFn: () => getRecentBlocks(10),
    refetchInterval: 60_000,
  });

  return (
    <div className="relative overflow-hidden rounded-xl border border-outline-variant/30 bg-surface-container-low/40 p-5">
      <h3 className="relative z-10 mb-4 flex items-center gap-2 text-label-md font-bold text-on-surface">
        <Icon name="security_update_warning" className="text-on-surface-variant" />
        Bloqueios Recentes
      </h3>

      {isLoading && <Skeleton />}
      {error && <ErrorState error={error} />}
      {data && data.length === 0 && <EmptyState />}
      {data && data.length > 0 && <BlockList items={data} />}
    </div>
  );
}

function Skeleton() {
  return (
    <div className="relative z-10 space-y-2">
      {[0, 1, 2].map((i) => (
        <div
          key={i}
          className="h-14 animate-pulse rounded-lg bg-surface-container-high/40"
        />
      ))}
    </div>
  );
}

function ErrorState({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div
      role="alert"
      className="relative z-10 flex flex-col items-center gap-2 rounded-lg bg-error/10 py-4 text-center text-error"
    >
      <Icon name="error" className="text-2xl" />
      <p className="text-label-sm">{message}</p>
    </div>
  );
}

function EmptyState() {
  return (
    <div className="relative z-10 flex flex-col items-center gap-2 py-6 text-center text-on-surface-variant">
      <Icon name="shield" className="text-3xl" />
      <p className="text-label-md font-semibold">Nenhum bloqueio recente</p>
      <p className="text-label-sm">
        Quando alguma criança tentar usar o app durante bedtime ou dia bloqueado,
        o evento vai aparecer aqui.
      </p>
    </div>
  );
}

function BlockList({ items }: { items: RecentBlock[] }) {
  return (
    <ul className="relative z-10 space-y-2">
      {items.map((block) => (
        <li
          key={block.id}
          className="flex items-center gap-3 rounded-lg bg-surface-container/60 p-3"
        >
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-tertiary-container/40 text-tertiary-container">
            <Icon name={DETAIL_ICON[block.detail]} className="text-lg" filled />
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-label-md font-semibold text-on-surface">
              {block.childName || `Filho #${block.childId}`}
            </p>
            <p className="truncate text-label-sm text-on-surface-variant">
              {DETAIL_LABEL[block.detail]}
            </p>
          </div>
          <span className="shrink-0 text-label-sm text-on-surface-variant">
            {formatRelative(block.createdAt)}
          </span>
        </li>
      ))}
    </ul>
  );
}
