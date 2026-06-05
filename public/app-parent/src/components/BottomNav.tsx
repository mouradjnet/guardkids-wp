import { Icon } from './Icon';
import type { PageId } from '../data/mockData';

const items: {
  id: PageId;
  label: string;
  icon: string;
  filled?: boolean;
  badge?: boolean;
}[] = [
  { id: 'dashboard', label: 'Início', icon: 'home', filled: true },
  { id: 'children', label: 'Filhos', icon: 'smart_display' },
  { id: 'approvals', label: 'Aprovações', icon: 'task_alt', badge: true },
  { id: 'sites-rules', label: 'Regras', icon: 'app_blocking' },
];

type BottomNavProps = {
  activePage: PageId;
  onNavigate: (page: PageId) => void;
};

export function BottomNav({ activePage, onNavigate }: BottomNavProps) {
  return (
    <nav className="fixed bottom-0 z-50 flex w-full items-center justify-around border-t border-outline-variant bg-surface/80 pb-safe pt-2 shadow-ambient-up backdrop-blur-md md:hidden">
      {items.map((item) => {
        const isActive = activePage === item.id;
        return (
          <button
            key={item.id}
            type="button"
            onClick={() => onNavigate(item.id)}
            className={
              isActive
                ? 'flex flex-col items-center justify-center rounded-xl bg-primary-container px-4 py-1.5 text-on-primary-container'
                : 'relative flex flex-col items-center justify-center rounded-xl px-4 py-1.5 text-on-surface-variant transition-colors hover:bg-surface-container-high'
            }
          >
            {item.badge && !isActive && (
              <span className="absolute right-3 top-1 h-2 w-2 rounded-full bg-error" />
            )}
            <Icon name={item.icon} filled={item.filled} />
            <span className="mt-1 text-label-sm">{item.label}</span>
          </button>
        );
      })}
    </nav>
  );
}
