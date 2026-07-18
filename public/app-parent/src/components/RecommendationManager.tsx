import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  createRecommendation, deleteRecommendation, listRecommendations, reorderRecommendations,
  type Recommendation,
} from '../api/content';
import { MutationError } from './MutationError';

type RecommendationManagerProps = { childId: number; contentOptions: { id: number; title: string }[] };

export function RecommendationManager({ childId, contentOptions }: RecommendationManagerProps) {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ['content', 'recs', childId], queryFn: () => listRecommendations(childId) });
  const invalidate = () => qc.invalidateQueries({ queryKey: ['content', 'recs', childId] });
  const add = useMutation({ mutationFn: (contentId: number) => createRecommendation(childId, contentId), onSuccess: invalidate });
  const remove = useMutation({ mutationFn: (id: number) => deleteRecommendation(id), onSuccess: invalidate });
  const reorder = useMutation({ mutationFn: (ids: number[]) => reorderRecommendations(childId, ids), onSuccess: invalidate });

  const recs = query.data ?? [];
  function move(index: number, dir: -1 | 1) {
    const next = [...recs];
    const j = index + dir;
    if (j < 0 || j >= next.length) return;
    [next[index], next[j]] = [next[j], next[index]];
    reorder.mutate(next.map((r) => r.id));
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <select id="rec-add" className="rounded-lg border border-outline-variant p-2" defaultValue="">
          <option value="" disabled>Escolher conteúdo…</option>
          {contentOptions.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
        </select>
        <button
          type="button"
          onClick={() => {
            const el = document.getElementById('rec-add') as HTMLSelectElement | null;
            if (el && el.value) add.mutate(Number(el.value));
          }}
          className="rounded-lg bg-primary px-3 py-2 text-label-md font-semibold text-white"
        >
          Adicionar
        </button>
      </div>
      {(add.isError || remove.isError || reorder.isError) && (
        <MutationError
          error={add.error ?? remove.error ?? reorder.error}
          prefix="Falha na recomendação"
        />
      )}
      {recs.length === 0 && <p className="text-label-sm text-on-surface-variant">Nenhuma recomendação para este filho.</p>}
      <ul className="space-y-1">
        {recs.map((r: Recommendation, i) => (
          <li key={r.id} className="flex items-center justify-between rounded-lg border border-outline-variant p-2">
            <span className="text-label-md text-on-surface">Conteúdo #{r.contentId}{r.note ? ` — ${r.note}` : ''}</span>
            <span className="flex gap-1">
              <button type="button" aria-label="Subir" onClick={() => move(i, -1)} className="px-2">↑</button>
              <button type="button" aria-label="Descer" onClick={() => move(i, 1)} className="px-2">↓</button>
              <button type="button" aria-label="Remover" onClick={() => remove.mutate(r.id)} className="px-2 text-error">✕</button>
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
