import { Icon } from './Icon';
import { recentBlocks } from '../data/mockData';

export function RecentBlocks() {
  return (
    <div className="relative overflow-hidden rounded-xl border border-error/20 bg-error-container/30 p-5">
      <div className="pointer-events-none absolute -right-5 -top-5 text-error opacity-10">
        <span className="material-symbols-outlined" style={{ fontSize: 120 }}>
          gpp_bad
        </span>
      </div>
      <h3 className="relative z-10 mb-4 flex items-center gap-2 text-label-md font-bold text-on-error-container">
        <Icon name="security_update_warning" className="text-error" />
        Bloqueios Recentes
      </h3>
      <ul className="relative z-10 space-y-3">
        {recentBlocks.map((block) => (
          <li
            key={block.id}
            className="flex items-start gap-3 border-b border-error/10 pb-3 text-sm last:border-b-0 last:pb-0"
          >
            <Icon name="block" className="mt-0.5 text-lg text-error" />
            <div>
              <div className="font-medium text-on-surface">Bloqueado: {block.site}</div>
              <div className="text-xs text-on-surface-variant">
                {block.childName} • {block.whenLabel} • {block.reason}
              </div>
            </div>
          </li>
        ))}
      </ul>
      <button
        type="button"
        className="relative z-10 mt-4 flex w-full items-center justify-center gap-1 text-label-sm font-semibold text-primary hover:underline"
      >
        Ver histórico completo
      </button>
    </div>
  );
}
