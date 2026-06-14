import { useQuery } from '@tanstack/react-query';
import { listChildren } from '../api/children';
import { listRequests } from '../api/requests';
import { listSettings } from '../api/settings';
import { Icon } from './Icon';

type Priority = 'high' | 'medium' | 'low' | 'ok';

type Action = {
  id: string;
  priority: Priority;
  icon: string;
  title: string;
  hint: string;
};

const PRIORITY_TONE: Record<Priority, { bg: string; text: string; dot: string }> = {
  high: { bg: 'bg-error/10', text: 'text-error', dot: 'bg-error' },
  medium: { bg: 'bg-tertiary-container/40', text: 'text-tertiary-container', dot: 'bg-tertiary-container' },
  low: { bg: 'bg-primary-container/40', text: 'text-primary', dot: 'bg-primary' },
  ok: { bg: 'bg-secondary-container/40', text: 'text-secondary', dot: 'bg-secondary' },
};

const PRIORITY_ORDER: Priority[] = ['high', 'medium', 'low', 'ok'];

export function ProximasAcoes() {
  const childrenQ = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const pendingQ = useQuery({
    queryKey: ['requests', 'pending'],
    queryFn: () => listRequests('pending'),
  });
  const settingsQ = useQuery({ queryKey: ['settings'], queryFn: listSettings });

  const loading = childrenQ.isLoading || pendingQ.isLoading || settingsQ.isLoading;
  const actions = buildActions(
    childrenQ.data ?? [],
    pendingQ.data ?? [],
    settingsQ.data ?? {},
  );

  return (
    <div className="relative overflow-hidden rounded-xl border border-outline-variant/30 bg-surface-container-low/40 p-5">
      <h3 className="relative z-10 mb-4 flex items-center gap-2 text-label-md font-bold text-on-surface">
        <Icon name="flag" className="text-on-surface-variant" />
        Próximas ações
      </h3>

      {loading ? (
        <div className="space-y-2">
          {[0, 1].map((i) => (
            <div key={i} className="h-14 animate-pulse rounded-lg bg-surface-container-high/40" />
          ))}
        </div>
      ) : (
        <ul className="space-y-2">
          {actions.map((a) => (
            <ActionRow key={a.id} action={a} />
          ))}
        </ul>
      )}
    </div>
  );
}

function ActionRow({ action }: { action: Action }) {
  const tone = PRIORITY_TONE[action.priority];
  return (
    <li className="flex items-center gap-3 rounded-lg bg-surface-container/60 p-3">
      <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${tone.bg} ${tone.text}`}>
        <Icon name={action.icon} className="text-lg" filled />
      </div>
      <div className="min-w-0 flex-1">
        <p className="truncate text-label-md font-semibold text-on-surface">{action.title}</p>
        <p className="truncate text-label-sm text-on-surface-variant">{action.hint}</p>
      </div>
      <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${tone.dot}`} aria-hidden />
    </li>
  );
}

type ChildLike = { id: number; name: string; status: string; usedMinutes: number; limitMinutes: number };
type RequestLike = { childId: number };
type SettingsLike = Record<string, unknown>;

export function buildActions(
  children: ChildLike[],
  pending: RequestLike[],
  settings: SettingsLike,
): Action[] {
  const actions: Action[] = [];

  if (children.length === 0) {
    actions.push({
      id: 'no-children',
      priority: 'high',
      icon: 'person_add',
      title: 'Conectar o primeiro dispositivo',
      hint: 'Comece adicionando uma criança no painel.',
    });
  } else {
    const locationOn = settings.location_enabled === true;
    if (!locationOn) {
      actions.push({
        id: 'location-off',
        priority: 'high',
        icon: 'location_off',
        title: 'Autorizar localização',
        hint: 'Crianças ficam sem mapa enquanto desligada.',
      });
    }

    if (pending.length > 0) {
      actions.push({
        id: 'pending-requests',
        priority: 'medium',
        icon: 'inbox',
        title: `Revisar ${pending.length} ${pending.length === 1 ? 'pedido pendente' : 'pedidos pendentes'}`,
        hint: 'As crianças estão esperando sua resposta.',
      });
    }

    const nearLimit = children.filter(
      (c) => c.limitMinutes > 0 && c.usedMinutes / c.limitMinutes >= 0.8,
    );
    if (nearLimit.length > 0) {
      actions.push({
        id: 'near-limit',
        priority: 'medium',
        icon: 'hourglass_bottom',
        title:
          nearLimit.length === 1
            ? `${nearLimit[0].name} está próxima do limite`
            : `${nearLimit.length} crianças próximas do limite`,
        hint: 'Mais de 80% do tempo de tela já consumido hoje.',
      });
    }
  }

  if (actions.length === 0) {
    actions.push({
      id: 'all-ok',
      priority: 'ok',
      icon: 'check_circle',
      title: 'Tudo funcionando bem',
      hint: 'Nenhuma ação necessária no momento.',
    });
  }

  return actions.sort(
    (a, b) => PRIORITY_ORDER.indexOf(a.priority) - PRIORITY_ORDER.indexOf(b.priority),
  );
}
