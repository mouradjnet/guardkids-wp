import type { Child } from '../api/types';
import { Icon } from './Icon';

type Props = { child: Child };

const WEEKDAY_LABELS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

function formatLimit(minutes: number): string {
  if (minutes <= 0) return 'Sem limite';
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  if (h === 0) return `${m} min`;
  if (m === 0) return `${h}h`;
  return `${h}h ${m}min`;
}

export function RegrasDoDia({ child }: Props) {
  const allowed = child.allowedWeekdays ?? 'YYYYYYY';
  const todayIdx = (new Date().getDay() + 6) % 7; // 0=Mon (alinha com backend)
  const blockedToday = allowed[todayIdx] === 'N';
  const blockedDays = WEEKDAY_LABELS.filter((_, i) => allowed[i] === 'N');

  const bedtimeOn = child.bedtimeEnabled === true && child.bedtimeStart && child.bedtimeEnd;

  return (
    <section className="mb-4 flex flex-col gap-stack-sm">
      <h3 className="px-1 font-display text-headline-md text-primary">Regras de hoje</h3>
      <div className="glass-panel flex flex-col gap-3 rounded-2xl p-4 shadow-ambient">
        <Rule
          icon="timer"
          tone={blockedToday ? 'muted' : 'primary'}
          label="Tempo de tela"
          value={blockedToday ? 'Hoje é dia de pausa' : formatLimit(child.limitMinutes)}
        />
        {bedtimeOn ? (
          <Rule
            icon="bedtime"
            tone="primary"
            label="Hora de dormir"
            value={`${child.bedtimeStart} → ${child.bedtimeEnd}`}
          />
        ) : (
          <Rule
            icon="bedtime_off"
            tone="muted"
            label="Hora de dormir"
            value="Sem horário definido"
          />
        )}
        {blockedDays.length > 0 ? (
          <Rule
            icon="event_busy"
            tone="warn"
            label="Dias de pausa"
            value={blockedDays.join(' · ')}
          />
        ) : (
          <Rule
            icon="event_available"
            tone="success"
            label="Dias de uso"
            value="Todos os dias"
          />
        )}
      </div>
    </section>
  );
}

type RuleTone = 'primary' | 'success' | 'warn' | 'muted';
type RuleProps = {
  icon: string;
  tone: RuleTone;
  label: string;
  value: string;
};

const TONE: Record<RuleTone, { bg: string; text: string }> = {
  primary: { bg: 'bg-primary-container/40', text: 'text-primary' },
  success: { bg: 'bg-secondary-container/40', text: 'text-secondary' },
  warn: { bg: 'bg-tertiary-container/40', text: 'text-tertiary-container' },
  muted: { bg: 'bg-surface-container-high', text: 'text-on-surface-variant' },
};

function Rule({ icon, tone, label, value }: RuleProps) {
  const t = TONE[tone];
  return (
    <div className="flex items-center gap-3">
      <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ${t.bg} ${t.text}`}>
        <Icon name={icon} className="text-lg" filled />
      </div>
      <div className="min-w-0 flex-1">
        <div className="truncate text-label-sm text-on-surface-variant">{label}</div>
        <div className="truncate font-display text-label-md font-bold text-on-surface">
          {value}
        </div>
      </div>
    </div>
  );
}
