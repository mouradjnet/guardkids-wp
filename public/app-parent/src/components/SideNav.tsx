import { useQuery } from '@tanstack/react-query';
import { Icon } from './Icon';
import { Logo } from './Logo';
import { navItems, type PageId } from '../data/mockData';
import { listRequests } from '../api/requests';
import { useCurrentRole } from '../hooks/useCurrentRole';
import { canAccessPage } from '../lib/roleAccess';

type SideNavProps = {
  activePage: PageId;
  onNavigate: (page: PageId) => void;
};

export function SideNav({ activePage, onNavigate }: SideNavProps) {
  const { role, name, isCollaborator } = useCurrentRole();
  const visibleItems = navItems.filter((item) => canAccessPage(role, item.id));

  // O badge era `badge: 2` hardcoded no mockData — dizia "2" com zero pedidos e
  // diria "2" com quarenta. Badge com número é afirmação factual: um pai que
  // confia nele deixa criança esperando resposta que ele acha que já deu.
  //
  // A chave ['requests', ...] casa com o invalidateQueries da Approvals, então
  // o número cai sozinho quando o pai aprova ou nega.
  const pendentesQuery = useQuery({
    queryKey: ['requests', 'pending'],
    queryFn: () => listRequests('pending'),
  });
  const pendentes = pendentesQuery.data?.length ?? 0;

  return (
    <aside className="fixed left-0 top-0 z-50 hidden h-screen w-64 flex-col border-r border-outline-variant bg-surface shadow-sm md:flex">
      <div className="flex h-full flex-col py-stack-lg">
        <div className="mb-8 flex items-center gap-3 px-6">
          <Logo size={40} />
          <div className="font-display text-headline-md font-extrabold leading-tight text-primary">
            GuardKids
            <br />
            <span className="text-base font-bold tracking-tight">WP</span>
          </div>
        </div>

        <div className="mb-8 flex items-center gap-3 px-6">
          <div className="flex h-12 w-12 items-center justify-center overflow-hidden rounded-full border-2 border-primary-container bg-surface-container-high text-primary">
            <Icon name="person" className="text-2xl" />
          </div>
          <div>
            <div className="font-sans text-label-md font-semibold text-on-surface">
              {name || 'Parent Admin'}
            </div>
            <div className="text-label-sm text-on-surface-variant">
              {isCollaborator ? 'Colaborador' : 'Controle Parental'}
            </div>
          </div>
        </div>

        <nav className="flex-1 space-y-2 px-4">
          {visibleItems.map((item) => {
            const isActive = activePage === item.id;
            // Só Aprovações tem badge, e ele vem do servidor. Nada de número
            // enquanto carrega: melhor não dizer nada do que dizer errado.
            const badge = item.id === 'approvals' && pendentes > 0 ? pendentes : undefined;
            return (
              <button
                key={item.id}
                type="button"
                onClick={() => onNavigate(item.id)}
                className={
                  isActive
                    ? 'flex w-full items-center gap-3 rounded-lg border-r-4 border-primary bg-surface-container-high px-4 py-3 text-left font-bold text-primary'
                    : 'flex w-full items-center gap-3 rounded-lg px-4 py-3 text-left text-on-surface-variant transition-colors duration-200 hover:bg-surface-container hover:text-on-surface'
                }
              >
                <Icon name={item.icon} />
                <span className="flex-1 text-label-md font-semibold">{item.label}</span>
                {badge ? (
                  <span className="rounded-full bg-error px-2 py-0.5 text-xs font-bold text-on-error">
                    {badge}
                  </span>
                ) : null}
              </button>
            );
          })}
        </nav>

        {!isCollaborator && (
          <div className="mt-auto mb-6 px-6">
            <button
              type="button"
              onClick={() => onNavigate('children')}
              className="flex w-full items-center justify-center gap-2 rounded-full bg-primary-container px-4 py-3 text-label-md font-semibold text-on-primary-container transition-colors hover:bg-surface-tint hover:text-white"
            >
              <Icon name="add" className="text-lg" />
              Conectar Dispositivo Infantil
            </button>
          </div>
        )}

        <div className="space-y-2 border-t border-outline-variant px-6 pt-4">
          <a
            href="#support"
            className="flex items-center gap-3 py-2 text-on-surface-variant transition-colors hover:text-on-surface"
          >
            <Icon name="help" className="text-lg" />
            <span className="text-label-sm">Suporte</span>
          </a>
          <a
            href={window.guardkidsApi?.logoutUrl ?? '/wp-login.php?action=logout'}
            className="flex items-center gap-3 py-2 text-on-surface-variant transition-colors hover:text-on-surface"
          >
            <Icon name="logout" className="text-lg" />
            <span className="text-label-sm">Sair</span>
          </a>
        </div>
      </div>
    </aside>
  );
}
