import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getAcademy, submitQuiz } from '../api/academy';
import { renderMarkdown } from '../academy/markdown';
import { findLesson } from '../academy/lessons';
import { findQuiz, type QuizQuestion } from '../academy/quizzes';
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
  const [failed, setFailed] = useState<{ correct: number; total: number } | null>(null);

  const quizMut = useMutation({
    mutationFn: (vars: { key: string; answers: number[] }) => submitQuiz(vars.key, vars.answers),
    onSuccess: (res) => {
      if (res.passed) {
        setFailed(null);
        // atualiza a carteira em todo lugar que a mostra (Academy, Home/Mundo).
        qc.invalidateQueries({ queryKey: ['child', 'academy'] });
        qc.invalidateQueries({ queryKey: ['child', 'me'] });
        qc.invalidateQueries({ queryKey: ['child', 'progression'] });
        if (res.awarded.justCompleted) {
          setCelebration({ xp: res.awarded.xp, coins: res.awarded.coins });
        }
      } else {
        setFailed({ correct: res.correct, total: res.total });
      }
    },
  });

  const completedKeys = academyQuery.data?.completedKeys ?? [];
  const progression = academyQuery.data?.progression;

  const back = () => onNavigate('home');

  const openLessonById = (id: string) => {
    setOpenLessonId(id);
    setCelebration(null);
    setFailed(null);
  };

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
    const quiz = findQuiz(openLesson.id);
    const closeLesson = () => {
      setOpenLessonId(null);
      setCelebration(null);
      setFailed(null);
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

        {quizMut.isError && <FormError error={quizMut.error} />}

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
        ) : quiz ? (
          <QuizForm
            key={openLesson.id}
            questions={quiz}
            pending={quizMut.isPending}
            failed={failed}
            onSubmit={(answers) => quizMut.mutate({ key: openLesson.id, answers })}
          />
        ) : null}
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
                  onClick={() => openLessonById(id)}
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
        Aprenda coisas legais, responda o quiz e ganhe XP e moedas em cada aula!
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

/**
 * Quiz da aula: seleciona uma alternativa por questão e envia. A correção é do
 * servidor — aqui não há gabarito. Guarda a seleção localmente (o `key` no pai
 * remonta a cada aula, zerando as respostas).
 */
function QuizForm({
  questions,
  pending,
  failed,
  onSubmit,
}: {
  questions: QuizQuestion[];
  pending: boolean;
  failed: { correct: number; total: number } | null;
  onSubmit: (answers: number[]) => void;
}) {
  const [sel, setSel] = useState<(number | null)[]>(() => questions.map(() => null));
  const allAnswered = sel.every((v) => v !== null);

  return (
    <div className="flex flex-col gap-stack-md">
      <p className="font-display text-title-md font-bold text-on-surface">Quiz da aula</p>

      {questions.map((q, qi) => (
        <div key={qi} className="rounded-2xl bg-surface-container p-4 shadow-sm">
          <p className="mb-2 text-label-md font-semibold text-on-surface">
            {qi + 1}. {q.prompt}
          </p>
          <div className="flex flex-col gap-2">
            {q.options.map((opt, oi) => {
              const chosen = sel[qi] === oi;
              return (
                <button
                  key={oi}
                  type="button"
                  aria-pressed={chosen}
                  onClick={() =>
                    setSel((prev) => {
                      const next = [...prev];
                      next[qi] = oi;
                      return next;
                    })
                  }
                  className={
                    chosen
                      ? 'rounded-xl bg-primary px-4 py-2 text-left text-label-md font-semibold text-white'
                      : 'rounded-xl bg-surface-container-low px-4 py-2 text-left text-label-md text-on-surface active:scale-[0.99]'
                  }
                >
                  {opt}
                </button>
              );
            })}
          </div>
        </div>
      ))}

      {failed ? (
        <div
          role="status"
          className="rounded-2xl bg-surface-container-low p-4 text-center text-label-md font-semibold text-on-surface"
        >
          Quase! Você acertou {failed.correct} de {failed.total}. Revê a aula e tenta de novo. 💪
        </div>
      ) : null}

      <button
        type="button"
        disabled={!allAnswered || pending}
        onClick={() => onSubmit(sel.map((v) => v ?? -1))}
        className="rounded-2xl bg-primary px-5 py-3 text-title-md font-bold text-white shadow-sm disabled:opacity-40"
      >
        {pending ? 'Enviando…' : failed ? 'Tentar de novo' : 'Enviar respostas'}
      </button>
    </div>
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
