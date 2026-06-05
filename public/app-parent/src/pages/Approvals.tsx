import { useState } from 'react';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import {
  approvalHistory,
  pendingRequests,
  type ApprovalHistory,
  type PendingRequest,
} from '../data/mockData';

type Tab = 'pending' | 'history';

export function Approvals() {
  const [tab, setTab] = useState<Tab>('pending');
  const [filterChild, setFilterChild] = useState<string>('all');

  const childrenInData = Array.from(
    new Set([
      ...pendingRequests.map((r) => r.childName),
      ...approvalHistory.map((h) => h.childName),
    ]),
  );

  const filterMatch = (name: string) =>
    filterChild === 'all' || filterChild === name;

  const pending = pendingRequests.filter((r) => filterMatch(r.childName));
  const history = approvalHistory.filter((r) => filterMatch(r.childName));

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Aprovações"
        subtitle="Revise pedidos das crianças e veja o que foi decidido antes."
        action={
          <div className="relative">
            <Icon
              name="filter_alt"
              className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant"
            />
            <select
              value={filterChild}
              onChange={(e) => setFilterChild(e.target.value)}
              className="appearance-none rounded-full border border-outline-variant bg-white py-2.5 pl-10 pr-10 text-label-md font-semibold text-on-surface shadow-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
            >
              <option value="all">Todos os filhos</option>
              {childrenInData.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
            <Icon
              name="expand_more"
              className="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant"
            />
          </div>
        }
      />

      <div className="glass-panel inline-flex w-fit gap-1 rounded-full p-1 shadow-ambient">
        <TabButton
          active={tab === 'pending'}
          onClick={() => setTab('pending')}
          label="Pendentes"
          badge={pendingRequests.length}
        />
        <TabButton
          active={tab === 'history'}
          onClick={() => setTab('history')}
          label="Histórico"
        />
      </div>

      {tab === 'pending' ? (
        <PendingList items={pending} />
      ) : (
        <HistoryList items={history} />
      )}
    </main>
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

function PendingList({ items }: { items: PendingRequest[] }) {
  if (items.length === 0) {
    return <EmptyState icon="task_alt" title="Nada por aqui!" subtitle="Sem pedidos pendentes." />;
  }
  return (
    <div className="grid grid-cols-1 gap-gutter lg:grid-cols-2">
      {items.map((req) => {
        const accentBorder =
          req.accent === 'tertiary' ? 'border-l-tertiary-container' : 'border-l-primary';
        const highlightText =
          req.accent === 'tertiary' ? 'text-tertiary-container' : 'text-primary';
        return (
          <article
            key={req.id}
            className={`glass-panel rounded-2xl border-l-4 p-5 shadow-ambient transition-shadow hover:shadow-md ${accentBorder}`}
          >
            <div className="flex items-center gap-3">
              <img
                src={req.childAvatar}
                alt={req.childName}
                className="h-12 w-12 rounded-full object-cover"
              />
              <div className="flex-1">
                <div className="text-label-md font-semibold text-on-surface">
                  {req.childName}
                </div>
                <div className="text-label-sm text-on-surface-variant">
                  {req.description}{' '}
                  <span className={`font-bold ${highlightText}`}>{req.highlight}</span>
                </div>
              </div>
              <span className="text-label-sm text-on-surface-variant">
                {req.requestedAtLabel}
              </span>
            </div>

            <div className="mt-4 flex gap-2">
              <button
                type="button"
                className="flex flex-1 items-center justify-center gap-1 rounded-lg bg-secondary py-2.5 text-label-md font-semibold text-white transition-colors hover:bg-secondary/90"
              >
                <Icon name="check" className="text-sm" />
                Aprovar
              </button>
              <button
                type="button"
                className="flex items-center justify-center gap-1 rounded-lg border border-outline px-4 py-2.5 text-label-md font-semibold text-on-surface transition-colors hover:bg-surface-variant"
              >
                <Icon name="schedule" className="text-sm" />
                Aprovar por 15min
              </button>
              <button
                type="button"
                className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline bg-transparent py-2.5 text-label-md font-semibold text-on-surface transition-colors hover:bg-surface-variant"
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

function HistoryList({ items }: { items: ApprovalHistory[] }) {
  if (items.length === 0) {
    return <EmptyState icon="history" title="Sem histórico" subtitle="Quando aprovar ou negar pedidos, eles aparecem aqui." />;
  }
  return (
    <div className="glass-panel rounded-2xl shadow-ambient">
      <ul className="divide-y divide-outline-variant/50">
        {items.map((h) => {
          const approved = h.decision === 'approved';
          return (
            <li key={h.id} className="flex items-center gap-4 p-4">
              <img
                src={h.childAvatar}
                alt={h.childName}
                className="h-11 w-11 rounded-full object-cover"
              />
              <div className="flex-1">
                <div className="flex items-center gap-2">
                  <span className="text-label-md font-semibold text-on-surface">
                    {h.childName}
                  </span>
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
                <div className="mt-0.5 text-label-md text-on-surface">{h.summary}</div>
                <div className="text-label-sm text-on-surface-variant">{h.detail}</div>
              </div>
              <span className="text-label-sm text-on-surface-variant">
                {h.decidedAtLabel}
              </span>
            </li>
          );
        })}
      </ul>
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
