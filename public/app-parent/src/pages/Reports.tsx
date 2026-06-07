import { useQueries } from '@tanstack/react-query';
import { useState } from 'react';
import { listChildren } from '../api/children';
import { ApiError } from '../api/client';
import { getReport, type Report, type ReportPerChild, type ReportRange, type ReportTopSite } from '../api/reports';
import type { Child } from '../api/types';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { downloadReportCsv } from '../lib/exportReportCsv';

const CHILD_COLORS = ['#00236f', '#F59E0B', '#006c49', '#6B46C1'] as const;

function colorAt(index: number): string {
  return CHILD_COLORS[index % CHILD_COLORS.length];
}

export function Reports() {
  const [range, setRange] = useState<ReportRange>('week');
  const [childId, setChildId] = useState<number>(0);

  const queries = useQueries({
    queries: [
      { queryKey: ['reports', range, childId], queryFn: () => getReport(range, childId) },
      { queryKey: ['children'], queryFn: listChildren },
    ],
  });
  const reportQuery = queries[0];
  const childrenQuery = queries[1];

  const report = reportQuery.data;
  const children = childrenQuery.data ?? [];
  const childIndex = new Map(report?.perChild.map((c, i) => [c.childId, i]));

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Relatórios"
        subtitle="Veja onde a família passa tempo e quando."
        action={
          <div className="flex flex-wrap items-center gap-2">
            <RangeButton active={range === 'week'} onClick={() => setRange('week')} label="Semana" />
            <RangeButton active={range === 'month'} onClick={() => setRange('month')} label="Mês" />
            <select
              value={childId}
              onChange={(e) => setChildId(Number(e.target.value))}
              aria-label="Filtrar por filho"
              className="rounded-full border border-outline-variant bg-white px-4 py-2 text-label-md font-semibold text-on-surface-variant shadow-sm hover:bg-surface-container"
            >
              <option value={0}>Todos os filhos</option>
              {children.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
            <button
              type="button"
              disabled={!report || report.dailyByChild.length === 0}
              onClick={report ? () => downloadReportCsv(report) : undefined}
              className="inline-flex items-center gap-2 rounded-full border border-outline-variant bg-white px-4 py-2 text-label-md font-semibold text-on-surface shadow-sm hover:bg-surface-variant disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-white"
            >
              <Icon name="download" className="text-sm" />
              Exportar
            </button>
          </div>
        }
      />

      {reportQuery.isLoading && <LoadingSkeleton />}
      {reportQuery.error ? <LoadError error={reportQuery.error} /> : null}

      {report && report.dailyByChild.length === 0 && (
        <EmptyState />
      )}

      {report && report.dailyByChild.length > 0 && (
        <>
          <Kpis kpis={report.kpis} />
          <ChartSection report={report} colorFor={(id) => colorAt(childIndex.get(id) ?? 0)} />
          <TopSitesSection sites={report.topSites} perChild={report.perChild} />
          <PerChildSection perChild={report.perChild} children={children} />
        </>
      )}
    </main>
  );
}

