import type { Child } from '../api/types';
import { Icon } from './Icon';

type ProfileSheetProps = { child: Child; onClose: () => void };

export function ProfileSheet({ child, onClose }: ProfileSheetProps) {
  const limit = child.limitMinutes > 0 ? child.limitMinutes : 0;
  const pct = limit > 0 ? Math.min(100, Math.round((child.usedMinutes / limit) * 100)) : 0;

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-black/40 backdrop-blur-sm sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-label="Perfil"
      onClick={onClose}
    >
      <div
        className="glass-panel w-full max-w-sm rounded-t-3xl p-6 shadow-ambient sm:rounded-3xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-5 flex items-start justify-between">
          <h2 className="font-display text-headline-md text-primary">Seu perfil</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Fechar"
            className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50"
          >
            <Icon name="close" />
          </button>
        </div>

        <div className="flex items-center gap-4">
          {child.avatarUrl ? (
            <img
              src={child.avatarUrl}
              alt=""
              className="h-16 w-16 rounded-full object-cover shadow-ambient"
            />
          ) : (
            <div className="flex h-16 w-16 items-center justify-center rounded-full bg-primary-container text-on-primary-container">
              <Icon name="account_circle" className="text-4xl" filled />
            </div>
          )}
          <div>
            <div className="font-display text-headline-md font-bold text-on-surface">{child.name}</div>
            <div className="text-label-md text-on-surface-variant">{child.device ?? 'Meu aparelho'}</div>
          </div>
        </div>

        <div className="mt-5 rounded-2xl bg-surface-container p-4">
          <div className="flex items-center justify-between text-label-md">
            <span className="text-on-surface-variant">Tempo de tela hoje</span>
            <span className="font-bold text-on-surface">
              {child.usedMinutes}/{child.limitMinutes} min
            </span>
          </div>
          <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-surface-variant">
            <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} />
          </div>
        </div>

        <div className="mt-4 flex items-center gap-2 rounded-2xl border border-secondary/30 bg-secondary-container/30 p-3 text-secondary">
          <Icon name="verified_user" className="text-lg" filled />
          <span className="text-label-md font-semibold">Aparelho protegido</span>
        </div>
      </div>
    </div>
  );
}
