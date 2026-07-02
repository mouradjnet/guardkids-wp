import { Icon } from './Icon';
import type { PageId } from '../data/mockData';

const items: {
  id: PageId;
  label: string;
  icon: string;
  filled?: boolean;
  badge?: boolean;
}[] = [
  { id: 'home', label: 'Início', icon: 'home', filled: true },
  { id: 'browser', label: 'Navegar', icon: 'travel_explore' },
  { id: 'location', label: 'Localização', icon: 'location_on' },
  { id: 'requests', label: 'Pedidos', icon: 'task_alt' },
  { id: 'alerts', label: 'Alertas', icon: 'notifications_active', badge: true },
];

type BottomNavProps = {
  activePage: PageId;
  onNavigate: (page: PageId) => void;
  alertsUnread: number;
};

export function BottomNav({ activePage, onNavigate, alertsUnread }: BottomNavProps) {
  return (
    <nav className="fixed bottom-0 left-0 right-0 z-50 flex items-center justify-around border-t border-outline-variant bg-surface/85 pb-safe pt-2 shadow-ambient-up backdrop-blur-md">
      {items.map((item) => {
        const isActive = activePage === item.id;
        return (
          <button
            key={item.id}
            type="button"
            onClick={() => onNavigate(item.id)}
            className={
              isActive
                ? 'flex flex-col items-center justify-center rounded-xl bg-primary-container px-4 py-1.5 text-on-primary-container active:scale-95'
                : 'relative flex flex-col items-center justify-center rounded-xl px-4 py-1.5 text-on-surface-variant transition-transform hover:bg-surface-container-high active:scale-95'
            }
          >
            {item.badge && !isActive && alertsUnread > 0 && (
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
