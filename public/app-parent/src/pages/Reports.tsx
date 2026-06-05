import { useState } from 'react';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import {
  children,
  dailyMinutesByDay,
  reportKpis,
  topSites,
  type KpiCard as KpiCardType,
  type TopSite,
} from '../data/mockData';

type Range = 'week' | 'month';

const childColors: Record<string, string> = {
  lucas: '#00236f',
  sofia: '#F59E0B',
  theo: '#006c49',
};

export function Reports() {
  const [range, setRange] = useState<Range>('week');

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Relatórios"
        subtitle="Veja onde a família passa tempo, em quais sites e quando."
        action={
          <div className="flex items-center gap-2">
            <RangeButton active={range === 'week'} onClick={() => setRange('week')} label="Semana" />
            <RangeButton active={range === 'month'} onClick={() => setRange('month')} label="Mês" />
            <button
              type="button"
              className="inline-flex items-center gap-2 rounded-full border border-outline-variant bg-white px-4 py-2 text-label-md font-semibold text-on-surface shadow-sm hover:bg-surface-variant"
            >
              <Icon name="download" className="text-sm" />
              Exportar
            </button>
          </div>
        }
      />

      <section className="grid grid-cols-2 gap-gutter md:grid-cols-4">
        {reportKpis.map((kpi) => (
          <KpiCard key={kpi.id} kpi={kpi} />
        ))}
      </section>

      <section className="glass-panel rounded-2xl p-6 shadow-ambient">
        <header className="mb-6 flex items-start justify-between gap-3">
          <div>
            <h3 className="font-display text-headline-md text-on-surface">
              Minutos por dia da semana
            </h3>
            <p className="text-label-sm text-on-surface-variant">
              Empilhado por filho — passe o mouse pra ver detalhes
            </p>
          </div>
          <div className="flex flex-wrap gap-3 text-label-sm">
            {children.map((c) => (
              <div key={c.id} className="flex items-center gap-2 text-on-surface">
                <span
                  className="inline-block h-3 w-3 rounded-sm"
                  style={{ background: childColors[c.id] }}
                />
                {c.name}
              </div>
            ))}
          </div>
        </header>
        <Chart />
      </section>

      <section className="glass-panel rounded-2xl p-6 shadow-ambient">
        <header className="mb-4 flex items-center justify-between">
          <div>
            <h3 className="font-display text-headline-md text-on-surface">Top sites visitados</h3>
            <p className="text-label-sm text-on-surface-variant">Últimos 7 dias</p>
          </div>
          <button
            type="button"
            className="flex items-center gap-1 text-label-md font-semibold text-primary hover:underline"
          >
            Ver tudo <Icon name="chevron_right" className="text-sm" />
          </button>
        </header>
        <ul className="divide-y divide-outline-variant/50">
          {topSites.map((s, idx) => (
            <TopSiteRow key={s.id} rank={idx + 1} site={s} max={topSites[0].totalMinutes} />
          ))}
        </ul>
      </section>

      <section className="grid grid-cols-1 gap-gutter md:grid-cols-3">
        {children.map((c) => (
          <ChildSummary key={c.id} childId={c.id} name={c.name} avatar={c.avatar} />
        ))}
      </section>
    </main>
  );
}

function RangeButton({
  active,
  onClick,
  label,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        active
          ? 'rounded-full bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-sm'
          : 'rounded-full px-4 py-2 text-label-md font-semibold text-on-surface-variant hover:bg-surface-container'
      }
    >
      {label}
    </button>
  );
}

