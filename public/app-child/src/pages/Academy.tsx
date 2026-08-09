import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { completeLesson, getAcademy } from '../api/academy';
import { renderMarkdown } from '../academy/markdown';
import { findLesson } from '../academy/lessons';
import { findTrack, TRACKS, trackProgress } from '../academy/tracks';
import type { PageId } from '../data/mockData';
import { FormError } from '../components/FormError';
import { Icon } from '../components/Icon';

export function Academy({ onNavigate }: { onNavigate: (page: PageId) => void }) {
  const qc = useQueryClient();
  const academyQuery = useQuery({ queryKey: ['child', 'academy'], queryFn: getAcademy });

  const [openTrackId, setOpenTrackId] = useState<string | null>(null);
  const [openLessonId, setOpenLessonId] = useState<string | null>(null);
  const [celebration, setCelebration] = useState<{ xp: number; coins: number } | null>(null);

  const completeMut = useMutation({
    mutationFn: (key: string) => completeLesson(key),
    onSuccess: (res) => {
      // atualiza a carteira em todo lugar que a mostra (Academy, Home/Mundo).
      qc.invalidateQueries({ queryKey: ['child', 'academy'] });
      qc.invalidateQueries({ queryKey: ['child', 'me'] });
      qc.invalidateQueries({ queryKey: ['child', 'progression'] });
      if (res.awarded.justCompleted) {
        setCelebration({ xp: res.awarded.xp, coins: res.awarded.coins });
      }
    },
  });

  const completedKeys = academyQuery.data?.completedKeys ?? [];
  const progression = academyQuery.data?.progression;

  const back = () => onNavigate('home');

  if (academyQuery.isLoading) {
    return (
      <main className="flex flex-1 items-center justify-center text-on-surface-variant">
        <Icon name="progress_activity" className="animate-spin text-2xl" />
      </main>
    );
  }

  // ── Visualizador de aula ────────────────────────────────────────────
  const openLesson = openLessonId ? findLesson(openLessonId) : undefined;
  if (openLesson) {
    const done = completedKeys.includes(openLesson.id);
    const closeLesson = () => {
      setOpenLessonId(null);
      setCelebration(null);
    };
    return (
      <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
        <button
          type="button"
          onClick={closeLesson}
          className="flex items-center gap-1 self-start text-label-sm text-on-surface-variant"
        >
          <Icon name="arrow_back" className="text-base" /> Voltar
        </button>

        <article className="rounded-2xl bg-surface-container p-4 shadow-sm">
          <div className="space-y-1">{renderMarkdown(openLesson.body)}</div>
        </article>

        {completeMut.isError && <FormError error={completeMut.error} />}

        {celebration ? (
          <div
            role="status"
            className="flex flex-col items-center gap-2 rounded-2xl bg-primary-container p-5 text-center text-on-primary-container shadow-sm"
          >
            <Icon name="celebration" className="text-3xl" filled />
            <p className="font-display text-title-md font-bold">Boa! Você concluiu a aula 🎉</p>
            <p className="text-label-md font-semibold">
              +{celebration.xp} XP · +{celebration.coins} moedas
            </p>
            <button
              type="button"
              onClick={closeLesson}
              className="mt-1 rounded-xl bg-primary px-5 py-2 text-label-md font-semibold text-white"
            >
              Continuar
            </button>
          </div>
        ) : done ? (
          <div className="flex items-center justify-center gap-2 rounded-2xl bg-surface-container-low p-4 text-label-md font-semibold text-primary">
            <Icon name="check_circle" filled /> Aula concluída
          </div>
        ) : (
          <button
            type="button"
            disabled={completeMut.isPending}
            onClick={() => completeMut.mutate(openLesson.id)}
            className="rounded-2xl bg-primary px-5 py-3 text-title-md font-bold text-white shadow-sm disabled:opacity-40"
          >
            {completeMut.isPending ? 'Salvando…' : 'Concluí!'}
          </button>
        )}
      </main>
    );
  }

  // ── Lista de aulas de uma trilha ────────────────────────────────────
  const openTrack = openTrackId ? findTrack(openTrackId) : undefined;
  if (openTrack) {
    const prog = trackProgress(openTrack, completedKeys);
    return (
      <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
        <button
          type="button"
          onClick={() => setOpenTrackId(null)}
          className="flex items-center gap-1 self-start text-label-sm text-on-surface-variant"
        >
          <Icon name="arrow_back" className="text-base" /> Trilhas
        </button>

        <div className="rounded-2xl bg-primary p-4 text-white shadow-sm">
          <div className="flex items-center gap-2">
            <Icon name={openTrack.icon} className="text-2xl" filled />
            <span className="font-display text-title-md font-bold">{openTrack.title}</span>
          </div>
          <ProgressBar pct={prog.pct} done={prog.done} total={prog.total} tone="light" />
        </div>

        <ol className="space-y-3">
          {openTrack.lessonIds.map((id, i) => {
            const lesson = findLesson(id);
            if (!lesson) return null;
            const done = completedKeys.includes(id);
            return (
              <li key={id}>
                <button
                  type="button"
                  onClick={() => setOpenLessonId(id)}
                  className="flex w-full items-center gap-3 rounded-2xl bg-surface-container p-4 text-left shadow-sm active:scale-[0.99]"
                >
                  <div
                    className={
                      done
                        ? 'flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-white'
                        : 'flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-variant text-on-surface-variant'
                    }
                  >
                    {done ? <Icon name="check" filled /> : <span className="text-label-md font-bold">{i + 1}</span>}
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="text-label-md font-semibold text-on-surface">{lesson.title}</div>
                    <div className="truncate text-label-sm text-on-surface-variant">{lesson.summary}</div>
                  </div>
                  <Icon name="chevron_right" className="text-on-surface-variant" />
                </button>
              </li>
            );
          })}
        </ol>
      </main>
    );
  }

  // ── Lista de trilhas ────────────────────────────────────────────────
  return (
    <main className="flex flex-1 flex-col gap-stack-lg px-container-padding-mobile py-stack-md">
      <button
        type="button"
        onClick={back}
        className="flex items-center gap-1 self-start text-label-sm text-on-surface-variant"
      >
        <Icon name="arrow_back" className="text-base" /> Voltar
      </button>

      <div className="flex items-center justify-between rounded-2xl bg-primary p-4 text-white shadow-sm">
        <span className="flex items-center gap-2 font-display text-title-md font-bold">
          <Icon name="school" className="text-xl" filled /> Academia
        </span>
        {progression ? (
          <span className="flex items-center gap-1 text-title-md font-bold">
            <Icon name="bolt" className="text-xl" filled /> {progression.xp}
          </span>
        ) : null}
      </div>

      <p className="text-label-md text-on-surface-variant">
        Aprenda coisas legais e ganhe XP e moedas em cada aula!
      </p>

      <ul className="space-y-3">
        {TRACKS.map((track) => {
          const prog = trackProgress(track, completedKeys);
          return (
            <li key={track.id}>
              <button
                type="button"
                onClick={() => setOpenTrackId(track.id)}
                className="flex w-full items-center gap-3 rounded-2xl bg-surface-container p-4 text-left shadow-sm active:scale-[0.99]"
              >
                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary-container text-on-primary-container">
                  <Icon name={track.icon} className="text-2xl" filled />
                </div>
                <div className="min-w-0 flex-1">
                  <div className="font-display text-label-lg font-bold text-on-surface">{track.title}</div>
                  <div className="text-label-sm text-on-surface-variant">{track.description}</div>
                  <ProgressBar pct={prog.pct} done={prog.done} total={prog.total} tone="dark" />
                </div>
              </button>
            </li>
          );
        })}
      </ul>
    </main>
  );
}

function ProgressBar({
  pct,
  done,
  total,
  tone,
}: {
  pct: number;
  done: number;
  total: number;
  tone: 'light' | 'dark';
}) {
  const track = tone === 'light' ? 'bg-white/30' : 'bg-surface-variant';
  const fill = tone === 'light' ? 'bg-white' : 'bg-primary';
  const label = tone === 'light' ? 'text-white/90' : 'text-on-surface-variant';
  return (
    <div className="mt-2">
      <div className={`h-2 w-full overflow-hidden rounded-full ${track}`}>
        <div
          className={`h-full rounded-full ${fill}`}
          style={{ width: `${pct}%` }}
          role="progressbar"
          aria-valuenow={pct}
          aria-valuemin={0}
          aria-valuemax={100}
        />
      </div>
      <span className={`mt-1 block text-label-sm ${label}`}>
        {done} de {total} aulas
      </span>
    </div>
  );
}
