import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

import { findLesson } from '../academy/lessons';
import { TRACKS, trackProgress, type Track } from '../academy/tracks';
import { completeLesson, getAcademyProgress } from '../api/academy';
import { AcademyPanel } from '../components/AcademyPanel';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';

/**
 * Academia — a área navegável de trilhas/aulas (Onda 2). Lê o progresso do
 * usermeta (mesmo `completed` da pílula contextual), mostra cada trilha com barra
 * de progresso e "Continuar", e abre a aula no AcademyPanel reusado. Concluir
 * invalida o progresso → a barra sobe.
 */
export function Academy() {
  const progressQuery = useQuery({ queryKey: ['academy', 'progress'], queryFn: getAcademyProgress });
  const completed = progressQuery.data?.completed ?? [];

  const [openLessonId, setOpenLessonId] = useState<string | null>(null);
  const queryClient = useQueryClient();

  const completeMutation = useMutation({
    mutationFn: (lessonId: string) => completeLesson(lessonId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['academy', 'progress'] });
      setOpenLessonId(null);
    },
  });

  const openLesson = openLessonId ? findLesson(openLessonId) : undefined;

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Academia"
        subtitle="Aprenda a usar o GuardKids no seu ritmo, por trilhas."
      />

      <div className="flex flex-col gap-4">
        {TRACKS.map((track) => (
          <TrackCard
            key={track.id}
            track={track}
            completed={completed}
            onOpenLesson={setOpenLessonId}
          />
        ))}
      </div>

      {openLesson ? (
        <AcademyPanel
          title={openLesson.title}
          reason={openLesson.summary}
          body={openLesson.body}
          busy={completeMutation.isPending}
          onClose={() => setOpenLessonId(null)}
          onComplete={() => completeMutation.mutate(openLesson.id)}
        />
      ) : null}
    </main>
  );
}

function TrackCard({
  track,
  completed,
  onOpenLesson,
}: {
  track: Track;
  completed: string[];
  onOpenLesson: (lessonId: string) => void;
}) {
  const { total, done, pct, nextLessonId } = trackProgress(track, completed);
  const isComingSoon = track.status === 'coming-soon';

  return (
    <section className="rounded-2xl border border-outline-variant bg-surface p-4">
      <div className="flex items-start gap-3">
        <span
          className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full ${
            isComingSoon ? 'bg-surface-variant text-on-surface-variant' : 'bg-primary/15 text-primary'
          }`}
        >
          <Icon name={track.icon} filled={!isComingSoon} />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h2 className="font-display text-title-md text-on-surface">{track.title}</h2>
            {isComingSoon ? (
              <span className="rounded-full bg-surface-variant px-2 py-0.5 text-label-sm text-on-surface-variant">
                Em breve
              </span>
            ) : null}
          </div>
          <p className="mt-0.5 text-body-md text-on-surface-variant">{track.description}</p>
        </div>
      </div>

      {isComingSoon ? null : (
        <>
          <div className="mt-3" aria-label={`Progresso: ${pct}%`}>
            <div className="flex items-center justify-between text-label-sm text-on-surface-variant">
              <span>
                {done} de {total} aulas
              </span>
              <span>{pct}%</span>
            </div>
            <div className="mt-1 h-2 overflow-hidden rounded-full bg-surface-variant">
              <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} />
            </div>
          </div>

          <div className="mt-3">
            {nextLessonId ? (
              <button
                type="button"
                onClick={() => onOpenLesson(nextLessonId)}
                className="rounded-lg bg-primary px-4 py-2 text-label-lg text-white hover:bg-primary-container"
              >
                {done === 0 ? 'Começar' : 'Continuar'}
              </button>
            ) : (
              <p className="flex items-center gap-1 text-label-lg text-primary">
                <Icon name="check_circle" filled /> Trilha concluída
              </p>
            )}
          </div>

          <ul className="mt-3 flex flex-col gap-1">
            {track.lessonIds.map((lessonId, index) => {
              const lesson = findLesson(lessonId);
              if (!lesson) {
                return null;
              }
              const isDone = completed.includes(lessonId);
              return (
                <li key={lessonId}>
                  <button
                    type="button"
                    onClick={() => onOpenLesson(lessonId)}
                    className="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left hover:bg-surface-container"
                  >
                    <Icon
                      name={isDone ? 'check_circle' : 'radio_button_unchecked'}
                      filled={isDone}
                      className={isDone ? 'text-primary' : 'text-on-surface-variant'}
                    />
                    <span className="text-body-md text-on-surface">
                      {index + 1}. {lesson.title}
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>
        </>
      )}
    </section>
  );
}
