import { Icon } from '../components/Icon';
import { QuickActions } from '../components/QuickActions';
import { SafeBrowser } from '../components/SafeBrowser';
import { Schedule } from '../components/Schedule';
import { ScreenTime } from '../components/ScreenTime';
import { Welcome } from '../components/Welcome';
import type { PageId } from '../data/mockData';

type HomeProps = { onNavigate: (page: PageId) => void };

export function Home({ onNavigate }: HomeProps) {
  return (
    <main className="flex flex-1 flex-col gap-stack-lg px-container-padding-mobile py-stack-md">
      <Welcome />
      <ScreenTime />
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
