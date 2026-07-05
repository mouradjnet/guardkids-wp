import { useState, type FormEvent } from 'react';
import type { Content, ContentCategory, ContentInput } from '../api/content';

const AGE_BUCKETS: Record<string, [number, number]> = {
  '4-6': [4, 6], '7-9': [7, 9], '10-13': [10, 13], '14-16': [14, 16],
};
const LEVELS = ['iniciante', 'intermediário', 'avançado'];

type ContentFormProps = {
  categories: ContentCategory[];
  initial?: Content;
  onSubmit: (input: ContentInput) => Promise<void>;
  onClose: () => void;
};

function bucketOf(min: number, max: number): string {
  const found = Object.entries(AGE_BUCKETS).find(([, [a, b]]) => a === min && b === max);
  return found ? found[0] : '4-6';
}

export function ContentForm({ categories, initial, onSubmit, onClose }: ContentFormProps) {
  const [title, setTitle] = useState(initial?.title ?? '');
  const [description, setDescription] = useState(initial?.description ?? '');
  const [categoryId, setCategoryId] = useState(initial?.categoryId ?? categories[0]?.id ?? 0);
  const [bucket, setBucket] = useState(initial ? bucketOf(initial.ageMin, initial.ageMax) : '4-6');
  const [url, setUrl] = useState(initial?.url ?? '');
  const [thumbnail, setThumbnail] = useState(initial?.thumbnail ?? '');
  const [minutes, setMinutes] = useState(initial?.estimatedMinutes?.toString() ?? '');
  const [level, setLevel] = useState(initial?.level ?? LEVELS[0]);
  const [tags, setTags] = useState(initial?.tags ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!title.trim()) return;
    setBusy(true);
    setError(null);
    const [ageMin, ageMax] = AGE_BUCKETS[bucket];
    try {
      await onSubmit({
        title: title.trim(),
        description: description || undefined,
        categoryId: categoryId || undefined,
        ageMin,
        ageMax,
        url: url || undefined,
        thumbnail: thumbnail || undefined,
        estimatedMinutes: minutes ? Number(minutes) : undefined,
        level,
        tags: tags || undefined,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Não foi possível salvar.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <form onClick={(e) => e.stopPropagation()} onSubmit={submit} className="w-full max-w-lg space-y-3 rounded-2xl bg-surface p-6 shadow-lg">
        <h2 className="font-display text-headline-md text-on-surface">{initial ? 'Editar conteúdo' : 'Novo conteúdo'}</h2>
        <label className="block text-label-md">Título
          <input aria-label="Título" value={title} onChange={(e) => setTitle(e.target.value)} required className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
        </label>
        <label className="block text-label-md">Descrição
          <textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2} className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className="block text-label-md">Categoria
            <select value={categoryId} onChange={(e) => setCategoryId(Number(e.target.value))} className="mt-1 w-full rounded-lg border border-outline-variant p-2">
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </label>
          <label className="block text-label-md">Faixa etária
            <select aria-label="Faixa etária" value={bucket} onChange={(e) => setBucket(e.target.value)} className="mt-1 w-full rounded-lg border border-outline-variant p-2">
              {Object.keys(AGE_BUCKETS).map((b) => <option key={b} value={b}>{b}</option>)}
            </select>
          </label>
        </div>
        <label className="block text-label-md">Link
          <input value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://..." className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
        </label>
        <label className="block text-label-md">Miniatura (URL)
          <input value={thumbnail} onChange={(e) => setThumbnail(e.target.value)} placeholder="https://..." className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
        </label>
        <div className="grid grid-cols-3 gap-3">
          <label className="block text-label-md">Tempo (min)
            <input type="number" value={minutes} onChange={(e) => setMinutes(e.target.value)} className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
          </label>
          <label className="block text-label-md">Nível
            <select value={level} onChange={(e) => setLevel(e.target.value)} className="mt-1 w-full rounded-lg border border-outline-variant p-2">
              {LEVELS.map((l) => <option key={l} value={l}>{l}</option>)}
            </select>
          </label>
          <label className="block text-label-md">Tags
            <input value={tags} onChange={(e) => setTags(e.target.value)} placeholder="jogo, online" className="mt-1 w-full rounded-lg border border-outline-variant p-2" />
          </label>
        </div>
        {error && <p role="alert" className="rounded-lg bg-error/10 p-3 text-label-sm text-error">{error}</p>}
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" onClick={onClose} className="rounded-lg px-4 py-2 text-on-surface-variant">Cancelar</button>
          <button type="submit" disabled={busy} className="rounded-lg bg-primary px-4 py-2 font-semibold text-white disabled:opacity-60">{busy ? 'Salvando…' : 'Salvar'}</button>
        </div>
      </form>
    </div>
  );
}
