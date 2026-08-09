import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import type { Child } from '../api/types';

const listChildrenMock = vi.fn();
const listSitesMock = vi.fn();
const getAcademyProgressMock = vi.fn();

vi.mock('../api/children', () => ({ listChildren: () => listChildrenMock() }));
vi.mock('../api/sites', () => ({ listSites: () => listSitesMock() }));
vi.mock('../api/academy', () => ({ getAcademyProgress: () => getAcademyProgressMock() }));

import { useAcademyContext } from './useAcademyContext';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

function child(overrides: Partial<Child>): Child {
  return {
    id: 1,
    slug: 'c1',
    name: 'Kid',
    age: 8,
    avatarUrl: null,
    device: null,
    paired: false,
    status: 'offline',
    usedMinutes: 0,
    limitMinutes: 0,
    dailyLimitEnabled: false,
    bedtimeEnabled: false,
    bedtimeStart: null,
    bedtimeEnd: null,
    allowedWeekdays: '',
    createdAt: null,
    updatedAt: null,
    currentPlace: null,
    ...overrides,
  };
}

describe('useAcademyContext', () => {
  it('família vazia: contadores zerados e todos os sinais falsos', async () => {
    listChildrenMock.mockResolvedValue([]);
    listSitesMock.mockResolvedValue([]);
    getAcademyProgressMock.mockResolvedValue({ completed: [], dismissed: [] });

    const { result } = renderHook(() => useAcademyContext('dashboard'), {
      wrapper: makeWrapper(),
    });

    await waitFor(() => expect(result.current.family.childrenCount).toBe(0));
    expect(result.current.family.hasPairedDevice).toBe(false);
    expect(result.current.family.hasSiteRules).toBe(false);
    expect(result.current.family.hasTimeLimits).toBe(false);
    expect(result.current.screen).toBe('dashboard');
  });

  it('deriva os sinais do estado real (aparelho pareado, limite e regra)', async () => {
    listChildrenMock.mockResolvedValue([child({ paired: true, dailyLimitEnabled: true })]);
    listSitesMock.mockResolvedValue([{ id: 1 }]);
    getAcademyProgressMock.mockResolvedValue({ completed: [], dismissed: [] });

    const { result } = renderHook(() => useAcademyContext('children'), {
      wrapper: makeWrapper(),
    });

    await waitFor(() => expect(result.current.family.childrenCount).toBe(1));
    expect(result.current.family.hasPairedDevice).toBe(true);
    expect(result.current.family.hasTimeLimits).toBe(true);
    expect(result.current.family.hasSiteRules).toBe(true);
  });

  it('hora de dormir sozinha também conta como limite de tempo', async () => {
    listChildrenMock.mockResolvedValue([child({ bedtimeEnabled: true })]);
    listSitesMock.mockResolvedValue([]);
    getAcademyProgressMock.mockResolvedValue({ completed: [], dismissed: [] });

    const { result } = renderHook(() => useAcademyContext('time'), { wrapper: makeWrapper() });

    await waitFor(() => expect(result.current.family.hasTimeLimits).toBe(true));
  });

  it('repassa o progresso do servidor (completed/dismissed)', async () => {
    listChildrenMock.mockResolvedValue([]);
    listSitesMock.mockResolvedValue([]);
    getAcademyProgressMock.mockResolvedValue({
      completed: ['primeiros-passos'],
      dismissed: ['verificacao-conexao'],
    });

    const { result } = renderHook(() => useAcademyContext('dashboard'), {
      wrapper: makeWrapper(),
    });

    await waitFor(() => expect(result.current.completedLessonIds).toEqual(['primeiros-passos']));
    expect(result.current.dismissed).toEqual(['verificacao-conexao']);
  });
});
