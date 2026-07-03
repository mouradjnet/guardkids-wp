import { useState } from 'react';
import { AutoLogoutGuard } from './components/AutoLogoutGuard';
import { BottomNav } from './components/BottomNav';
import { SideNav } from './components/SideNav';
import { TopNav } from './components/TopNav';
import { Approvals } from './pages/Approvals';
import { Children } from './pages/Children';
import { ContentDashboard } from './pages/ContentDashboard';
import { Dashboard } from './pages/Dashboard';
import { GamificationDashboard } from './pages/GamificationDashboard';
import { License } from './pages/License';
import { Localizacao } from './pages/Localizacao';
import { ProtectionMode } from './pages/ProtectionMode';
import { Recompensas } from './pages/Recompensas';
import { Reports } from './pages/Reports';
import { Settings } from './pages/Settings';
import { SitesRules } from './pages/SitesRules';
import { TimeLimits } from './pages/TimeLimits';
import { Upgrade } from './pages/Upgrade';
import { ZonasSeguras } from './pages/ZonasSeguras';
import type { PageId } from './data/mockData';

export default function App() {
  const [activePage, setActivePage] = useState<PageId>('dashboard');

  return (
    <div className="flex min-h-screen flex-col bg-background text-on-background md:flex-row">
      <AutoLogoutGuard />
      <TopNav />
      <SideNav activePage={activePage} onNavigate={setActivePage} />
      <PageRenderer page={activePage} />
      <BottomNav activePage={activePage} onNavigate={setActivePage} />
    </div>
  );
}

function PageRenderer({ page }: { page: PageId }) {
  switch (page) {
    case 'children':
      return <Children />;
    case 'location':
      return <Localizacao />;
    case 'safe-zones':
      return <ZonasSeguras />;
    case 'approvals':
      return <Approvals />;
    case 'sites-rules':
      return <SitesRules />;
    case 'content':
      return <ContentDashboard />;
    case 'gamification':
      return <GamificationDashboard />;
    case 'rewards':
      return <Recompensas />;
    case 'time':
      return <TimeLimits />;
    case 'reports':
      return <Reports />;
    case 'settings':
      return <Settings />;
    case 'protection':
      return <ProtectionMode />;
    case 'license':
      return <License />;
    case 'upgrade':
      return <Upgrade />;
    case 'dashboard':
    default:
      return <Dashboard />;
  }
}
