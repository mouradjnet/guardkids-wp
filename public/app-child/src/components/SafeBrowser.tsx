import { Icon } from './Icon';
import type { PageId } from '../data/mockData';

type SafeBrowserProps = { onNavigate: (page: PageId) => void };

export function SafeBrowser({ onNavigate }: SafeBrowserProps) {
  return (
    <section className="glass-panel group relative flex flex-col gap-3 overflow-hidden rounded-2xl p-5 shadow-ambient transition-shadow hover:shadow-md">
      <div className="pointer-events-none absolute -bottom-6 -right-6 translate-x-4 translate-y-4 opacity-10 transition-opacity group-hover:opacity-20">
        <span className="material-symbols-outlined" style={{ fontSize: 140 }}>
          explore
        </span>
      </div>
      <div className="z-10 flex items-center gap-3">
        <div className="rounded-xl bg-primary p-3 text-white shadow-sm">
          <Icon name="travel_explore" />
        </div>
        <h3 className="font-display text-headline-md text-primary">Abrir Navegador Seguro</h3>
      </div>
      <p className="z-10 text-body-md text-on-surface-variant">
        Explore a web com segurança. Apenas sites aprovados são permitidos aqui.
      </p>
      <button
        type="button"
        onClick={() => onNavigate('browser')}
        className="z-10 mt-2 flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-label-md font-semibold text-white transition-colors hover:bg-primary/90 active:scale-95"
      >
        Começar a Navegar
        <Icon name="arrow_forward" className="text-sm" />
      </button>
    </section>
  );
}
