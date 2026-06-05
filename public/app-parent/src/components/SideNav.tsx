import { Icon } from './Icon';
import { Logo } from './Logo';
import { navItems, type PageId } from '../data/mockData';

type SideNavProps = {
  activePage: PageId;
  onNavigate: (page: PageId) => void;
};

export function SideNav({ activePage, onNavigate }: SideNavProps) {
  return (
    <aside className="fixed left-0 top-0 z-50 hidden h-screen w-64 flex-col border-r border-outline-variant bg-surface shadow-sm md:flex">
      <div className="flex h-full flex-col py-stack-lg">
        <div className="mb-8 flex items-center gap-3 px-6">
          <Logo size={40} />
          <div className="font-display text-headline-md font-extrabold leading-tight text-primary">
            GuardKids
            <br />
            <span className="text-base font-bold tracking-tight">WP</span>
          </div>
        </div>

        <div className="mb-8 flex items-center gap-3 px-6">
          <div className="flex h-12 w-12 items-center justify-center overflow-hidden rounded-full border-2 border-primary-container bg-surface-container-high text-primary">
            <Icon name="person" className="text-2xl" />
          </div>
          <div>
            <div className="font-sans text-label-md font-semibold text-on-surface">
              Parent Admin
            </div>
            <div className="text-label-sm text-on-surface-variant">Controle Parental</div>
          </div>
        </div>

        <nav className="flex-1 space-y-2 px-4">
          {navItems.map((item) => {
            const isActive = activePage === item.id;
            const badge = 'badge' in item ? item.badge : undefined;
            return (
              <button
                key={item.id}
                type="button"
                onClick={() => onNavigate(item.id)}
                className={
                  isActive
                    ? 'flex w-full items-center gap-3 rounded-lg border-r-4 border-primary bg-surface-container-high px-4 py-3 text-left font-bold text-primary'
                    : 'flex w-full items-center gap-3 rounded-lg px-4 py-3 text-left text-on-surface-variant transition-colors duration-200 hover:bg-surface-container hover:text-on-surface'
                }
              >
                <Icon name={item.icon} />
                <span className="flex-1 text-label-md font-semibold">{item.label}</span>
                {badge ? (
                  <span className="rounded-full bg-error px-2 py-0.5 text-xs font-bold text-on-error">
                    {badge}
                  </span>
                ) : null}
              </button>
            );
          })}
        </nav>

        <div className="mt-auto mb-6 px-6">
          <button
            type="button"
            onClick={() => onNavigate('children')}
            className="flex w-full items-center justify-center gap-2 rounded-full bg-primary-container px-4 py-3 text-label-md font-semibold text-on-primary-container transition-colors hover:bg-surface-tint hover:text-white"
          >
            <Icon name="add" className="text-lg" />
            Adicionar Novo Filho
          </button>
        </div>

        <div className="space-y-2 border-t border-outline-variant px-6 pt-4">
          <a
            href="#support"
            className="flex items-center gap-3 py-2 text-on-surface-variant transition-colors hover:text-on-surface"
          >
            <Icon name="help" className="text-lg" />
            <span className="text-label-sm">Suporte</span>
          </a>
          <a
            href="#logout"
            className="flex items-center gap-3 py-2 text-on-surface-variant transition-colors hover:text-on-surface"
          >
            <Icon name="logout" className="text-lg" />
            <span className="text-label-sm">Sair</span>
          </a>
        </div>
      </div>
    </aside>
  );
}