function RangeButton({ active, onClick, label }: { active: boolean; onClick: () => void; label: string }) {
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

function Kpis({ kpis }: { kpis: Report['kpis'] }) {
  const pctLimit = kpis.percentOfLimit === null ? '—' : `${Math.round(kpis.percentOfLimit * 100)}%`;
  const delta = kpis.deltaPctVsPrevious === null ? '—' : `${kpis.deltaPctVsPrevious > 0 ? '+' : ''}${Math.round(kpis.deltaPctVsPrevious * 100)}%`;
  const deltaPositive = kpis.deltaPctVsPrevious !== null && kpis.deltaPctVsPrevious < 0;

  return (
    <section className="grid grid-cols-2 gap-gutter md:grid-cols-4">
      <KpiCard icon="schedule" label="Tempo total" value={String(kpis.totalMinutes)} delta={delta} positive={deltaPositive} />
      <KpiCard icon="trending_flat" label="Média / dia" value={String(kpis.avgMinutesPerDay)} delta="min" positive={true} />
      <KpiCard icon="speed" label="% do limite" value={pctLimit} delta="" positive={kpis.percentOfLimit !== null && kpis.percentOfLimit < 1} />
      <KpiCard icon="trending_down" label="Delta vs anterior" value={delta} delta="" positive={deltaPositive} />
    </section>
  );
}

function KpiCard({ icon, label, value, delta, positive }: { icon: string; label: string; value: string; delta: string; positive: boolean }) {
  return (
    <article className="glass-panel rounded-2xl p-5 shadow-ambient">
      <div className="flex items-start justify-between">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-surface-container-high text-primary">
          <Icon name={icon} className="text-2xl" filled />
        </div>
      </div>
      <div className="mt-3 text-label-sm text-on-surface-variant">{label}</div>
      <div className="font-display text-headline-lg leading-none text-primary">{value}</div>
      {delta ? (
        <div className={`mt-1 text-label-sm font-semibold ${positive ? 'text-secondary' : 'text-on-error-container'}`}>
          {delta}
        </div>
      ) : null}
    </article>
  );
}

function ChartSection({ report, colorFor }: { report: Report; colorFor: (childId: number) => string }) {
  const max = Math.max(
    ...report.dailyByChild.map(d => Object.values(d.byChild).reduce((a, b) => a + b, 0)),
    1,
  );
  const chartHeight = 220;

  return (
    <section className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-6 flex items-start justify-between gap-3">
        <div>
          <h3 className="font-display text-headline-md text-on-surface">Minutos por dia</h3>
          <p className="text-label-sm text-on-surface-variant">Empilhado por filho</p>
        </div>
        <div className="flex flex-wrap gap-3 text-label-sm">
          {report.perChild.map(c => (
            <div key={c.childId} className="flex items-center gap-2 text-on-surface">
              <span className="inline-block h-3 w-3 rounded-sm" style={{ background: colorFor(c.childId) }} />
              {c.name}
            </div>
          ))}
        </div>
      </header>
      <div className="flex h-[260px] gap-3">
        <div className="flex flex-1 items-end gap-3 border-l border-b border-outline-variant pb-6">
          {report.dailyByChild.map(d => {
            const stacks = Object.entries(d.byChild).map(([cid, mins]) => ({
              childId: Number(cid),
              minutes: mins,
              height: (mins / max) * chartHeight,
            }));
            return (
              <div key={d.day} data-testid="chart-day" className="flex h-full flex-1 flex-col items-center justify-end gap-1">
                <div className="flex w-full max-w-[36px] flex-col overflow-hidden rounded-t-lg shadow-sm">
                  {stacks.map(s => (
                    <div key={s.childId} style={{ height: `${s.height}px`, background: colorFor(s.childId) }} className="w-full" />
                  ))}
                </div>
                <span className="mt-1 text-label-sm font-semibold text-on-surface-variant">{d.day.slice(-2)}</span>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}

function TopSitesSection({ sites, perChild }: { sites: ReportTopSite[]; perChild: ReportPerChild[] }) {
  const max = Math.max(...sites.map(s => s.opens), 1);
  return (
    <section className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4">
        <h3 className="font-display text-headline-md text-on-surface">Top sites visitados</h3>
        <p className="text-label-sm text-on-surface-variant">Ranqueado por nº de aberturas</p>
      </header>
      <ul className="divide-y divide-outline-variant/50">
        {sites.map((s, idx) => {
          const topChild = perChild.find(c => c.childId === s.topChildId)?.name ?? 'Família';
          const pct = (s.opens / max) * 100;
          return (
            <li key={s.domain} className="flex items-center gap-4 py-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-surface-container-high font-display text-label-md font-bold text-primary">
                #{idx + 1}
              </span>
              <div className="flex-1">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-label-md font-semibold text-on-surface">{s.domain}</span>
                  <span className="text-label-sm text-on-surface-variant">{s.opens} aberturas</span>
                </div>
                <div className="mt-1 text-label-sm text-on-surface-variant">mais usado por {topChild}</div>
                <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-surface-container">
                  <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} />
                </div>
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}

function PerChildSection({ perChild, children }: { perChild: ReportPerChild[]; children: Child[] }) {
  return (
    <section className="grid grid-cols-1 gap-gutter md:grid-cols-3">
      {perChild.map(c => {
        const avatar = children.find(x => x.id === c.childId)?.avatarUrl ?? null;
        return (
          <article key={c.childId} className="glass-panel rounded-2xl p-5 shadow-ambient">
            <header className="flex items-center gap-3">
              {avatar ? (
                <img src={avatar} alt={c.name} className="h-11 w-11 rounded-full object-cover" />
              ) : (
                <div className="flex h-11 w-11 items-center justify-center rounded-full bg-primary-container text-on-primary-container font-display text-headline-md font-bold">
                  {c.name.charAt(0)}
                </div>
              )}
              <div>
                <h3 className="font-display text-headline-md text-on-surface">{c.name}</h3>
                <p className="text-label-sm text-on-surface-variant">Resumo do período</p>
              </div>
            </header>
            <div className="mt-4 grid grid-cols-2 gap-3 text-center">
              <div className="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-3">
                <div className="text-label-sm text-on-surface-variant">Total</div>
                <div className="font-display text-headline-md text-primary">
                  {Math.floor(c.totalMinutes / 60)}h {c.totalMinutes % 60}m
                </div>
              </div>
              <div className="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-3">
                <div className="text-label-sm text-on-surface-variant">Média/dia</div>
                <div className="font-display text-headline-md text-primary">
                  {Math.floor(c.avgMinutesPerDay / 60)}h {c.avgMinutesPerDay % 60}m
                </div>
              </div>
            </div>
          </article>
        );
      })}
    </section>
  );
}

function LoadingSkeleton() {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-4 gap-gutter">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="glass-panel h-32 animate-pulse rounded-2xl bg-surface-container-low" />
        ))}
      </div>
      <div className="glass-panel h-64 animate-pulse rounded-2xl bg-surface-container-low" />
    </div>
  );
}

function EmptyState() {
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-3 rounded-2xl p-12 text-center shadow-ambient">
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-high text-primary">
        <Icon name="bar_chart" className="text-3xl" />
      </div>
      <h3 className="font-display text-headline-md text-on-surface">Ainda não há dados de uso</h3>
      <p className="text-body-md text-on-surface-variant">
        Os dados aparecem quando seus filhos abrirem o app.
      </p>
    </div>
  );
}

function LoadError({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar relatórios</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}
