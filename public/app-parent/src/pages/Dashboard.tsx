import { ChildCard } from '../components/ChildCard';
import { HeroWelcome } from '../components/HeroWelcome';
import { PendingRequests } from '../components/PendingRequests';
import { RecentBlocks } from '../components/RecentBlocks';
import { children } from '../data/mockData';
import { Icon } from '../components/Icon';

export function Dashboard() {
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
          <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
            {children.map((child) => (
              <ChildCard key={child.id} child={child} />
            ))}
          </div>
        </div>

        <div className="space-y-6">
          <PendingRequests />
          <RecentBlocks />
        </div>
      </div>
    </main>
  );
}
