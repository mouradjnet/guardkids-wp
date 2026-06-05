import { Icon } from './Icon';
import { pendingRequests } from '../data/mockData';

export function PendingRequests() {
  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <h3 className="flex items-center gap-2 font-display text-headline-md text-on-surface">
          Solicitações Pendentes
          <span className="rounded-full bg-error px-2 py-0.5 text-xs font-bold text-on-error">
            {pendingRequests.length}
          </span>
        </h3>
      </div>
      <div className="space-y-3">
        {pendingRequests.map((req) => {
          const accentBorder =
            req.accent === 'tertiary' ? 'border-l-tertiary-container' : 'border-l-primary';
          const highlightText =
            req.accent === 'tertiary' ? 'text-tertiary-container' : 'text-primary';
          return (
            <div
              key={req.id}
              className={`glass-panel rounded-xl border-l-4 p-4 transition-shadow hover:shadow-md ${accentBorder}`}
            >
              <div className="mb-3 flex items-center gap-3">
                <img
                  src={req.childAvatar}
                  alt={req.childName}
                  className="h-8 w-8 rounded-full object-cover"
                />
                <div className="flex-1">
                  <div className="text-label-md font-semibold text-on-surface">
                    {req.childName}
                  </div>
                  <div className="text-label-sm text-on-surface-variant">
                    {req.description}{' '}
                    <span className={`font-bold ${highlightText}`}>{req.highlight}</span>
                  </div>
                </div>
              </div>
              <div className="flex gap-2">
                <button
                  type="button"
                  className="flex flex-1 items-center justify-center gap-1 rounded-lg bg-secondary py-2 text-label-sm font-semibold text-white transition-colors hover:bg-secondary/90"
                >
                  <Icon name="check" className="text-sm" /> Aprovar
                </button>
                <button
                  type="button"
                  className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline bg-transparent py-2 text-label-sm font-semibold text-on-surface transition-colors hover:bg-surface-variant"
                >
                  <Icon name="close" className="text-sm" /> Negar
                </button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
