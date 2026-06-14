import { Icon } from './Icon';
import { formatRelative } from '../lib/requestDisplay';
import type { Child } from '../api/types';

function formatHM(min: number) {
  const h = Math.floor(min / 60);
  const m = min % 60;
  return `${h}h ${String(m).padStart(2, '0')}m`;
}

type ChildCardProps = { child: Child; pendingCount?: number };

export function ChildCard({ child, pendingCount = 0 }: ChildCardProps) {
  const pct = child.limitMinutes > 0
    ? Math.min(100, Math.round((child.usedMinutes / child.limitMinutes) * 100))
    : 0;
  const offline = child.status === 'offline';
  const isPaused = offline;
  const lastSync = child.updatedAt ? formatRelative(child.updatedAt) : null;

  return (
    <div className="glass-panel group relative overflow-hidden rounded-2xl p-6 transition-shadow hover:shadow-md">
      <div className="mb-6 flex items-start justify-between">
        <div className="flex items-center gap-4">
          <div className="relative">
            {child.avatarUrl ? (
              <img
                src={child.avatarUrl}
                alt={`${child.name} avatar`}
                className={`h-14 w-14 rounded-full border-2 border-surface-variant object-cover ${
                  offline ? 'grayscale-[30%]' : ''
                }`}
              />
            ) : (
              <div
                className={`flex h-14 w-14 items-center justify-center rounded-full border-2 border-surface-variant bg-surface-container font-display text-lg text-on-surface-variant ${
                  offline ? 'grayscale-[30%]' : ''
                }`}
              >
                {child.name.charAt(0).toUpperCase()}
              </div>
            )}
            <div
              className={`absolute bottom-0 right-0 h-3.5 w-3.5 rounded-full border-2 border-white ${
                child.status === 'online' ? 'bg-secondary pulse-green' : 'bg-outline-variant'
              }`}
            />
          </div>
          <div>
            <h4 className="text-lg font-semibold text-on-surface">{child.name}</h4>
            <div className="mt-1 flex flex-wrap items-center gap-2">
              {child.status === 'online' ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-secondary-container/30 px-2 py-0.5 text-label-sm text-secondary">
                  <span className="h-1.5 w-1.5 rounded-full bg-secondary" />
                  Online
                </span>
              ) : (
                <span className="inline-flex items-center gap-1 rounded-full bg-surface-variant/50 px-2 py-0.5 text-label-sm text-on-surface-variant">
                  <span className="h-1.5 w-1.5 rounded-full bg-outline-variant" />
                  Offline
                </span>
              )}
              {lastSync && (
                <span className="text-label-sm text-on-surface-variant" title="Última sincronização">
                  · sync {lastSync}
                </span>
              )}
            </div>
          </div>
        </div>
        <div className="flex items-start gap-2">
          {pendingCount > 0 && (
            <span
              className="inline-flex items-center gap-1 rounded-full bg-tertiary-container/50 px-2 py-0.5 text-label-sm font-semibold text-tertiary-container"
              title={`${pendingCount} pedido${pendingCount === 1 ? '' : 's'} pendente${pendingCount === 1 ? '' : 's'}`}
            >
              <Icon name="notifications_active" className="text-xs" filled />
              {pendingCount}
            </span>
          )}
          <button
            type="button"
            aria-label="Mais ações"
            className="rounded-full p-1 text-on-surface-variant transition-colors hover:bg-surface-variant/50 hover:text-primary"
          >
            <Icon name="more_vert" />
          </button>
        </div>
      </div>

      <div className={`mb-6 flex items-center gap-6 ${offline ? 'opacity-70' : ''}`}>
        <div className="relative h-20 w-20">
          <svg className="h-full w-full" viewBox="0 0 36 36">
            <path
              fill="none"
              stroke="#e5eeff"
              strokeWidth="3.8"
              d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
            />
            <path
              className="ring-progress stroke-primary"
              fill="none"
              strokeWidth="2.8"
              strokeLinecap="round"
              strokeDasharray={`${pct}, 100`}
              d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
            />
          </svg>
          <div className="absolute inset-0 flex items-center justify-center">
            <span className="text-label-md font-bold leading-none text-on-surface">{pct}%</span>
          </div>
        </div>
        <div>
          <div className="font-display text-headline-md font-semibold text-primary">
            {formatHM(child.usedMinutes)}
          </div>
          <div className="text-label-sm text-on-surface-variant">
            de {formatHM(child.limitMinutes)} de limite
          </div>
        </div>
      </div>

      <div className="flex gap-2">
        <QuickAction
          icon={isPaused ? 'wifi' : 'wifi_off'}
          label={isPaused ? 'Retomar' : 'Pausar'}
          tone={isPaused ? 'muted' : 'error'}
          disabled={isPaused}
        />
        <QuickAction
          icon={offline ? 'edit_calendar' : 'more_time'}
          label={offline ? 'Agenda' : '+15m'}
          tone="primary"
        />
        <QuickAction icon="history" label="Histórico" tone="neutral" />
      </div>
    </div>
  );
}

type QuickActionProps = {
  icon: string;
  label: string;
  tone: 'primary' | 'error' | 'muted' | 'neutral';
  disabled?: boolean;
};

function QuickAction({ icon, label, tone, disabled }: QuickActionProps) {
  const toneClass =
    tone === 'primary'
      ? 'text-primary'
      : tone === 'error'
        ? 'text-error'
        : 'text-on-surface-variant';
  return (
    <button
      type="button"
      disabled={disabled}
      className={`flex flex-1 flex-col items-center gap-1 rounded-lg border border-outline-variant bg-surface-container py-2 text-label-sm transition-colors hover:bg-surface-variant ${
        disabled ? 'cursor-not-allowed opacity-50' : ''
      }`}
    >
      <span className={`material-symbols-outlined text-lg ${toneClass}`}>{icon}</span>
      {label}
    </button>
  );
}
