import { useQuery } from '@tanstack/react-query';
import { listChildren } from '../api/children';
import { ApiError } from '../api/client';
import { ChildCard } from '../components/ChildCard';
import { HeroWelcome } from '../components/HeroWelcome';
import { Icon } from '../components/Icon';
import { PendingRequests } from '../components/PendingRequests';
import { RecentBlocks } from '../components/RecentBlocks';

export function Dashboard() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['children'],
    queryFn: listChildren,
  });

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <HeroWelcome />

      <div className="grid grid-cols-1 gap-gutter lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <div className="flex items-center justify-between">
            <h3 className="font-display text-headline-md text-on-surface">Crianças Ativas</h3>
            <button
              type="button"
              className="flex items-center gap-1 text-label-md font-semibold text-primary hover:underline"
            >
              Ver Todos os Perfis
              <Icon name="chevron_right" className="text-sm" />
            </button>
          </div>

          {isLoading && <ChildrenSkeleton />}
          {error && <ChildrenError error={error} />}
          {data && data.length === 0 && <ChildrenEmpty />}
          {data && data.length > 0 && (
            <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
              {data.map((child) => (
                <ChildCard key={child.id} child={child} />
              ))}
            </div>
          )}
        </div>

        <div className="space-y-6">
          <PendingRequests />
          <RecentBlocks />
        </div>
      </div>
    </main>
  );
}

function ChildrenSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
      {[0, 1].map((i) => (
        <div
          key={i}
          className="glass-panel h-64 animate-pulse rounded-2xl bg-surface-container-low"
        />
      ))}
    </div>
  );
}

function ChildrenError({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar crianças</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}

function ChildrenEmpty() {
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl p-8 text-on-surface-variant">
      <Icon name="child_care" className="text-3xl" />
      <p className="text-label-md font-semibold">Nenhuma criança cadastrada ainda</p>
      <p className="text-center text-label-sm">
        Vá em <span className="font-semibold text-primary">Filhos</span> e clique em
        “Adicionar Novo Filho” para começar.
      </p>
    </div>
  );
}