function KpiCard({ kpi }: { kpi: KpiCardType }) {
  return (
    <article className="glass-panel rounded-2xl p-5 shadow-ambient">
      <div className="flex items-start justify-between">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-surface-container-high text-primary">
          <Icon name={kpi.icon} className="text-2xl" filled />
        </div>
        <span
          className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-label-sm font-semibold ${
            kpi.positive
              ? 'bg-secondary-container/40 text-secondary'
              : 'bg-error-container/60 text-on-error-container'
          }`}
        >
          <Icon name={kpi.positive ? 'trending_down' : 'trending_up'} className="text-sm" />
        </span>
      </div>
      <div className="mt-3 text-label-sm text-on-surface-variant">{kpi.label}</div>
      <div className="font-display text-headline-lg leading-none text-primary">{kpi.value}</div>
      <div
        className={`mt-1 text-label-sm font-semibold ${
          kpi.positive ? 'text-secondary' : 'text-on-error-container'
        }`}
      >
        {kpi.delta}
      </div>
    </article>
  );
}

function Chart() {
  const max = Math.max(
    ...dailyMinutesByDay.map((d) => d.lucas + d.sofia + d.theo),
  );
  const chartHeight = 220;

  return (
    <div className="flex h-[260px] gap-3">
      <div className="flex h-full flex-col justify-between pb-6 text-right text-[11px] text-on-surface-variant">
        <span>{Math.ceil(max / 60)}h</span>
        <span>{Math.ceil(max / 120)}h</span>
        <span>0</span>
      </div>
      <div className="flex flex-1 items-end gap-3 border-l border-b border-outline-variant pb-6">
        {dailyMinutesByDay.map((d) => {
          const lucasH = (d.lucas / max) * chartHeight;
          const sofiaH = (d.sofia / max) * chartHeight;
          const theoH = (d.theo / max) * chartHeight;
          const total = d.lucas + d.sofia + d.theo;
          return (
            <div key={d.day} className="group flex h-full flex-1 flex-col items-center justify-end gap-1">
              <div className="opacity-0 transition-opacity group-hover:opacity-100">
                <span className="rounded-md bg-on-surface px-2 py-0.5 text-[10px] font-bold text-white">
                  {Math.floor(total / 60)}h{total % 60 ? ` ${total % 60}m` : ''}
                </span>
              </div>
              <div className="flex w-full max-w-[36px] flex-col overflow-hidden rounded-t-lg shadow-sm">
                <div
                  style={{ height: `${theoH}px`, background: childColors.theo }}
                  className="w-full"
                />
                <div
                  style={{ height: `${sofiaH}px`, background: childColors.sofia }}
                  className="w-full"
                />
                <div
                  style={{ height: `${lucasH}px`, background: childColors.lucas }}
                  className="w-full"
                />
              </div>
              <span className="mt-1 text-label-sm font-semibold text-on-surface-variant">
                {d.day}
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function TopSiteRow({ rank, site, max }: { rank: number; site: TopSite; max: number }) {
  const pct = (site.totalMinutes / max) * 100;
  return (
    <li className="flex items-center gap-4 py-3">
      <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-surface-container-high font-display text-label-md font-bold text-primary">
        #{rank}
      </span>
      <div className="flex-1">
        <div className="flex items-center justify-between gap-2">
          <span className="text-label-md font-semibold text-on-surface">{site.domain}</span>
          <span className="text-label-sm text-on-surface-variant">
            {Math.floor(site.totalMinutes / 60)}h {site.totalMinutes % 60}min
          </span>
        </div>
        <div className="mt-1 text-label-sm text-on-surface-variant">
          {site.category} • mais usado por {site.topChild}
        </div>
        <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-surface-container">
          <div
            className="h-full rounded-full bg-primary"
            style={{ width: `${pct}%` }}
          />
        </div>
      </div>
    </li>
  );
}

function ChildSummary({
  childId,
  name,
  avatar,
}: {
  childId: string;
  name: string;
  avatar: string;
}) {
  const totals = dailyMinutesByDay.reduce(
    (acc, d) => acc + (d[childId as 'lucas' | 'sofia' | 'theo'] ?? 0),
    0,
  );
  const avgPerDay = Math.round(totals / 7);
  return (
    <article className="glass-panel rounded-2xl p-5 shadow-ambient">
      <header className="flex items-center gap-3">
        <img src={avatar} alt={name} className="h-11 w-11 rounded-full object-cover" />
        <div>
          <h3 className="font-display text-headline-md text-on-surface">{name}</h3>
          <p className="text-label-sm text-on-surface-variant">Resumo semanal</p>
        </div>
      </header>
      <div className="mt-4 grid grid-cols-2 gap-3 text-center">
        <div className="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-3">
          <div className="text-label-sm text-on-surface-variant">Total semana</div>
          <div className="font-display text-headline-md text-primary">
            {Math.floor(totals / 60)}h {totals % 60}m
          </div>
        </div>
        <div className="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-3">
          <div className="text-label-sm text-on-surface-variant">Média/dia</div>
          <div className="font-display text-headline-md text-primary">
            {Math.floor(avgPerDay / 60)}h {avgPerDay % 60}m
          </div>
        </div>
      </div>
    </article>
  );
}
