import { useQuery } from '@tanstack/react-query';
import { getMe } from '../api/child';
import { ApiError } from '../api/client';
import { clearStoredToken } from '../api/token';
import { Icon } from '../components/Icon';
import { QuickActions } from '../components/QuickActions';
import { SafeBrowser } from '../components/SafeBrowser';
import { Schedule } from '../components/Schedule';
import { ScreenTime } from '../components/ScreenTime';
import { Welcome } from '../components/Welcome';
import type { PageId } from '../data/mockData';

type HomeProps = { onNavigate: (page: PageId) => void };

export function Home({ onNavigate }: HomeProps) {
  const meQuery = useQuery({ queryKey: ['child', 'me'], queryFn: getMe });

  if (meQuery.isLoading) {
    return (
      <main className="flex flex-1 items-center justify-center text-on-surface-variant">
        <Icon name="progress_activity" className="animate-spin text-2xl" />
      </main>
    );
  }

  if (meQuery.error) {
    if (meQuery.error instanceof ApiError && meQuery.error.status === 401) {
      // Token vencido/invalidado pelo lado parent → força re-pareamento
      clearStoredToken();
      window.location.reload();
      return null;
    }
    return (
      <main className="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center text-error">
        <Icon name="error" className="text-3xl" />
        <p className="font-semibold">Não foi possível carregar o seu perfil.</p>
        <p className="text-label-sm text-error/80">
          {meQuery.error instanceof Error ? meQuery.error.message : 'Erro desconhecido.'}
        </p>
      </main>
    );
  }

  const child = meQuery.data;
  if (!child) return null;

  return (
    <main className="flex flex-1 flex-col gap-stack-lg px-container-padding-mobile py-stack-md">
      <Welcome child={child} />
      <ScreenTime child={child} />
      <QuickActions onNavigate={onNavigate} />
      <SafeBrowser onNavigate={onNavigate} />
      <Schedule />

      <button
        type="button"
        onClick={() => onNavigate('blocked')}
        className="mt-2 flex items-center justify-center gap-2 self-center rounded-full border border-dashed border-outline-variant px-4 py-2 text-label-sm text-on-surface-variant transition-colors hover:bg-surface-container hover:text-primary"
      >
        <Icon name="visibility" className="text-sm" />
        Ver prévia: Modo Bloqueado
      </button>
    </main>
  );
}
