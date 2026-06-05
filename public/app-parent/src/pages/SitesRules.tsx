import { useState } from 'react';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import {
  categories,
  siteRules,
  type Category,
  type SiteRule,
} from '../data/mockData';

type Tab = 'whitelist' | 'blacklist' | 'categories';

export function SitesRules() {
  const [tab, setTab] = useState<Tab>('whitelist');
  const [query, setQuery] = useState('');

  const whitelist = siteRules.filter((s) => s.list === 'whitelist');
  const blacklist = siteRules.filter((s) => s.list === 'blacklist');

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
        <TabButton active={tab === 'categories'} onClick={() => setTab('categories')} label="Categorias" count={categories.length} />
      </div>

      {tab === 'whitelist' && (
        <DomainList kind="whitelist" items={filterBy(whitelist, query)} />
      )}
      {tab === 'blacklist' && (
        <DomainList kind="blacklist" items={filterBy(blacklist, query)} />
      )}
      {tab === 'categories' && <CategoryList items={filterCategories(query)} />}
    </main>
  );
}

function filterBy(items: SiteRule[], q: string) {
  const v = q.trim().toLowerCase();
  if (!v) return items;
  return items.filter(
    (s) =>
      s.domain.toLowerCase().includes(v) ||
      s.category.toLowerCase().includes(v) ||
      s.appliesTo.some((p) => p.toLowerCase().includes(v)),
  );
}

function filterCategories(q: string) {
  const v = q.trim().toLowerCase();
  if (!v) return categories;
  return categories.filter(
    (c) => c.name.toLowerCase().includes(v) || c.description.toLowerCase().includes(v),
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

function DomainList({
  kind,
  items,
}: {
  kind: 'whitelist' | 'blacklist';
  items: SiteRule[];
}) {
  const accent =
    kind === 'whitelist'
      ? { icon: 'check_circle', toneText: 'text-secondary', toneBg: 'bg-secondary-container/40' }
      : { icon: 'block', toneText: 'text-error', toneBg: 'bg-error-container/60' };

  return (
    <section className="space-y-4">
      <div className="glass-panel rounded-2xl p-4 shadow-ambient">
        <div className="flex flex-col gap-3 md:flex-row md:items-center">
          <Icon
            name={kind === 'whitelist' ? 'add_link' : 'link_off'}
            className="hidden text-2xl text-primary md:block"
          />
          <input
            type="text"
            placeholder={
              kind === 'whitelist'
                ? 'Adicionar domínio permitido (ex: khanacademy.org)'
                : 'Adicionar domínio bloqueado (ex: tiktok.com)'
            }
            className="flex-1 rounded-xl border border-outline-variant bg-surface-container-low px-4 py-2.5 text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
          />
          <select
            className="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2.5 text-label-md text-on-surface focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
            defaultValue="todos"
          >
            <option value="todos">Aplicar a todos</option>
            <option value="lucas">Só Lucas</option>
            <option value="sofia">Só Sofia</option>
            <option value="theo">Só Théo</option>
          </select>
          <button
            type="button"
            className={`inline-flex items-center justify-center gap-2 rounded-xl px-5 py-2.5 text-label-md font-semibold text-white transition-colors ${
              kind === 'whitelist' ? 'bg-secondary hover:bg-secondary/90' : 'bg-error hover:bg-error/90'
            }`}
          >
            <Icon name="add" />
            Adicionar
          </button>
        </div>
      </div>

      {items.length === 0 ? (
        <EmptyState
          icon="search_off"
          title="Nada encontrado"
          subtitle="Refine sua busca ou adicione um novo domínio acima."
        />
      ) : (
        <div className="glass-panel rounded-2xl shadow-ambient">
          <ul className="divide-y divide-outline-variant/50">
            {items.map((s) => (
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
                    {s.category} • Aplica a {s.appliesTo.join(', ')}
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    className="flex items-center gap-1 rounded-lg border border-outline-variant bg-surface-container px-3 py-1.5 text-label-sm font-semibold text-on-surface hover:bg-surface-variant"
                  >
                    <Icon name="edit" className="text-sm" />
                    Editar
                  </button>
                  <button
                    type="button"
                    className="flex items-center gap-1 rounded-lg border border-error/30 bg-error-container/40 px-3 py-1.5 text-label-sm font-semibold text-error hover:bg-error-container/60"
                  >
                    <Icon name="delete" className="text-sm" />
                    Remover
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}

function CategoryList({ items }: { items: Category[] }) {
  if (items.length === 0) {
    return <EmptyState icon="search_off" title="Nenhuma categoria encontrada" subtitle="Tente outro termo." />;
  }
  return (
    <div className="grid grid-cols-1 gap-gutter md:grid-cols-2 xl:grid-cols-3">
      {items.map((c) => (
        <CategoryCard key={c.id} category={c} />
      ))}
    </div>
  );
}

function CategoryCard({ category }: { category: Category }) {
  const [blocked, setBlocked] = useState(category.blocked);
  return (
    <article className="glass-panel flex flex-col gap-3 rounded-2xl p-5 shadow-ambient">
      <div className="flex items-start justify-between">
        <div
          className={`flex h-12 w-12 items-center justify-center rounded-xl ${
            blocked ? 'bg-error-container/60 text-error' : 'bg-surface-container-high text-primary'
          }`}
        >
          <Icon name={category.icon} className="text-2xl" filled />
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={blocked}
          onClick={() => setBlocked((v) => !v)}
          className={`relative inline-flex h-7 w-12 items-center rounded-full transition-colors ${
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
        <p className="mt-1 text-label-sm text-on-surface-variant">{category.description}</p>
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
    </article>
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
