import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState, type FormEvent } from 'react';
import { listCategories, updateCategoryBlocked } from '../api/categories';
import { listChildren } from '../api/children';
import { ApiError } from '../api/client';
import { createSite, deleteSite, listSites } from '../api/sites';
import type { Category, Child, Site, SiteListType } from '../api/types';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';

type Tab = 'whitelist' | 'blacklist' | 'categories';

export function SitesRules() {
  const [tab, setTab] = useState<Tab>('whitelist');
  const [query, setQuery] = useState('');

  const sitesQuery = useQuery({ queryKey: ['sites', 'all'], queryFn: () => listSites('all') });
  const categoriesQuery = useQuery({ queryKey: ['categories'], queryFn: listCategories });
  const childrenQuery = useQuery({ queryKey: ['children'], queryFn: listChildren });

  const { whitelist, blacklist } = useMemo(() => {
    const items = sitesQuery.data ?? [];
    return {
      whitelist: items.filter((s) => s.listType === 'whitelist'),
      blacklist: items.filter((s) => s.listType === 'blacklist'),
    };
  }, [sitesQuery.data]);

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Sites & Regras"
        subtitle="Controle exatamente o que cada filho pode acessar."
        action={
          <div className="relative w-full md:w-80">
            <Icon
              name="search"
              className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-on-surface-variant"
            />
            <input
              type="search"
              placeholder="Buscar site ou categoria…"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              className="w-full rounded-full border border-outline-variant bg-white py-2.5 pl-10 pr-4 text-label-md text-on-surface shadow-sm placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
            />
          </div>
        }
      />

      <div className="glass-panel inline-flex w-fit gap-1 rounded-full p-1 shadow-ambient">
        <TabButton active={tab === 'whitelist'} onClick={() => setTab('whitelist')} label="Permitidos" count={whitelist.length} />
        <TabButton active={tab === 'blacklist'} onClick={() => setTab('blacklist')} label="Bloqueados" count={blacklist.length} />
        <TabButton active={tab === 'categories'} onClick={() => setTab('categories')} label="Categorias" count={categoriesQuery.data?.length ?? 0} />
      </div>

      {tab !== 'categories' && (
        <SitesTab
          kind={tab}
          items={tab === 'whitelist' ? whitelist : blacklist}
          query={query}
          children={childrenQuery.data}
          loading={sitesQuery.isLoading}
          error={sitesQuery.error}
        />
      )}
      {tab === 'categories' && (
        <CategoriesTab
          items={categoriesQuery.data ?? []}
          query={query}
          loading={categoriesQuery.isLoading}
          error={categoriesQuery.error}
        />
      )}
    </main>
  );
}

