import { useQuery } from '@tanstack/react-query';
import { getProgression } from '../api/gamification';
import { Icon } from './Icon';

export function ProgressCard() {
  const query = useQuery({ queryKey: ['child', 'progression'], queryFn: getProgression });
  const p = query.data;

  if (query.isLoading) {
    return <div className="h-24 animate-pulse rounded-2xl bg-surface-container-low" />;
  }
  if (!p) return null;

  const pct =
    p.xpForNextLevel > 0 ? Math.min(100, Math.round((p.xpIntoLevel / p.xpForNextLevel) * 100)) : 100;

  return (
    <div className="rounded-2xl bg-surface-container p-4 shadow-sm">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary text-white">
            <Icon name="stars" className="text-xl" filled />
          </div>
          <div>
            <div className="font-display text-label-md font-bold text-on-surface">Nível {p.level}</div>
            <div className="text-label-sm text-on-surface-variant">Minha Evolução</div>
          </div>
        </div>
        <div className="flex items-center gap-3">
          <span className="flex items-center gap-1 text-label-md font-bold text-orange-500">
            <Icon name="paid" className="text-base" filled /> {p.coins}
          </span>
          <span className="flex items-center gap-1 text-label-md font-bold text-error">
            <Icon name="local_fire_department" className="text-base" filled /> {p.streakDays}
          </span>
        </div>
      </div>
      <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-surface-variant">
        <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} />
      </div>
      {p.xpForNextLevel > 0 && (
        <div className="mt-1 text-label-sm text-on-surface-variant">
          {p.xpIntoLevel}/{p.xpForNextLevel} XP
        </div>
      )}
    </div>
  );
}
