import { Icon } from './Icon';
import { Logo } from './Logo';

export function TopNav() {
  return (
    <header className="sticky top-0 z-40 flex h-16 w-full items-center justify-between border-b border-outline-variant bg-surface/80 px-container-padding-mobile shadow-sm backdrop-blur-md md:hidden">
      <div className="flex items-center gap-2">
        <Logo size={32} />
        <h1 className="font-display text-headline-md font-extrabold text-primary">
          GuardKids WP
        </h1>
      </div>
      <div className="flex items-center gap-2">
        <button
          type="button"
          className="rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-variant/50"
          aria-label="Notificações"
        >
          <Icon name="notifications" />
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