function SitesTab({
  kind,
  items,
  query,
  children,
  loading,
  error,
}: {
  kind: SiteListType;
  items: Site[];
  query: string;
  children: Child[] | undefined;
  loading: boolean;
  error: unknown;
}) {
  const queryClient = useQueryClient();
  const [domain, setDomain] = useState('');
  const [applies, setApplies] = useState<'all' | string>('all');

  const create = useMutation({
    mutationFn: createSite,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites'] });
      setDomain('');
      setApplies('all');
    },
  });
  const remove = useMutation({
    mutationFn: deleteSite,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sites'] }),
  });

  function submit(e: FormEvent) {
    e.preventDefault();
    const d = domain.trim().toLowerCase();
    if (!d) return;
    create.mutate({
      domain: d,
      list_type: kind,
      applies_to: applies === 'all' ? [] : [Number(applies)],
    });
  }

  const filtered = useMemo(() => {
    const v = query.trim().toLowerCase();
    if (!v) return items;
    return items.filter(
      (s) =>
        s.domain.toLowerCase().includes(v) ||
        (s.category ?? '').toLowerCase().includes(v) ||
        s.appliesTo.some((id) => {
          const c = children?.find((x) => x.id === id);
          return c ? c.name.toLowerCase().includes(v) : false;
        }),
    );
  }, [items, query, children]);

  const accent =
    kind === 'whitelist'
      ? { icon: 'check_circle', toneText: 'text-secondary', toneBg: 'bg-secondary-container/40' }
      : { icon: 'block', toneText: 'text-error', toneBg: 'bg-error-container/60' };

  return (
    <section className="space-y-4">
      <form
        onSubmit={submit}
        className="glass-panel rounded-2xl p-4 shadow-ambient"
      >
        <div className="flex flex-col gap-3 md:flex-row md:items-center">
          <Icon
            name={kind === 'whitelist' ? 'add_link' : 'link_off'}
            className="hidden text-2xl text-primary md:block"
          />
          <input
            type="text"
            value={domain}
            onChange={(e) => setDomain(e.target.value)}
            placeholder={
              kind === 'whitelist'
                ? 'Adicionar domínio permitido (ex: khanacademy.org)'
                : 'Adicionar domínio bloqueado (ex: tiktok.com)'
            }
            className="flex-1 rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
          />
          <select
            value={applies}
            onChange={(e) => setApplies(e.target.value)}
            className="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-label-md text-on-surface focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
          >
            <option value="all">Aplicar a todos</option>
            {(children ?? []).map((c) => (
              <option key={c.id} value={c.id}>
                Só {c.name}
              </option>
            ))}
          </select>
          <button
            type="submit"
            disabled={create.isPending || !domain.trim()}
            className={`inline-flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-label-md font-semibold text-white transition-colors disabled:opacity-60 ${
              kind === 'whitelist' ? 'bg-secondary hover:bg-secondary/90' : 'bg-error hover:bg-error/90'
            }`}
          >
            <Icon
              name={create.isPending ? 'progress_activity' : 'add'}
              className={create.isPending ? 'animate-spin' : ''}
            />
            Adicionar
          </button>
        </div>
        {create.error ? <MutationError prefix="Falha ao adicionar" error={create.error} /> : null}
      </form>

      {loading && (
        <div className="glass-panel h-32 animate-pulse rounded-2xl bg-surface-container-low" />
      )}
      {error ? <ListError error={error} label="sites" /> : null}

      {!loading && !error && filtered.length === 0 && (
        <EmptyState
          icon="search_off"
          title="Nada encontrado"
          subtitle={
            query.trim()
              ? 'Refine sua busca ou adicione um novo domínio acima.'
              : `Sem domínios na lista de ${kind === 'whitelist' ? 'permitidos' : 'bloqueados'} ainda.`
          }
        />
      )}

      {!loading && !error && filtered.length > 0 && (
        <div className="glass-panel rounded-2xl shadow-ambient">
          <ul className="divide-y divide-outline-variant/50">
            {filtered.map((s) => {
              const appliesText =
                s.appliesTo.length === 0
                  ? 'Todos os filhos'
                  : s.appliesTo
                      .map((id) => children?.find((c) => c.id === id)?.name ?? `#${id}`)
                      .join(', ');
              const busy = remove.isPending && remove.variables === s.id;
              return (
                <li
                  key={s.id}
                  className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center"
                >
                  <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${accent.toneBg}`}>
                    <Icon name={accent.icon} className={accent.toneText} filled />
                  </div>
                  <div className="flex-1">
                    <div className="text-label-md font-semibold text-on-surface">{s.domain}</div>
                    <div className="text-label-sm text-on-surface-variant">
                      {s.category ? `${s.category} • ` : ''}
                      Aplica a {appliesText}
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => remove.mutate(s.id)}
                      className="flex items-center gap-1 rounded-lg border border-error/30 bg-error-container/40 px-3 py-1.5 text-label-sm font-semibold text-error hover:bg-error-container/60 disabled:opacity-50"
                    >
                      <Icon
                        name={busy ? 'progress_activity' : 'delete'}
                        className={`text-sm ${busy ? 'animate-spin' : ''}`}
                      />
                      Remover
                    </button>
                  </div>
                </li>
              );
            })}
          </ul>
        </div>
      )}
    </section>
  );
}

function CategoriesTab({
  items,
  query,
  loading,
  error,
}: {
  items: Category[];
  query: string;
  loading: boolean;
  error: unknown;
}) {
  const filtered = useMemo(() => {
    const v = query.trim().toLowerCase();
    if (!v) return items;
    return items.filter(
      (c) =>
        c.name.toLowerCase().includes(v) ||
        (c.description ?? '').toLowerCase().includes(v),
    );
  }, [items, query]);

  if (loading) {
    return (
      <div className="grid grid-cols-1 gap-gutter md:grid-cols-2 xl:grid-cols-3">
        {[0, 1, 2].map((i) => (
          <div
            key={i}
            className="glass-panel h-40 animate-pulse rounded-2xl bg-surface-container-low"
          />
        ))}
      </div>
    );
  }
  if (error) return <ListError error={error} label="categorias" />;
  if (filtered.length === 0) {
    return (
      <EmptyState
        icon="search_off"
        title="Nenhuma categoria encontrada"
        subtitle="Tente outro termo."
      />
    );
  }
  return (
    <div className="grid grid-cols-1 gap-gutter md:grid-cols-2 xl:grid-cols-3">
      {filtered.map((c) => (
        <CategoryCard key={c.id} category={c} />
      ))}
    </div>
  );
}

function CategoryCard({ category }: { category: Category }) {
  const queryClient = useQueryClient();
  const toggle = useMutation({
    mutationFn: (blocked: boolean) => updateCategoryBlocked(category.id, blocked),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['categories'] }),
  });
  const blocked = category.blocked;
  const busy = toggle.isPending;

  return (
    <article className="glass-panel flex flex-col gap-3 rounded-2xl p-5 shadow-ambient">
      <div className="flex items-start justify-between">
        <div
          className={`flex h-12 w-12 items-center justify-center rounded-xl ${
            blocked ? 'bg-error-container/60 text-error' : 'bg-surface-container-high text-primary'
          }`}
        >
          <Icon name={category.icon ?? 'category'} className="text-2xl" filled />
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={blocked}
          disabled={busy}
          onClick={() => toggle.mutate(!blocked)}
          className={`relative inline-flex h-7 w-12 items-center rounded-full transition-colors disabled:opacity-60 ${
            blocked ? 'bg-primary' : 'bg-outline-variant'
          }`}
        >
          <span
            className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform ${
              blocked ? 'translate-x-6' : 'translate-x-1'
            }`}
          />
        </button>
      </div>
      <div>
        <h3 className="font-display text-headline-md text-on-surface">{category.name}</h3>
        {category.description && (
          <p className="mt-1 text-label-sm text-on-surface-variant">{category.description}</p>
        )}
      </div>
      <span
        className={`inline-flex w-fit items-center gap-1 rounded-full px-3 py-1 text-label-sm font-semibold ${
          blocked
            ? 'bg-error-container/60 text-on-error-container'
            : 'bg-secondary-container/40 text-secondary'
        }`}
      >
        <Icon name={blocked ? 'block' : 'check_circle'} className="text-sm" filled />
        {blocked ? 'Bloqueada para todos' : 'Permitida'}
      </span>
      {toggle.error ? <MutationError prefix="Falha ao atualizar" error={toggle.error} /> : null}
    </article>
  );
}

function TabButton({
  active,
  onClick,
  label,
  count,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  count?: number;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        active
          ? 'flex items-center gap-2 rounded-full bg-primary px-5 py-2 text-label-md font-semibold text-white shadow-sm'
          : 'flex items-center gap-2 rounded-full px-5 py-2 text-label-md font-semibold text-on-surface-variant transition-colors hover:bg-surface-container'
      }
    >
      {label}
      {count != null && (
        <span
          className={`rounded-full px-2 py-0.5 text-xs font-bold ${
            active ? 'bg-white/20 text-white' : 'bg-surface-container text-on-surface-variant'
          }`}
        >
          {count}
        </span>
      )}
    </button>
  );
}

function MutationError({ prefix, error }: { prefix: string; error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'erro desconhecido';
  return (
    <p role="alert" className="mt-3 rounded-lg bg-error/10 p-2 text-label-sm text-error">
      {prefix}: {message}
    </p>
  );
}

function ListError({ error, label }: { error: unknown; label: string }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar {label}</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}

function EmptyState({
  icon,
  title,
  subtitle,
}: {
  icon: string;
  title: string;
  subtitle: string;
}) {
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-3 rounded-2xl p-12 text-center shadow-ambient">
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-high text-primary">
        <Icon name={icon} className="text-3xl" />
      </div>
      <h3 className="font-display text-headline-md text-on-surface">{title}</h3>
      <p className="text-body-md text-on-surface-variant">{subtitle}</p>
    </div>
  );
}
