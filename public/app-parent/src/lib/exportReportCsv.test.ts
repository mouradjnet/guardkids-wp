import { describe, expect, it } from 'vitest';
import type { Report } from '../api/reports';
import { buildReportCsv } from './exportReportCsv';

const sample: Report = {
  range: 'week',
  from: '2026-05-30T00:00:00',
  to: '2026-06-06T00:00:00',
  kpis: {
    totalMinutes: 720,
    avgMinutesPerDay: 103,
    percentOfLimit: 0.74,
    deltaPctVsPrevious: -0.12,
  },
  dailyByChild: [
    { day: '2026-05-30', byChild: { 1: 90, 2: 30 } },
    { day: '2026-05-31', byChild: { 1: 120 } },
  ],
  topSites: [
    { domain: 'youtube.com', opens: 14, topChildId: 1 },
    { domain: 'khanacademy.org', opens: 8, topChildId: null },
  ],
  perChild: [
    { childId: 1, name: 'Lucas', totalMinutes: 720, avgMinutesPerDay: 103 },
    { childId: 2, name: 'Sofia', totalMinutes: 30, avgMinutesPerDay: 4 },
  ],
};

describe('buildReportCsv', () => {
  it('inclui as 4 secoes na ordem certa', () => {
    const csv = buildReportCsv(sample);
    const kpisIdx = csv.indexOf('KPIs');
    const dailyIdx = csv.indexOf('Minutos por dia');
    const topIdx = csv.indexOf('Top sites');
    const perChildIdx = csv.indexOf('Resumo por filho');

    expect(kpisIdx).toBeGreaterThan(0);
    expect(dailyIdx).toBeGreaterThan(kpisIdx);
    expect(topIdx).toBeGreaterThan(dailyIdx);
    expect(perChildIdx).toBeGreaterThan(topIdx);
  });

  it('comeca com BOM UTF-8 e usa CRLF', () => {
    const csv = buildReportCsv(sample);
    expect(csv.charCodeAt(0)).toBe(0xFEFF);
    expect(csv).toContain('\r\n');
  });

  it('KPIs formatados (delta -12%, % limite 74%)', () => {
    const csv = buildReportCsv(sample);
    expect(csv).toContain('Tempo total (minutos),720');
    expect(csv).toContain('% do limite,74%');
    expect(csv).toContain('Delta vs janela anterior,-12%');
  });

  it('daily inclui coluna por child, 0 quando ausente', () => {
    const csv = buildReportCsv(sample);
    expect(csv).toContain('Dia,Lucas,Sofia');
    expect(csv).toContain('2026-05-30,90,30');
    // Sofia nao tem dado em 2026-05-31 → 0
    expect(csv).toContain('2026-05-31,120,0');
  });

  it('top sites com rank, dominio, aberturas e mais usado por', () => {
    const csv = buildReportCsv(sample);
    expect(csv).toContain('1,youtube.com,14,Lucas');
    // topChildId null → "Familia"
    expect(csv).toContain('2,khanacademy.org,8,Familia');
  });

  it('per child em rows separadas', () => {
    const csv = buildReportCsv(sample);
    expect(csv).toContain('1,Lucas,720,103');
    expect(csv).toContain('2,Sofia,30,4');
  });

  it('escapa virgulas e aspas em nomes', () => {
    const tricky: Report = {
      ...sample,
      perChild: [{ childId: 9, name: 'O"Brien, Jr', totalMinutes: 0, avgMinutesPerDay: 0 }],
    };
    const csv = buildReportCsv(tricky);
    expect(csv).toContain('9,"O""Brien, Jr",0,0');
  });

  it('% do limite null vira em-dash', () => {
    const empty: Report = { ...sample, kpis: { ...sample.kpis, percentOfLimit: null, deltaPctVsPrevious: null } };
    const csv = buildReportCsv(empty);
    expect(csv).toContain('% do limite,—');
    expect(csv).toContain('Delta vs janela anterior,—');
  });
});
