import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { listAllowedSites } from '../api/child';
import type { AllowedSite } from '../api/types';
import { Icon } from '../components/Icon';
import { getActiveTracker } from '../lib/usageTracker';
import type { PageId } from '../data/mockData';

type BrowserProps = { onNavigate: (page: PageId) => void };

const TONES = [
  { bg: 'bg-primary-container', text: 'text-on-primary-container' },
  { bg: 'bg-orange-warm/20', text: 'text-orange-warm' },
  { bg: 'bg-secondary-container', text: 'text-on-secondary-container' },
  { bg: 'bg-surface-container-highest', text: 'text-primary' },
];

/** Monta uma URL navegável do domínio da whitelist (que pode vir com ou sem protocolo). */
function toUrl(domain: string): string {
  const trimmed = domain.trim();
  return /^https?:\/\//i.test(trimmed) ? trimmed : `https://${trimmed}`;
}

export function Browser({ onNavigate }: BrowserProps) {
  const [url, setUrl] = useState('guardkids://inicio');
  const sitesQuery = useQuery({ queryKey: ['child', 'sites'], queryFn: listAllowedSites });

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

        {sitesQuery.isLoading && (
          <div className="grid grid-cols-2 gap-3">
            <div className="glass-panel h-32 animate-pulse rounded-2xl bg-surface-container-low" />
            <div className="glass-panel h-32 animate-pulse rounded-2xl bg-surface-container-low" />
          </div>
        )}

        {sitesQuery.error && (
          <div className="glass-panel flex flex-col items-center gap-2 rounded-2xl bg-error/5 p-4 text-error">
            <Icon name="error" className="text-2xl" />
            <p className="text-label-sm">Não deu pra carregar seus sites agora.</p>
          </div>
        )}

        {sitesQuery.data && sitesQuery.data.length === 0 && (
          <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl p-6 text-center text-on-surface-variant">
            <Icon name="travel_explore" className="text-3xl text-primary" filled />
            <p className="text-label-md font-semibold">Nenhum site liberado ainda</p>
            <p className="text-label-sm">Peça pros seus pais liberarem um site pra você.</p>
          </div>
        )}

        {sitesQuery.data && sitesQuery.data.length > 0 && (
          <div className="grid grid-cols-2 gap-3">
            {sitesQuery.data.map((site, i) => (
              <SiteShortcut key={site.domain} site={site} tone={TONES[i % TONES.length]} />
            ))}
          </div>
        )}
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
          onClick={() => onNavigate('requests')}
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

function SiteShortcut({
  site,
  tone,
}: {
  site: AllowedSite;
  tone: { bg: string; text: string };
}) {
  function onClick() {
    getActiveTracker()?.trackSiteOpen(site.domain);
    window.open(toUrl(site.domain), '_blank', 'noopener,noreferrer');
  }
  return (
    <button
      type="button"
      onClick={onClick}
      className="glass-panel flex flex-col items-start gap-3 rounded-2xl p-4 text-left shadow-ambient transition-transform active:scale-95"
    >
      <div className={`flex h-12 w-12 items-center justify-center rounded-xl ${tone.bg} ${tone.text}`}>
        <Icon name="public" className="text-2xl" filled />
      </div>
      <div className="w-full">
        <div className="truncate font-display text-label-md font-bold text-on-surface">{site.domain}</div>
        <div className="text-label-sm text-on-surface-variant">{site.category ?? 'Site liberado'}</div>
      </div>
      <div className="mt-1 flex items-center gap-1 text-label-sm text-secondary">
        <Icon name="lock" className="text-sm" filled />
        <span>Seguro</span>
      </div>
    </button>
  );
}
