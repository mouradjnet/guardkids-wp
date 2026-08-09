import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

import { recommend } from '../academy/engine';
import { findLesson } from '../academy/lessons';
import { useAcademyContext } from '../academy/useAcademyContext';
import { completeLesson, dismissLesson } from '../api/academy';
import type { PageId } from '../data/mockData';
import { AcademyPanel } from './AcademyPanel';
import { Icon } from './Icon';

/**
 * Entrada contextual do Academy. Lê a tela ativa + o estado da família, pergunta
 * ao engine qual a próxima aula, e — quando há uma — mostra uma pílula flutuante.
 * Sem recomendação, não renderiza nada (é isso que faz o botão "aparecer na tela
 * certa"). Concluir/dispensar invalida o progresso, então a recomendação some ou
 * dá lugar à próxima.
 */
export function AcademyButton({ screen }: { screen: PageId }) {
  const context = useAcademyContext(screen);
  const recommendation = recommend(context);
  const [open, setOpen] = useState(false);
  const queryClient = useQueryClient();

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['academy', 'progress'] });

  const completeMutation = useMutation({
    mutationFn: (lessonId: string) => completeLesson(lessonId),
    onSuccess: () => {
      invalidate();
      setOpen(false);
    },
  });

  const dismissMutation = useMutation({
    mutationFn: (lessonId: string) => dismissLesson(lessonId),
    onSuccess: () => {
      invalidate();
      setOpen(false);
    },
  });

  if (!recommendation) {
    return null;
  }

  const lesson = findLesson(recommendation.lessonId);
  const busy = completeMutation.isPending || dismissMutation.isPending;

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="fixed bottom-20 right-4 z-[90] flex max-w-[16rem] items-center gap-2 rounded-full bg-primary px-4 py-3 text-label-lg text-white shadow-lg hover:bg-primary-container md:bottom-6"
      >
        <Icon name="school" filled />
        <span className="truncate">{recommendation.title}</span>
      </button>

      {open && lesson ? (
        <AcademyPanel
          title={lesson.title}
          reason={recommendation.reason}
          body={lesson.body}
          busy={busy}
          onClose={() => setOpen(false)}
          onComplete={() => completeMutation.mutate(recommendation.lessonId)}
          onDismiss={() => dismissMutation.mutate(recommendation.lessonId)}
        />
      ) : null}
    </>
  );
}
