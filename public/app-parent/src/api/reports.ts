import { apiFetch } from './client';

export type ReportRange = 'week' | 'month';

export type ReportKpis = {
  totalMinutes: number;
  avgMinutesPerDay: number;
  percentOfLimit: number | null;
  deltaPctVsPrevious: number | null;
};

export type ReportDailyEntry = {
  day: string;
  byChild: Record<number, number>;
};

export type ReportTopSite = {
  domain: string;
  opens: number;
  topChildId: number | null;
};

export type ReportPerChild = {
  childId: number;
  name: string;
  totalMinutes: number;
  avgMinutesPerDay: number;
};

export type Report = {
  range: ReportRange;
  from: string;
  to: string;
  kpis: ReportKpis;
  dailyByChild: ReportDailyEntry[];
  topSites: ReportTopSite[];
  perChild: ReportPerChild[];
};

export function getReport(range: ReportRange = 'week', childId = 0): Promise<Report> {
  const childFilter = childId > 0 ? `&child_id=${childId}` : '';
  return apiFetch<Report>(`/reports?range=${range}${childFilter}`);
}

export type BlockDetail = 'bedtime' | 'weekday' | 'limit';

export type RecentBlock = {
  id: number;
  childId: number;
  childName: string;
  detail: BlockDetail;
  createdAt: string;
};

export function getRecentBlocks(limit = 10): Promise<RecentBlock[]> {
  return apiFetch<RecentBlock[]>(`/blocks/recent?limit=${limit}`);
}
