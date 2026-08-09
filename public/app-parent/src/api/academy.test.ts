import { beforeEach, describe, expect, it, vi } from 'vitest';

const { apiFetchMock } = vi.hoisted(() => ({ apiFetchMock: vi.fn() }));
vi.mock('./client', () => ({
  apiFetch: apiFetchMock,
  ApiError: class ApiError extends Error {},
}));

import { completeLesson, dismissLesson, getAcademyProgress, markLesson } from './academy';

describe('api/academy', () => {
  beforeEach(() => {
    apiFetchMock.mockReset().mockResolvedValue({ completed: [], dismissed: [] });
  });

  it('getAcademyProgress GETs /academy/progress', async () => {
    await getAcademyProgress();
    expect(apiFetchMock).toHaveBeenCalledWith('/academy/progress');
  });

  it('markLesson POSTs lessonId + kind', async () => {
    await markLesson('primeiros-passos', 'completed');
    expect(apiFetchMock).toHaveBeenCalledWith('/academy/progress', {
      method: 'POST',
      body: JSON.stringify({ lessonId: 'primeiros-passos', kind: 'completed' }),
    });
  });

  it('completeLesson usa kind completed', async () => {
    await completeLesson('dispositivo-filho');
    expect(apiFetchMock).toHaveBeenCalledWith('/academy/progress', {
      method: 'POST',
      body: JSON.stringify({ lessonId: 'dispositivo-filho', kind: 'completed' }),
    });
  });

  it('dismissLesson usa kind dismissed', async () => {
    await dismissLesson('verificacao-conexao');
    expect(apiFetchMock).toHaveBeenCalledWith('/academy/progress', {
      method: 'POST',
      body: JSON.stringify({ lessonId: 'verificacao-conexao', kind: 'dismissed' }),
    });
  });
});
