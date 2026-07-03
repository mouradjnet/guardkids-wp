import { useQuery } from '@tanstack/react-query';
import { listChildren } from '../api/children';
import { getChildProgression } from '../api/gamification';
import type { Child } from '../api/types';

function ChildProgressCard({ child }: { child: Child }) {
  const query = useQuery({
    queryKey: ['progression', child.id],
    queryFn: () => getChildProgression(child.id),
  });
  const p = query.data;
  const metrics = [
    { label: 'Nível', value: p ? `Nível ${p.level}` : '—' },
    { label: 'XP', value: p?.xp ?? 0 },
    { label: 'GuardCoins', value: p?.coins ?? 0 },
    { label: 'Missões concluídas', value: p?.missionsCompleted ?? 0 },
    { label: 'Medalhas', value: p?.medalsUnlocked ?? 0 },
    { label: 'Dias consecutivos', value: p?.streakDays ?? 0 },
  ];
  return (
    <div className="rounded-2xl border border-outline-variant bg-surface p-4 shadow-sm">
      <h3 className="mb-3 font-display text-headline-md text-on-surface">{child.name}</h3>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
        {metrics.map((m) => (
          <div key={m.label}>
            <div className="text-2xl font-bold text-primary">{m.value}</div>
            <div className="text-label-sm text-on-surface-variant">{m.label}</div>
          </div>
        ))}
      </div>
    </div>
  );
}

export function GamificationDashboard() {
  const children = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const list = children.data ?? [];

  return (
    <main className="mx-auto w-full max-w-[1440px] flex-1 space-y-6 p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <div>
        <h1 className="font-display text-headline-lg text-on-background">Gamificação</h1>
        <p className="text-body-md text-on-surface-variant">
          A evolução de cada filho no Mundo Guardião.
        </p>
      </div>
      {children.isLoading ? (
        <div className="h-24 animate-pulse rounded-2xl bg-surface-container-low" />
      ) : list.length === 0 ? (
        <div className="flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-outline-variant p-10 text-center">
          <span className="material-symbols-outlined text-5xl text-outline">stadia_controller</span>
          <p className="text-label-lg font-semibold text-on-surface">Nenhum filho cadastrado</p>
        </div>
      ) : (
        <div className="space-y-4">
          {list.map((c) => (
            <ChildProgressCard key={c.id} child={c} />
          ))}
        </div>
      )}
    </main>
  );
}
