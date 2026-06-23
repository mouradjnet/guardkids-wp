import { apiFetch } from './client';

export type ExportData = {
  exported_at: string;
  site_url: string;
  version: string;
  tables: Record<string, unknown[]>;
};

export type ClearHistoryResult = {
  usage_events: number;
  locations: number;
  requests: number;
};

export type DeleteAllResult = {
  tables: Record<string, number>;
};

export function exportData(): Promise<ExportData> {
  return apiFetch<ExportData>('/privacy/export');
}

export function clearHistory(): Promise<ClearHistoryResult> {
  return apiFetch<ClearHistoryResult>('/privacy/clear-history', { method: 'POST' });
}

export function deleteAllData(confirm: string): Promise<DeleteAllResult> {
  return apiFetch<DeleteAllResult>('/privacy/delete-all', {
    method: 'POST',
    body: JSON.stringify({ confirm }),
  });
}
