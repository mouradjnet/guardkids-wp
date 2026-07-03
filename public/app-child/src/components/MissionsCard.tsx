import { useQuery } from '@tanstack/react-query';
import { getMissions } from '../api/gamification';
import { Icon } from './Icon';

export function MissionsCard() {
  const query = useQuery({ queryKey: ['child', 'missions'], queryFn: getMissions });

  if (query.isLoading) {
    return <div className="h-32 animate-pulse rounded-2xl bg-surface-container-low" />;
  }

  const missions = query.data ?? [];
  if (missions.length === 0) {
    return <div data-testid="missions-empty" className="hidden" />;
  }

  return (
    <div className="rounded-2xl bg-surface-container p-4 shadow-sm">
      <div className="mb-3 flex items-center gap-2">
        <Icon name="flag" className="text-xl text-primary" filled />
        <h3 className="font-display text-label-md font-bold text-on-surface">Missões do dia</h3>
      </div>
      <ul className="space-y-3">
        {missions.map((m) => {
          const pct = m.target > 0 ? Math.min(100, Math.round((m.progress / m.target) * 100)) : 0;
          return (
            <li key={m.key} data-testid="mission-row" className="flex items-center gap-3">
              <div
                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${
                  m.completed ? 'bg-primary text-white' : 'bg-surface-variant text-on-surface-variant'
                }`}
              >
                <Icon name={m.completed ? 'check' : m.icon} className="text-lg" filled />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-center justify-between gap-2">
                  <span className="truncate text-label-md text-on-surface">{m.title}</span>
                  {m.completed ? (
                    <span
                      data-testid={`mission-completed-${m.key}`}
                      className="shrink-0 text-label-sm font-bold text-primary"
                    >
                      +{m.xpReward} XP
                    </span>
                  ) : (
                    <span className="shrink-0 text-label-sm text-on-surface-variant">
                      {m.progress}/{m.target}
                    </span>
                  )}
                </div>
                <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-surface-variant">
                  <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} />
                </div>
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
