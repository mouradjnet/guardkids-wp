import { apiFetch } from './client';
import type { ReportRange } from './reports';

export type InsightSeverity = 'info' | 'warning' | 'alert';

export type Insight = {
  title: string;
  body: string;
  severity: InsightSeverity;
  cta: string;
};

/** Espelha o payload do InsightsController (Onda 5). */
export type InsightsResponse = {
  available: boolean;
  /** Presente quando available=false: 'locked' (Free) | 'no_key' (sem chave). */
  reason?: string;
  fromCache: boolean;
  generatedAt?: string;
  model?: string;
  insights: Insight[];
};

export function getInsights(range: ReportRange = 'week', childId = 0): Promise<InsightsResponse> {
  const childFilter = childId > 0 ? `&child_id=${childId}` : '';
  return apiFetch<InsightsResponse>(`/insights?range=${range}${childFilter}`);
}

export function refreshInsights(range: ReportRange = 'week', childId = 0): Promise<InsightsResponse> {
  return apiFetch<InsightsResponse>('/insights/refresh', {
    method: 'POST',
    body: JSON.stringify(childId > 0 ? { range, child_id: childId } : { range }),
  });
}
