import { useState } from 'react';
import { Icon } from '../components/Icon';
import { allowedSites, type AllowedSite } from '../data/mockData';

const colorMap: Record<AllowedSite['color'], { bg: string; text: string }> = {
  primary: { bg: 'bg-primary-container', text: 'text-on-primary-container' },
  orange: { bg: 'bg-orange-warm/20', text: 'text-orange-warm' },
  mint: { bg: 'bg-secondary-container', text: 'text-on-secondary-container' },
  violet: { bg: 'bg-surface-container-highest', text: 'text-primary' },
};

export function Browser() {
  const [url, setUrl] = useState('guardkids://inicio');

  return (
    <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
      <section className="glass-panel flex items-center gap-2 rounded-2xl px-3 py-2 shadow-ambient">
        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-secondary-container/40 text-secondary">
          <Icon name="lock" className="text-base" filled />
        </div>
        <input
          type="text"
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          aria-label="Endereço"
          className="flex-1 bg-transparent text-label-md text-on-surface outline-none placeholder:text-on-surface-variant"
          placeholder="guardkids://inicio"
        />
        <button
          type="button"
          aria-label="Recarregar"
          className="rounded-full p-2 text-on-surface-variant hover:bg-surface-variant/50"
        >
          <Icon name="refresh" />
        </button>
      </section>

      <section className="rounded-2xl border border-secondary/30 bg-secondary-container/30 p-3">
        <div className="flex items-center gap-2 text-secondary">
          <Icon name="verified_user" className="text-lg" filled />
          <span className="text-label-md font-semibold">Site seguro</span>
        </div>
        <p className="mt-1 text-label-sm text-on-secondary-container">
          Você está em um ambiente protegido. Só aparece o que é seguro pra você.
        </p>
      </section>

      <section className="flex flex-col gap-3">
        <h2 className="px-1 font-display text-headline-md text-primary">Seus sites favoritos</h2>
        <div className="grid grid-cols-2 gap-3">
          {allowedSites.map((site) => (
            <SiteShortcut key={site.id} site={site} />
          ))}
        </div>
      </section>

      <section className="glass-panel mt-2 flex items-center justify-between gap-3 rounded-2xl p-4 shadow-ambient">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-orange-warm/15 text-orange-warm">
            <Icon name="add_link" className="text-2xl" filled />
          </div>
          <div>
            <h3 className="font-display text-label-md font-bold text-on-surface">
              Site novo?
            </h3>
            <p className="text-label-sm text-on-surface-variant">
              Peça pra seus pais liberarem.
            </p>
          </div>
        </div>
        <button
          type="button"
          className="rounded-xl bg-orange-warm px-4 py-2 text-label-md font-semibold text-white shadow-sm transition-colors hover:bg-orange-warm/90"
        >
          Pedir
        </button>
      </section>

      <section className="rounded-2xl border-2 border-dashed border-outline-variant bg-surface-container-low p-5 text-center">
        <Icon name="shield" className="text-4xl text-primary" filled />
        <p className="mt-2 text-label-md font-semibold text-on-surface">
          Tem dúvida sobre um link?
        </p>
        <p className="mt-1 text-label-sm text-on-surface-variant">
          Pergunte para seus pais antes de clicar.
        </p>
      </section>
    </main>
  );
}

function SiteShortcut({ site }: { site: AllowedSite }) {
  const tone = colorMap[site.color];
  return (
    <button
      type="button"
      className="glass-panel flex flex-col items-start gap-3 rounded-2xl p-4 text-left shadow-ambient transition-transform active:scale-95"
    >
      <div className={`flex h-12 w-12 items-center justify-center rounded-xl ${tone.bg} ${tone.text}`}>
        <Icon name={site.icon} className="text-2xl" filled />
      </div>
      <div>
        <div className="font-display text-label-md font-bold text-on-surface">{site.name}</div>
        <div className="text-label-sm text-on-surface-variant">{site.description}</div>
      </div>
      <div className="mt-1 flex items-center gap-1 text-label-sm text-secondary">
        <Icon name="lock" className="text-sm" filled />
        <span className="truncate">{site.domain}</span>
      </div>
    </button>
  );
}
