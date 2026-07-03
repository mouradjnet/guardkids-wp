import { useQuery } from '@tanstack/react-query';
import { getMedals } from '../api/gamification';
import { Icon } from './Icon';

export function MedalsCard() {
  const query = useQuery({ queryKey: ['child', 'medals'], queryFn: getMedals });

  if (query.isLoading) {
    return <div className="h-40 animate-pulse rounded-2xl bg-surface-container-low" />;
  }

  const medals = query.data ?? [];
  if (medals.length === 0) {
    return <div data-testid="medals-empty" className="hidden" />;
  }

  const unlockedCount = medals.filter((m) => m.unlocked).length;

  return (
    <div className="rounded-2xl bg-surface-container p-4 shadow-sm">
      <div className="mb-3 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Icon name="military_tech" className="text-xl text-primary" filled />
          <h3 className="font-display text-label-md font-bold text-on-surface">Minhas Medalhas</h3>
        </div>
        <span className="text-label-sm font-bold text-on-surface-variant">
          {unlockedCount}/{medals.length}
        </span>
      </div>
      <ul className="grid grid-cols-3 gap-3">
        {medals.map((m) => (
          <li
            key={m.key}
            data-testid="medal-tile"
            className="flex flex-col items-center gap-1 text-center"
          >
            <div
              data-testid={m.unlocked ? `medal-unlocked-${m.key}` : `medal-locked-${m.key}`}
              className={`flex h-14 w-14 items-center justify-center rounded-full ${
                m.unlocked
                  ? 'bg-primary text-white shadow-sm'
                  : 'bg-surface-variant text-on-surface-variant opacity-50'
              }`}
            >
              <Icon name={m.icon} className="text-2xl" filled />
            </div>
            <span className="text-label-sm text-on-surface">{m.title}</span>
            {m.unlocked ? (
              <span className="text-label-sm font-bold text-primary">
                {m.justUnlocked ? `+${m.xpReward} XP` : 'Conquistada'}
              </span>
            ) : (
              <span className="text-label-sm text-on-surface-variant">
                {m.progress}/{m.target}
              </span>
            )}
          </li>
        ))}
      </ul>
    </div>
  );
}
