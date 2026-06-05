import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { listChildren } from '../api/children';
import { ApiError } from '../api/client';
import { approveRequest, denyRequest, listRequests } from '../api/requests';
import type { ApprovalRequest, Child } from '../api/types';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { accentFor, childBadge, formatRelative } from '../lib/requestDisplay';

type Tab = 'pending' | 'history';
type Decision = 'approve' | 'deny';

export function Approvals() {
  const [tab, setTab] = useState<Tab>('pending');
  const [filterChildId, setFilterChildId] = useState<'all' | number>('all');

  const childrenQuery = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const requestsQuery = useQuery({
    queryKey: ['requests', 'all'],
    queryFn: () => listRequests('all'),
  });

  const queryClient = useQueryClient();
  const decide = useMutation({
    mutationFn: ({ id, action }: { id: number; action: Decision }) =>
      action === 'approve' ? approveRequest(id) : denyRequest(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['requests'] });
    },
  });

  const { pending, history } = useMemo(() => {
    const items = requestsQuery.data ?? [];
    const matches = (req: ApprovalRequest) =>
      filterChildId === 'all' || req.childId === filterChildId;
    return {
      pending: items.filter((r) => r.status === 'pending' && matches(r)),
      history: items.filter((r) => r.status !== 'pending' && matches(r)),
    };
  }, [requestsQuery.data, filterChildId]);

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Aprovações"
        subtitle="Revise pedidos das crianças e veja o que foi decidido antes."
        action={
          <ChildFilter
            value={filterChildId}
            onChange={setFilterChildId}
            children={childrenQuery.data}
          />
        }
      />

      <div className="glass-panel inline-flex w-fit gap-1 rounded-full p-1 shadow-ambient">
        <TabButton
          active={tab === 'pending'}
          onClick={() => setTab('pending')}
          label="Pendentes"
          badge={pending.length}
        />
        <TabButton
          active={tab === 'history'}
          onClick={() => setTab('history')}
          label="Histórico"
        />
      </div>

      {requestsQuery.isLoading && <ListSkeleton />}
      {requestsQuery.error && <ListError error={requestsQuery.error} />}
      {requestsQuery.data && tab === 'pending' && (
        <PendingList
          items={pending}
          children={childrenQuery.data}
          onDecide={(id, action) => decide.mutate({ id, action })}
          mutatingId={decide.isPending ? decide.variables?.id ?? null : null}
        />
      )}
      {requestsQuery.data && tab === 'history' && (
        <HistoryList items={history} children={childrenQuery.data} />
      )}
    </main>
  );
}

function ChildFilter({
  value,
  onChange,
  children,
}: {
  value: 'all' | number;
  onChange: (v: 'all' | number) => void;
  children: Child[] | undefined;
}) {
  return (
    <div className="relative">
      <Icon
        name="filter_alt"
        className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant"
      />
      <select
        value={value === 'all' ? 'all' : String(value)}
        onChange={(e) => onChange(e.target.value === 'all' ? 'all' : Number(e.target.value))}
        className="appearance-none rounded-full border border-outline-variant bg-white py-2.5 pl-10 pr-10 text-label-md font-semibold text-on-surface shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
      >
        <option value="all">Todos os filhos</option>
        {(children ?? []).map((c) => (
          <option key={c.id} value={c.id}>
            {c.name}
          </option>
        ))}
      </select>
      <Icon
        name="expand_more"
        className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant"
      />
    </div>
  );
}

function TabButton({
  active,
  onClick,
  label,
  badge,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  badge?: number;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        active
          ? 'flex items-center gap-2 rounded-full bg-primary px-5 py-2 text-label-md font-semibold text-white shadow-sm'
          : 'flex items-center gap-2 rounded-full px-5 py-2 text-label-md font-semibold text-on-surface-variant transition-colors hover:bg-surface-container'
      }
    >
      {label}
      {badge ? (
        <span
          className={`rounded-full px-2 py-0.5 text-xs font-bold ${
            active ? 'bg-white/20 text-white' : 'bg-error text-on-error'
          }`}
        >
          {badge}
        </span>
      ) : null}
    </button>
  );
}

