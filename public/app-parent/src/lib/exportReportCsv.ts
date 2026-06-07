import type { Report } from '../api/reports';

type CsvRow = ReadonlyArray<string | number | null | undefined>;

function escapeCell(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return '';
  const s = String(value);
  if (s.includes(',') || s.includes('"') || s.includes('\n') || s.includes('\r')) {
    return `"${s.replace(/"/g, '""')}"`;
  }
  return s;
}

function row(cells: CsvRow): string {
  return cells.map(escapeCell).join(',');
}

function fmtRatio(v: number | null): string {
  if (v === null) return '—';
  return `${Math.round(v * 100)}%`;
}

function fmtDelta(v: number | null): string {
  if (v === null) return '—';
  const sign = v > 0 ? '+' : '';
  return `${sign}${Math.round(v * 100)}%`;
}

/**
 * Monta CSV de relatório com 4 seções (KPIs, daily, top sites, per child).
 * Função pura — separação clara da parte que toca DOM.
 */
export function buildReportCsv(report: Report): string {
  const childIds = Array.from(
    new Set(report.dailyByChild.flatMap((d) => Object.keys(d.byChild).map(Number))),
  ).sort((a, b) => a - b);
  const childNameById = new Map(report.perChild.map((c) => [c.childId, c.name]));

  const lines: string[] = [];

  lines.push(row(['GuardKids - Relatorio de uso']));
  lines.push(row(['Periodo', `${report.from} ate ${report.to}`]));
  lines.push(row(['Range', report.range]));
  lines.push('');

  lines.push(row(['KPIs']));
  lines.push(row(['Indicador', 'Valor']));
  lines.push(row(['Tempo total (minutos)', report.kpis.totalMinutes]));
  lines.push(row(['Media por dia (minutos)', report.kpis.avgMinutesPerDay]));
  lines.push(row(['% do limite', fmtRatio(report.kpis.percentOfLimit)]));
  lines.push(row(['Delta vs janela anterior', fmtDelta(report.kpis.deltaPctVsPrevious)]));
  lines.push('');

  lines.push(row(['Minutos por dia']));
  const dailyHeader: CsvRow = ['Dia', ...childIds.map((id) => childNameById.get(id) ?? `Child ${id}`)];
  lines.push(row(dailyHeader));
  for (const day of report.dailyByChild) {
    lines.push(row([day.day, ...childIds.map((id) => day.byChild[id] ?? 0)]));
  }
  lines.push('');

  lines.push(row(['Top sites']));
  lines.push(row(['#', 'Dominio', 'Aberturas', 'Mais usado por']));
  report.topSites.forEach((s, i) => {
    const usedBy = s.topChildId !== null ? (childNameById.get(s.topChildId) ?? 'Familia') : 'Familia';
    lines.push(row([i + 1, s.domain, s.opens, usedBy]));
  });
  lines.push('');

  lines.push(row(['Resumo por filho']));
  lines.push(row(['ChildId', 'Nome', 'Total (minutos)', 'Media/dia (minutos)']));
  for (const c of report.perChild) {
    lines.push(row([c.childId, c.name, c.totalMinutes, c.avgMinutesPerDay]));
  }

  // BOM UTF-8 pra Excel detectar charset com acentos
  return '﻿' + lines.join('\r\n') + '\r\n';
}

/**
 * Dispara download do CSV no browser. Efeito colateral — isolado dessa função
 * pra testes ficarem simples (unit em buildReportCsv, integração via spy aqui).
 */
export function downloadReportCsv(report: Report, opts: { now?: Date } = {}): void {
  const csv = buildReportCsv(report);
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  const today = (opts.now ?? new Date()).toISOString().slice(0, 10);
  a.href = url;
  a.download = `relatorio-${report.range}-${today}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}
