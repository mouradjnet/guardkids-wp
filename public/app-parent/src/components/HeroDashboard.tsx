import { useQuery } from '@tanstack/react-query';
import { listChildren } from '../api/children';
import { listRequests } from '../api/requests';
import { listSettings } from '../api/settings';
import { getMe } from '../api/me';
import { Icon } from './Icon';

function greeting(hour: number): string {
  if (hour < 12) return 'Bom dia';
  if (hour < 18) return 'Boa tarde';
  return 'Boa noite';
}

function firstName(full: string): string {
  return full.trim().split(/\s+/)[0] ?? '';
}

export function HeroDashboard() {
  const childrenQ = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const pendingQ = useQuery({
    queryKey: ['requests', 'pending'],
    queryFn: () => listRequests('pending'),
  });
  const settingsQ = useQuery({ queryKey: ['settings'], queryFn: listSettings });
  const meQ = useQuery({ queryKey: ['me'], queryFn: getMe });

  const hour = new Date().getHours();
  const name = meQ.data?.name ? firstName(meQ.data.name) : null;
  const heading = name ? `${greeting(hour)}, ${name} 👋` : `${greeting(hour)} 👋`;

  const childrenCount = childrenQ.data?.length ?? 0;
  const pendingCount = pendingQ.data?.length ?? 0;
  const locationEnabled = settingsQ.data?.location_enabled === true;

  const availableToday = (childrenQ.data ?? []).reduce((sum, c) => {
    const remaining = Math.max(0, c.limitMinutes - c.usedMinutes);
    return sum + remaining;
  }, 0);
  const availableLabel = formatHM(availableToday);

  return (
    <section className="glass-panel relative overflow-hidden rounded-2xl bg-gradient-to-br from-surface to-surface-container-low p-6 md:p-8">
      <div className="relative z-10 mb-6 max-w-xl">
        <h2 className="mb-2 font-display text-headline-lg-mobile font-bold text-primary md:text-headline-lg">
          {heading}
        </h2>
        <p className="text-body-md text-on-surface-variant">
          {pendingCount > 0
            ? `Você tem ${pendingCount} ${pendingCount === 1 ? 'pedido' : 'pedidos'} aguardando sua decisão.`
            : 'Tudo tranquilo. Nenhum alerta crítico detectado hoje.'}
        </p>
      </div>

      <div className="relative z-10 grid grid-cols-2 gap-3 md:grid-cols-5">
        <KpiChip
          icon="child_care"
          tone="primary"
          label="Crianças"
          value={`${childrenCount}`}
          hint={childrenCount === 1 ? 'protegida' : 'protegidas'}
          loading={childrenQ.isLoading}
        />
        <KpiChip
          icon="verified_user"
          tone="success"
          label="Sistema"
          value="Ativo"
          hint="Tudo seguro"
        />
        <KpiChip
          icon="notifications_active"
          tone={pendingCount > 0 ? 'warn' : 'muted'}
          label="Pedidos"
          value={`${pendingCount}`}
          hint={pendingCount === 1 ? 'pendente' : 'pendentes'}
          loading={pendingQ.isLoading}
        />
        <KpiChip
          icon={locationEnabled ? 'location_on' : 'location_off'}
          tone={locationEnabled ? 'success' : 'muted'}
          label="Localização"
          value={locationEnabled ? 'Ligada' : 'Desligada'}
          hint={locationEnabled ? 'Compartilhando' : 'Pausada'}
          loading={settingsQ.isLoading}
        />
        <KpiChip
          icon="timer"
          tone="primary"
          label="Disponível hoje"
          value={availableLabel}
          hint="restante total"
          loading={childrenQ.isLoading}
        />
      </div>

      <div className="pointer-events-none absolute -right-20 -top-20 h-64 w-64 rounded-full bg-primary-container opacity-5 blur-3xl" />
    </section>
  );
}

type KpiTone = 'primary' | 'success' | 'warn' | 'muted';

type KpiChipProps = {
  icon: string;
  tone: KpiTone;
  label: string;
  value: string;
  hint: string;
  loading?: boolean;
};

const TONE_CLASSES: Record<KpiTone, { bg: string; text: string }> = {
  primary: { bg: 'bg-primary-container/40', text: 'text-primary' },
  success: { bg: 'bg-secondary-container/40', text: 'text-secondary' },
  warn: { bg: 'bg-tertiary-container/40', text: 'text-tertiary-container' },
  muted: { bg: 'bg-surface-container-high', text: 'text-on-surface-variant' },
};

function KpiChip({ icon, tone, label, value, hint, loading }: KpiChipProps) {
  const t = TONE_CLASSES[tone];
  return (
    <div className="flex items-start gap-3 rounded-xl border border-outline-variant bg-white p-3 shadow-sm">
      <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${t.bg} ${t.text}`}>
        <Icon name={icon} className="text-lg" filled />
      </div>
      <div className="min-w-0 flex-1">
        <div className="truncate text-label-sm text-on-surface-variant">{label}</div>
        {loading ? (
          <div className="mt-1 h-5 w-12 animate-pulse rounded bg-surface-container-high" />
        ) : (
          <div className="truncate font-display text-label-md font-bold text-on-surface">
            {value}
          </div>
        )}
        <div className="truncate text-[10px] text-on-surface-variant">{hint}</div>
      </div>
    </div>
  );
}

function formatHM(min: number): string {
  if (min <= 0) return '0m';
  const h = Math.floor(min / 60);
  const m = min % 60;
  if (h === 0) return `${m}m`;
  if (m === 0) return `${h}h`;
  return `${h}h${m}m`;
}