function PendingList({
  items,
  children,
  onDecide,
  mutatingId,
}: {
  items: ApprovalRequest[];
  children: Child[] | undefined;
  onDecide: (id: number, action: Decision) => void;
  mutatingId: number | null;
}) {
  if (items.length === 0) {
    return <EmptyState icon="task_alt" title="Nada por aqui!" subtitle="Sem pedidos pendentes." />;
  }
  return (
    <div className="grid grid-cols-1 gap-gutter lg:grid-cols-2">
      {items.map((req) => {
        const accent = accentFor(req.kind);
        const accentBorder =
          accent === 'tertiary' ? 'border-l-tertiary-container' : 'border-l-primary';
        const highlightText =
          accent === 'tertiary' ? 'text-tertiary-container' : 'text-primary';
        const badge = childBadge(req, children);
        const busy = mutatingId === req.id;

        return (
          <article
            key={req.id}
            className={`glass-panel rounded-2xl border-l-4 p-5 shadow-ambient transition-shadow hover:shadow-md ${accentBorder}`}
          >
            <div className="flex items-center gap-3">
              <ChildPic name={badge.name} url={badge.avatarUrl} className="h-12 w-12 text-base" />
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

            <div className="mt-4 flex gap-2">
              <button
                type="button"
                disabled={busy}
                onClick={() => onDecide(req.id, 'approve')}
                className="flex flex-1 items-center justify-center gap-1 rounded-lg bg-secondary py-2.5 text-label-md font-semibold text-white transition-colors hover:bg-secondary/90 disabled:opacity-60"
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
                onClick={() => onDecide(req.id, 'deny')}
                className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline bg-transparent py-2.5 text-label-md font-semibold text-on-surface transition-colors hover:bg-surface-variant disabled:opacity-60"
              >
                <Icon name="close" className="text-sm" />
                Negar
              </button>
            </div>
          </article>
        );
      })}
    </div>
  );
}

function HistoryList({
  items,
  children,
}: {
  items: ApprovalRequest[];
  children: Child[] | undefined;
}) {
  if (items.length === 0) {
    return (
      <EmptyState
        icon="history"
        title="Sem histórico"
        subtitle="Quando aprovar ou negar pedidos, eles aparecem aqui."
      />
    );
  }
  return (
    <div className="glass-panel rounded-2xl shadow-ambient">
      <ul className="divide-y divide-outline-variant/50">
        {items.map((h) => {
          const approved = h.status === 'approved';
          const badge = childBadge(h, children);
          return (
            <li key={h.id} className="flex items-center gap-4 p-4">
              <ChildPic name={badge.name} url={badge.avatarUrl} className="h-11 w-11 text-base" />
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <span className="text-label-md font-semibold text-on-surface">{badge.name}</span>
                  <span
                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-label-sm font-semibold ${
                      approved
                        ? 'bg-secondary-container/40 text-secondary'
                        : 'bg-error-container/60 text-on-error-container'
                    }`}
                  >
                    <Icon
                      name={approved ? 'check_circle' : 'cancel'}
                      className="text-sm"
                      filled
                    />
                    {approved ? 'Aprovado' : 'Negado'}
                  </span>
                </div>
                <div className="mt-0.5 text-label-md text-on-surface">
                  {h.description ?? h.kind}
                </div>
                {h.reason ? (
                  <div className="text-label-sm text-on-surface-variant">{h.reason}</div>
                ) : null}
              </div>
              <span className="text-label-sm text-on-surface-variant">
                {formatRelative(h.decidedAt)}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function ChildPic({
  name,
  url,
  className,
}: {
  name: string;
  url: string | null;
  className: string;
}) {
  if (url) {
    return <img src={url} alt={name} className={`${className} rounded-full object-cover`} />;
  }
  return (
    <div
      className={`${className} flex items-center justify-center rounded-full bg-surface-container font-display font-semibold text-on-surface-variant`}
    >
      {name.charAt(0).toUpperCase()}
    </div>
  );
}

function ListSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-gutter lg:grid-cols-2">
      {[0, 1].map((i) => (
        <div key={i} className="glass-panel h-32 animate-pulse rounded-2xl bg-surface-container-low" />
      ))}
    </div>
  );
}

function ListError({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar pedidos</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}

function EmptyState({
  icon,
  title,
  subtitle,
}: {
  icon: string;
  title: string;
  subtitle: string;
}) {
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-3 rounded-2xl p-12 text-center shadow-ambient">
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-high text-primary">
        <Icon name={icon} className="text-3xl" />
      </div>
      <h3 className="font-display text-headline-md text-on-surface">{title}</h3>
      <p className="text-body-md text-on-surface-variant">{subtitle}</p>
    </div>
  );
}
