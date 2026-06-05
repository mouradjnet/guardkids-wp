import { lastRequest, type PageId } from '../data/mockData';
import { Icon } from './Icon';

type QuickActionsProps = { onNavigate: (page: PageId) => void };

export function QuickActions({ onNavigate }: QuickActionsProps) {
  return (
    <section className="flex flex-col gap-stack-sm">
      <h3 className="px-1 font-display text-headline-md text-primary">Ações Rápidas</h3>
      <div className="grid grid-cols-2 gap-3">
        <ActionCard
          icon="more_time"
          tone="orange"
          label={
            <>
              Pedir
              <br />
              mais tempo
            </>
          }
          onClick={() => onNavigate('requests')}
        />
        <ActionCard
          icon="public"
          tone="primary"
          label={
            <>
              Pedir
              <br />
              site
            </>
          }
          onClick={() => onNavigate('requests')}
        />
      </div>

      <button
        type="button"
        onClick={() => onNavigate('requests')}
        className="mt-2 flex items-center gap-3 rounded-lg border border-outline-variant bg-surface-container p-3 text-left transition-colors hover:bg-surface-container-high"
      >
        <div className="shrink-0 rounded-full bg-mint-success/20 p-2 text-mint-success">
          <Icon name="check_circle" className="text-sm" filled />
        </div>
        <div className="flex-1">
          <p className="text-label-sm text-on-surface">{lastRequest.label}</p>
          <p className="text-xs font-semibold text-mint-success">Aprovado!</p>
        </div>
        <Icon name="chevron_right" className="text-on-surface-variant" />
      </button>
    </section>
  );
}

type ActionCardProps = {
  icon: string;
  tone: 'orange' | 'primary';
  label: React.ReactNode;
  onClick: () => void;
};

function ActionCard({ icon, tone, label, onClick }: ActionCardProps) {
  const ringClass =
    tone === 'orange' ? 'bg-orange-warm/10 text-orange-warm' : 'bg-primary/10 text-primary';
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex flex-col items-center justify-center gap-2 rounded-xl border border-outline-variant bg-surface-container-high p-4 shadow-ambient transition-colors hover:bg-surface-container-highest active:scale-95"
    >
      <div
        className={`flex h-12 w-12 items-center justify-center rounded-full ${ringClass}`}
      >
        <Icon name={icon} className="text-2xl" />
      </div>
      <span className="text-center text-label-md text-on-surface">{label}</span>
    </button>
  );
}
