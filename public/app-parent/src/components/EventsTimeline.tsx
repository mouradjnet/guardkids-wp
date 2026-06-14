import { useQuery } from '@tanstack/react-query';
import { listChildren } from '../api/children';
import { listRequests } from '../api/requests';
import { getRecentBlocks, type BlockDetail, type RecentBlock } from '../api/reports';
import type { ApprovalRequest } from '../api/types';
import { ApiError } from '../api/client';
import { formatRelative } from '../lib/requestDisplay';
import { Icon } from './Icon';

type TimelineEvent =
  | {
      kind: 'block';
      id: string;
      createdAt: string;
      childId: number;
      childName: string;
      detail: BlockDetail;
    }
  | {
      kind: 'approved' | 'denied';
      id: string;
      createdAt: string;
      childId: number;
      childName: string;
      summary: string;
    };

const BLOCK_LABEL: Record<BlockDetail, string> = {
  bedtime: 'Bloqueio por hora de dormir',
  weekday: 'Bloqueio por dia de pausa',
  limit: 'Bloqueio por limite de tempo',
};

const BLOCK_ICON: Record<BlockDetail, string> = {
  bedtime: 'bedtime',
  weekday: 'event_busy',
  limit: 'hourglass_bottom',
};

export function EventsTimeline() {
  const blocksQ = useQuery({
    queryKey: ['blocks', 'recent'],
    queryFn: () => getRecentBlocks(10),
    refetchInterval: 60_000,
  });
  const requestsQ = useQuery({
    queryKey: ['requests', 'all'],
    queryFn: () => listRequests('all'),
    refetchInterval: 60_000,
  });
  const childrenQ = useQuery({ queryKey: ['children'], queryFn: listChildren });

  const loading = blocksQ.isLoading || requestsQ.isLoading;
  const error = blocksQ.error ?? requestsQ.error ?? null;
  const events = mergeEvents(
    blocksQ.data ?? [],
    requestsQ.data ?? [],
    childrenQ.data ?? [],
  );

  return (
    <div className="relative overflow-hidden rounded-xl border border-outline-variant/30 bg-surface-container-low/40 p-5">
      <h3 className="relative z-10 mb-4 flex items-center gap-2 text-label-md font-bold text-on-surface">
        <Icon name="timeline" className="text-on-surface-variant" />
        Eventos recentes
      </h3>

      {loading && <Skeleton />}
      {error && !loading && <ErrorState error={error} />}
      {!loading && !error && events.length === 0 && <EmptyState />}
      {!loading && !error && events.length > 0 && <EventList items={events} />}
    </div>
  );
}

function Skeleton() {
  return (
    <div className="space-y-2">
      {[0, 1, 2].map((i) => (
        <div key={i} className="h-14 animate-pulse rounded-lg bg-surface-container-high/40" />
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
      className="flex flex-col items-center gap-2 rounded-lg bg-error/10 py-4 text-center text-error"
    >
      <Icon name="error" className="text-2xl" />
      <p className="text-label-sm">{message}</p>
    </div>
  );
}

function EmptyState() {
  return (
    <div className="flex flex-col items-center gap-2 py-6 text-center text-on-surface-variant">
      <Icon name="celebration" className="text-3xl text-secondary" filled />
      <p className="text-label-md font-semibold">Tudo tranquilo hoje 🎉</p>
      <p className="text-label-sm">
        Quando rolar bloqueio ou decisão de pedido, vai aparecer aqui.
      </p>
    </div>
  );
}

function EventList({ items }: { items: TimelineEvent[] }) {
  return (
    <ul className="space-y-2">
      {items.map((ev) => (
        <EventRow key={ev.id} event={ev} />
      ))}
    </ul>
  );
}

function EventRow({ event }: { event: TimelineEvent }) {
  const visual = visualFor(event);
  const title = event.childName || `Filho #${event.childId}`;
  const subtitle =
    event.kind === 'block' ? BLOCK_LABEL[event.detail] : event.summary;
  return (
    <li className="flex items-center gap-3 rounded-lg bg-surface-container/60 p-3">
      <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${visual.bg} ${visual.text}`}>
        <Icon name={visual.icon} className="text-lg" filled />
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-label-md font-semibold text-on-surface">{title}</p>
        <p className="truncate text-label-sm text-on-surface-variant">{subtitle}</p>
      </div>
      <span className="shrink-0 text-label-sm text-on-surface-variant">
        {formatRelative(event.createdAt)}
      </span>
    </li>
  );
}

function visualFor(ev: TimelineEvent): { icon: string; bg: string; text: string } {
  if (ev.kind === 'block') {
    return {
      icon: BLOCK_ICON[ev.detail],
      bg: 'bg-tertiary-container/40',
      text: 'text-tertiary-container',
    };
  }
  if (ev.kind === 'approved') {
    return { icon: 'check_circle', bg: 'bg-secondary-container/40', text: 'text-secondary' };
  }
  return { icon: 'cancel', bg: 'bg-error/10', text: 'text-error' };
}

type ChildLike = { id: number; name: string };

export function mergeEvents(
  blocks: RecentBlock[],
  requests: ApprovalRequest[],
  children: ChildLike[],
  limit = 10,
): TimelineEvent[] {
  const nameOf = (childId: number) =>
    children.find((c) => c.id === childId)?.name ?? '';

  const blockEvents: TimelineEvent[] = blocks.map((b) => ({
    kind: 'block',
    id: `block-${b.id}`,
    createdAt: b.createdAt,
    childId: b.childId,
    childName: b.childName || nameOf(b.childId),
    detail: b.detail,
  }));

  const requestEvents: TimelineEvent[] = requests
    .filter((r): r is ApprovalRequest & { status: 'approved' | 'denied' } =>
      r.status === 'approved' || r.status === 'denied',
    )
    .map((r) => ({
      kind: r.status,
      id: `req-${r.id}`,
      createdAt: r.decidedAt ?? r.createdAt ?? '',
      childId: r.childId,
      childName: nameOf(r.childId),
      summary: summaryFor(r),
    }));

  return [...blockEvents, ...requestEvents]
    .filter((e) => !!e.createdAt)
    .sort((a, b) => (b.createdAt > a.createdAt ? 1 : -1))
    .slice(0, limit);
}

function summaryFor(r: ApprovalRequest): string {
  const verb = r.status === 'approved' ? 'Aprovado' : 'Negado';
  const what = [r.description, r.highlight].filter(Boolean).join(' ');
  return `${verb}: ${what || r.kind}`.trim();
}
