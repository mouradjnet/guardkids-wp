import type { Child } from '../api/types';

export function ScreenTime({ child }: { child: Child }) {
  const remaining = Math.max(0, child.limitMinutes - child.usedMinutes);
  const total = child.limitMinutes;
  const remainingRatio = total > 0 ? remaining / total : 0;
  const radius = 54;
  const circumference = 2 * Math.PI * radius;
  const dashOffset = circumference * (1 - remainingRatio);

  const totalHours = Math.floor(total / 60);
  const totalLabel =
    totalHours >= 1
      ? `${totalHours}${totalHours === 1 ? ' hora' : ' horas'}${total % 60 ? ` ${total % 60} min` : ''}`
      : `${total} min`;

  return (
    <section className="glass-panel relative flex flex-col items-center justify-center overflow-hidden rounded-2xl p-6 shadow-ambient">
      <div className="pointer-events-none absolute inset-0 bg-gradient-to-br from-surface-container-highest/20 to-transparent" />
      <h3 className="z-10 mb-4 text-label-md font-bold uppercase tracking-wider text-on-surface-variant">
        Tempo de tela
      </h3>
      <div className="relative z-10 flex h-48 w-48 items-center justify-center">
        <svg className="h-full w-full -rotate-90" viewBox="0 0 120 120">
          <circle
            cx="60"
            cy="60"
            r={radius}
            fill="none"
            stroke="#dce9ff"
            strokeWidth="8"
          />
          <circle
            cx="60"
            cy="60"
            r={radius}
            fill="none"
            stroke="#F59E0B"
            strokeWidth="8"
            strokeLinecap="round"
            strokeDasharray={circumference}
            strokeDashoffset={dashOffset}
          />
        </svg>
        <div className="absolute flex flex-col items-center justify-center">
          <span className="font-display text-display-lg font-bold leading-none text-primary">
            {remaining}
          </span>
          <span className="mt-1 text-label-md font-semibold text-on-surface-variant">
            min restantes
          </span>
        </div>
      </div>
      <p className="z-10 mt-4 text-center text-sm font-medium text-on-surface-variant">
        De {totalLabel} de limite diário
      </p>
    </section>
  );
}
