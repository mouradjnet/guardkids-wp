import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { getInsights, refreshInsights, type Insight, type InsightSeverity } from '../api/insights';
import type { ReportRange } from '../api/reports';
import { useLicense } from '../hooks/useLicense';
import { Icon } from './Icon';

type Props = {
  range: ReportRange;
  childId: number;
};

const SEVERITY: Record<InsightSeverity, { icon: string; accent: string; badge: string }> = {
  info:    { icon: 'lightbulb', accent: 'border-l-primary',    badge: 'bg-surface-container-high text-primary' },
  warning: { icon: 'warning',   accent: 'border-l-[#F59E0B]',  badge: 'bg-[#F59E0B]/15 text-[#B45309]' },
  alert:   { icon: 'priority_high', accent: 'border-l-error',  badge: 'bg-error/10 text-error' },
};

/**
 * Card de Insights de IA (Onda 5) — lê o mesmo uso dos relatórios e mostra, em
 * linguagem natural, o que mudou e o que fazer. A query só dispara quando o
 * plano libera `ai_insights` (o overlay de upgrade fica a cargo do PremiumLock
 * que envolve este card); o Gate do PHP é a barreira final.
 */
export function InsightsCard({ range, childId }: Props) {
  const license = useLicense();
  const enabled = license.can('ai_insights');
  const queryClient = useQueryClient();
  const [refreshing, setRefreshing] = useState(false);

  const query = useQuery({
    queryKey: ['insights', range, childId],
    queryFn: () => getInsights(range, childId),
    enabled,
  });

  async function handleRefresh() {
    setRefreshing(true);
    try {
      const fresh = await refreshInsights(range, childId);
      queryClient.setQueryData(['insights', range, childId], fresh);
    } catch {
      // mantém o que já está na tela; o usuário pode tentar de novo
    } finally {
      setRefreshing(false);
    }
  }

  const data = query.data;
  const insights = data?.insights ?? [];
  const unavailable = data !== undefined && !data.available;

  return (
    <section className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-start justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary text-white shadow-ambient">
            <Icon name="auto_awesome" className="text-2xl" filled />
          </div>
          <div>
            <h3 className="font-display text-headline-md text-on-surface">Insights com IA</h3>
            <p className="text-label-sm text-on-surface-variant">Leitura inteligente do uso da família</p>
          </div>
        </div>
        <button
          type="button"
          onClick={handleRefresh}
          disabled={!enabled || query.isLoading || refreshing}
          aria-label="Atualizar insights"
          className="inline-flex items-center gap-2 rounded-full border border-outline-variant bg-white px-4 py-2 text-label-md font-semibold text-on-surface shadow-sm hover:bg-surface-variant disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Icon name="refresh" className={`text-sm ${refreshing ? 'animate-spin' : ''}`} />
          Atualizar
        </button>
      </header>

      {query.isLoading || refreshing ? (
        <InsightsSkeleton />
      ) : query.isError ? (
        <Note icon="error" tone="error">Não foi possível carregar os insights agora.</Note>
      ) : unavailable ? (
        <Note icon="cloud_off" tone="muted">
          Insights de IA indisponíveis no momento. Tente novamente mais tarde.
        </Note>
      ) : insights.length === 0 ? (
        <Note icon="check_circle" tone="muted">
          Sem alertas no período. Continue acompanhando por aqui.
        </Note>
      ) : (
        <ul className="flex flex-col gap-3">
          {insights.map((it, idx) => (
            <InsightRow key={idx} insight={it} />
          ))}
        </ul>
      )}

      {data?.generatedAt && insights.length > 0 && (
        <p className="mt-4 text-label-sm text-on-surface-variant">
          Gerado por IA{data.fromCache ? ' (em cache)' : ''}. Revise antes de agir.
        </p>
      )}
    </section>
  );
}

function InsightRow({ insight }: { insight: Insight }) {
  const style = SEVERITY[insight.severity] ?? SEVERITY.info;
  return (
    <li className={`rounded-xl border border-outline-variant border-l-4 bg-surface-container-low p-4 ${style.accent}`}>
      <div className="flex items-start gap-3">
        <span className={`mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${style.badge}`}>
          <Icon name={style.icon} className="text-lg" filled />
        </span>
        <div className="flex-1">
          <h4 className="text-label-lg font-bold text-on-surface">{insight.title}</h4>
          <p className="mt-1 text-body-md text-on-surface-variant">{insight.body}</p>
          {insight.cta && (
            <span className="mt-2 inline-flex items-center gap-1 text-label-sm font-semibold text-primary">
              <Icon name="arrow_forward" className="text-sm" />
              {insight.cta}
            </span>
          )}
        </div>
      </div>
    </li>
  );
}

function InsightsSkeleton() {
  return (
    <div className="flex flex-col gap-3">
      {Array.from({ length: 2 }).map((_, i) => (
        <div key={i} className="h-20 animate-pulse rounded-xl bg-surface-container-low" />
      ))}
    </div>
  );
}

function Note({ icon, tone, children }: { icon: string; tone: 'muted' | 'error'; children: React.ReactNode }) {
  const color = tone === 'error' ? 'text-error' : 'text-on-surface-variant';
  return (
    <div className={`flex items-center gap-3 rounded-xl bg-surface-container-low p-4 ${color}`}>
      <Icon name={icon} className="text-xl" />
      <p className="text-label-md">{children}</p>
    </div>
  );
}
