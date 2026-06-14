import { useQuery } from '@tanstack/react-query';
import { getCompanionStatus, type CompanionStatus } from '../api/companion';
import { ApiError } from '../api/client';
import { formatRelative } from '../lib/requestDisplay';
import { Icon } from './Icon';

type Props = { childId: number; childName: string };

export function CompanionStatusCard({ childId, childName }: Props) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['companion', 'status', childId],
    queryFn: () => getCompanionStatus(childId),
    refetchInterval: 30_000,
  });

  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
          <Icon name="smartphone" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-headline-md text-on-surface">
            Status do Companion — {childName}
          </h3>
          <p className="text-label-sm text-on-surface-variant">
            Agente nativo Android (Modo Proteção Máxima).
          </p>
        </div>
      </header>

      {isLoading && (
        <div className="h-32 animate-pulse rounded-xl bg-surface-container-low" />
      )}

      {error && !isLoading && (
        <p role="alert" className="rounded-lg bg-error/10 p-3 text-label-sm text-error">
          {error instanceof ApiError
            ? `${error.message} (${error.status})`
            : error instanceof Error
              ? error.message
              : 'Erro desconhecido.'}
        </p>
      )}

      {data && !isLoading && <StatusGrid data={data} />}
    </article>
  );
}

function StatusGrid({ data }: { data: CompanionStatus }) {
  const items = [
    {
      id: 'paired',
      label: 'Companion',
      value: data.paired ? statusLabel(data.status) : 'Não pareado',
      ok: data.status === 'active',
      icon: 'link',
    },
    {
      id: 'accessibility',
      label: 'Accessibility Service',
      value: data.accessibilityEnabled ? 'Ativo' : 'Inativo',
      ok: data.accessibilityEnabled,
      icon: 'accessibility_new',
    },
    {
      id: 'admin',
      label: 'Device Admin',
      value: data.deviceAdminEnabled ? 'Ativo' : 'Inativo',
      ok: data.deviceAdminEnabled,
      icon: 'admin_panel_settings',
    },
    {
      id: 'owner',
      label: 'Device Owner',
      value: data.deviceOwnerEnabled ? 'Ativo' : 'Inativo',
      ok: data.deviceOwnerEnabled,
      icon: 'verified_user',
    },
    {
      id: 'sync',
      label: 'Última sincronização',
      value: data.lastSync ? formatRelative(data.lastSync) : 'Nunca',
      ok: data.lastSync !== null,
      icon: 'schedule',
    },
  ];
  return (
    <ul className="grid grid-cols-1 gap-3 md:grid-cols-2">
      {items.map((item) => (
        <li
          key={item.id}
          className="flex items-center gap-3 rounded-xl border border-outline-variant bg-surface-container-low p-3"
        >
          <div
            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
              item.ok ? 'bg-secondary-container/40 text-secondary' : 'bg-surface-container-high text-on-surface-variant'
            }`}
          >
            <Icon name={item.icon} className="text-lg" filled />
          </div>
          <div className="min-w-0 flex-1">
            <div className="truncate text-label-sm text-on-surface-variant">{item.label}</div>
            <div className={`truncate font-display text-label-md font-bold ${item.ok ? 'text-on-surface' : 'text-on-surface-variant'}`}>
              {item.value}
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}

function statusLabel(status: string): string {
  switch (status) {
    case 'active':
      return 'Conectado';
    case 'pending':
      return 'Aguardando pareamento';
    case 'unpaired':
      return 'Não pareado';
    default:
      return status;
  }
}
