import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  approveContent, createContent, deleteContent, getAnalytics, getContentSummary,
  listContentCategories, listContents, revokeContent, updateContent,
  type Content, type ContentInput,
} from '../api/content';
import { listChildren } from '../api/children';
import { ContentForm } from '../components/ContentForm';
import { MutationError } from '../components/MutationError';
import { RecommendationManager } from '../components/RecommendationManager';

function AnalyticsCard({ title, rows }: { title: string; rows: { label: string; value: string | number }[] }) {
  return (
    <div className="rounded-2xl border border-outline-variant bg-surface p-4 shadow-sm">
      <h3 className="mb-2 text-label-md font-bold text-on-surface">{title}</h3>
      {rows.length === 0 ? (
        <p className="text-label-sm text-on-surface-variant">Sem dados ainda.</p>
      ) : (
        <ul className="space-y-1">
          {rows.map((r) => (
            <li key={r.label} className="flex justify-between text-label-sm">
              <span className="text-on-surface-variant">{r.label}</span>
              <span className="font-semibold text-on-surface">{r.value}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function ContentDashboard() {
  const qc = useQueryClient();
  const [statusFilter, setStatusFilter] = useState<'' | 'pending' | 'approved'>('');
  const analytics = useQuery({ queryKey: ['content', 'analytics'], queryFn: getAnalytics });
  const categories = useQuery({ queryKey: ['content', 'categories'], queryFn: listContentCategories });
  const contents = useQuery({ queryKey: ['content', 'list', statusFilter], queryFn: () => listContents(0, '', statusFilter) });
  const summary = useQuery({ queryKey: ['content', 'summary'], queryFn: getContentSummary });
  const children = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const [editing, setEditing] = useState<Content | null>(null);
  const [creating, setCreating] = useState(false);
  const [recChild, setRecChild] = useState(0);

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['content', 'list'] });
    qc.invalidateQueries({ queryKey: ['content', 'analytics'] });
    qc.invalidateQueries({ queryKey: ['content', 'summary'] });
  };
  const save = useMutation({
    mutationFn: (input: ContentInput) => (editing ? updateContent(editing.id, input) : createContent(input)),
    onSuccess: () => { invalidate(); setEditing(null); setCreating(false); },
  });
  // onError em todas: sem isso o botão não faz nada visível quando o servidor
  // recusa, e o usuário conclui que o app está quebrado.
  const remove = useMutation({ mutationFn: (id: number) => deleteContent(id), onSuccess: invalidate });
  const approve = useMutation({ mutationFn: (id: number) => approveContent(id), onSuccess: invalidate });
  const revoke = useMutation({ mutationFn: (id: number) => revokeContent(id), onSuccess: invalidate });
  const acaoErro = remove.error ?? approve.error ?? revoke.error;

  const a = analytics.data;
  const list = contents.data ?? [];

  return (
    <main className="mx-auto w-full max-w-[1440px] flex-1 space-y-6 p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <h1 className="font-display text-headline-lg text-on-background">Conteúdo Infantil</h1>
          {(summary.data?.pendingCount ?? 0) > 0 && (
            <span className="rounded-full bg-amber-500/15 px-3 py-1 text-label-sm font-semibold text-amber-700">
              {summary.data?.pendingCount} pendente{summary.data?.pendingCount === 1 ? '' : 's'}
            </span>
          )}
        </div>
        <button type="button" onClick={() => { setEditing(null); setCreating(true); }} className="rounded-xl bg-primary px-5 py-2.5 text-label-md font-semibold text-white">
          Adicionar Conteúdo
        </button>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        <AnalyticsCard title="Mais acessados" rows={(a?.mostAccessed ?? []).map((m) => ({ label: m.title, value: `${m.opens}×` }))} />
        <AnalyticsCard title="Categorias favoritas" rows={(a?.favoriteCategories ?? []).map((c) => ({ label: c.category, value: `${c.opens}×` }))} />
        <AnalyticsCard title="Tempo por categoria" rows={(a?.timePerCategory ?? []).map((t) => ({ label: t.category, value: `${t.minutes} min` }))} />
      </div>

      <div className="flex gap-2" role="group" aria-label="Filtrar por status">
        {([['', 'Todos'], ['pending', 'Pendentes'], ['approved', 'Aprovados']] as const).map(([value, label]) => (
          <button
            key={value}
            type="button"
            onClick={() => setStatusFilter(value)}
            className={`rounded-full px-4 py-1.5 text-label-sm font-semibold ${
              statusFilter === value ? 'bg-primary text-white' : 'bg-surface-container-low text-on-surface-variant'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {acaoErro ? <MutationError error={acaoErro} /> : null}

      {contents.isLoading ? (
        <div className="h-24 animate-pulse rounded-2xl bg-surface-container-low" />
      ) : list.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-outline-variant p-10 text-center">
          <span className="material-symbols-outlined text-5xl text-outline">inventory_2</span>
          <p className="text-label-lg font-semibold text-on-surface">Nenhum conteúdo cadastrado</p>
        </div>
      ) : (
        <div className="divide-y divide-outline-variant rounded-2xl border border-outline-variant bg-surface">
          {list.map((c) => (
            <div key={c.id} className="flex items-center justify-between p-3">
              <div>
                <div className="flex items-center gap-2">
                  <span className="text-label-md font-semibold text-on-surface">{c.title}</span>
                  <span className={`rounded-full px-2 py-0.5 text-label-sm font-semibold ${
                    c.status === 'approved' ? 'bg-green-500/15 text-green-700' : 'bg-amber-500/15 text-amber-700'
                  }`}>
                    {c.status === 'approved' ? 'Aprovado' : 'Pendente'}
                  </span>
                </div>
                <div className="text-label-sm text-on-surface-variant">{c.ageMin}–{c.ageMax} anos{c.tags ? ` · ${c.tags}` : ''}</div>
              </div>
              <div className="flex gap-2">
                {c.status === 'pending' ? (
                  <button type="button" onClick={() => approve.mutate(c.id)} className="text-green-700">Aprovar</button>
                ) : (
                  <button type="button" onClick={() => revoke.mutate(c.id)} className="text-amber-700">Revogar</button>
                )}
                <button type="button" onClick={() => { setCreating(false); setEditing(c); }} className="text-primary">Editar</button>
                <button type="button" onClick={() => remove.mutate(c.id)} className="text-error">Excluir</button>
              </div>
            </div>
          ))}
        </div>
      )}

      <section className="space-y-3 rounded-2xl border border-outline-variant bg-surface p-4">
        <div className="flex items-center gap-3">
          <h2 className="font-display text-headline-md text-on-surface">Recomendações por filho</h2>
          <select
            aria-label="Filho"
            value={recChild}
            onChange={(e) => setRecChild(Number(e.target.value))}
            className="rounded-lg border border-outline-variant p-2"
          >
            <option value={0} disabled>Escolher filho…</option>
            {(children.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </div>
        {recChild > 0 && (
          <RecommendationManager childId={recChild} contentOptions={list.map((c) => ({ id: c.id, title: c.title }))} />
        )}
      </section>

      {(creating || editing) && (
        <ContentForm
          categories={categories.data ?? []}
          initial={editing ?? undefined}
          onSubmit={(input) => save.mutateAsync(input).then(() => undefined)}
          onClose={() => { setCreating(false); setEditing(null); }}
        />
      )}
    </main>
  );
}
