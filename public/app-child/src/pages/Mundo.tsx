import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { browseLibrary, listChildRecommendations, listLibraryCategories, recordHistory } from '../api/content';
import type { Content } from '../api/types';
import { EmptyState } from '../components/EmptyState';
import { Icon } from '../components/Icon';
import { Skeleton } from '../components/Skeleton';

function toUrl(domain: string): string {
  const t = domain.trim();
  return /^https?:\/\//i.test(t) ? t : `https://${t}`;
}

export function Mundo() {
  const [category, setCategory] = useState(0);
  const [search, setSearch] = useState('');
  const cats = useQuery({ queryKey: ['library', 'cats'], queryFn: listLibraryCategories });
  const recs = useQuery({ queryKey: ['library', 'recs'], queryFn: listChildRecommendations });
  const items = useQuery({ queryKey: ['library', 'items', category, search], queryFn: () => browseLibrary(category, search) });

  function open(c: Content) {
    if (c.url) {
      recordHistory(c.id, 'open', 0).catch(() => {});
      window.open(toUrl(c.url), '_blank', 'noopener,noreferrer');
    }
  }

  const list = items.data ?? [];

  return (
    <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
      <div className="glass-panel flex items-center gap-2 rounded-2xl px-3 py-2 shadow-ambient">
        <Icon name="search" className="text-base text-on-surface-variant" />
        <input
          aria-label="Buscar"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Buscar na biblioteca"
          className="flex-1 bg-transparent text-label-md text-on-surface outline-none"
        />
      </div>

      {recs.data && recs.data.length > 0 && (
        <section>
          <h2 className="mb-2 px-1 font-display text-headline-md text-primary">Indicados pra você</h2>
          <div className="flex gap-3 overflow-x-auto pb-1">
            {recs.data.map((r) => (
              <button key={r.id} type="button" onClick={() => open(r.content)} className="glass-panel min-w-[140px] shrink-0 rounded-2xl p-3 text-left shadow-ambient">
                <Icon name="recommend" className="text-primary" filled />
                <div className="mt-1 text-label-md font-bold text-on-surface">{r.content.title}</div>
              </button>
            ))}
          </div>
        </section>
      )}

      <div className="-mx-1 flex gap-2 overflow-x-auto px-1">
        <Chip active={category === 0} label="Tudo" onClick={() => setCategory(0)} />
        {(cats.data ?? []).map((c) => (
          <Chip key={c.id} active={category === c.id} label={`${c.name} (${c.count})`} onClick={() => setCategory(c.id)} />
        ))}
      </div>

      {items.isLoading ? (
        <Skeleton />
      ) : items.error ? (
        <div className="glass-panel flex flex-col items-center gap-2 rounded-2xl bg-error/5 p-4 text-error">
          <Icon name="error" className="text-2xl" />
          <p className="text-label-sm">Não deu pra carregar agora.</p>
        </div>
      ) : list.length === 0 ? (
        <EmptyState icon="auto_stories" message={search ? `Nada encontrado pra "${search}"` : 'Nada por aqui ainda. Seu mundo será preenchido pelo papai.'} />
      ) : (
        <div className="grid grid-cols-2 gap-3">
          {list.map((c) => (
            <button key={c.id} type="button" onClick={() => open(c)} className="glass-panel flex flex-col gap-2 rounded-2xl p-4 text-left shadow-ambient active:scale-95">
              <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
                <Icon name="play_circle" className="text-2xl" filled />
              </div>
              <div className="font-display text-label-md font-bold text-on-surface">{c.title}</div>
              {c.description && <div className="text-label-sm text-on-surface-variant">{c.description}</div>}
            </button>
          ))}
        </div>
      )}
    </main>
  );
}

function Chip({ active, label, onClick }: { active: boolean; label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        active
          ? 'shrink-0 rounded-full bg-primary px-3 py-1.5 text-label-sm font-semibold text-white'
          : 'shrink-0 rounded-full border border-outline-variant bg-white px-3 py-1.5 text-label-sm font-semibold text-on-surface'
      }
    >
      {label}
    </button>
  );
}
