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

export function getReport(range: ReportRange = 'week'): Promise<Report> {
  return apiFetch<Report>(`/reports?range=${range}`);
}
