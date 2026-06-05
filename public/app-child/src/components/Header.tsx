import { Icon } from './Icon';
import type { PageId } from '../data/mockData';

const titles: Record<PageId, string> = {
  home: 'GuardKids WP',
  browser: 'Navegador Seguro',
  requests: 'Minhas Solicitações',
  alerts: 'Avisos',
  blocked: '',
};

type HeaderProps = {
  activePage: PageId;
  onNavigate: (page: PageId) => void;
};

export function Header({ activePage, onNavigate }: HeaderProps) {
  const isHome = activePage === 'home';
  const title = titles[activePage];

  return (
    <header className="sticky top-0 z-40 flex h-16 w-full items-center justify-between border-b border-outline-variant bg-surface/80 px-container-padding-mobile shadow-sm backdrop-blur-md">
      {isHome ? (
        <div className="flex items-center gap-3">
          <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary text-white shadow-ambient">
            <Icon name="shield_person" className="text-[22px]" filled />
          </div>
          <h1 className="font-display text-headline-md font-extrabold leading-tight text-primary">
            GuardKids
            <br />
            <span className="text-base font-bold tracking-tight">WP</span>
          </h1>
        </div>
      ) : (
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => onNavigate('home')}
            aria-label="Voltar para o início"
            className="rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-variant/50"
          >
            <Icon name="arrow_back" />
          </button>
          <h1 className="font-display text-headline-md font-bold text-primary">{title}</h1>
        </div>
      )}

      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => onNavigate('alerts')}
          className="relative rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-variant/50"
          aria-label="Notificações"
        >
          <Icon name="notifications" />
          <span className="absolute right-2 top-2 h-2 w-2 rounded-full bg-orange-warm" />
        </button>
        <button
          type="button"
          className="rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-variant/50"
          aria-label="Perfil"
        >
          <Icon name="account_circle" />
        </button>
      </div>
    </header>
  );
}
