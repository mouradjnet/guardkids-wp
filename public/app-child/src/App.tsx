import { useState } from 'react';
import { getStoredToken, setStoredToken } from './api/token';
import { BottomNav } from './components/BottomNav';
import { Header } from './components/Header';
import { Alerts } from './pages/Alerts';
import { Blocked } from './pages/Blocked';
import { Browser } from './pages/Browser';
import { Home } from './pages/Home';
import { PairScreen } from './pages/PairScreen';
import { Requests } from './pages/Requests';
import type { PageId } from './data/mockData';

export default function App() {
  const [token, setToken] = useState<string | null>(() => getStoredToken());
  const [activePage, setActivePage] = useState<PageId>('home');

  if (!token) {
    return (
      <PairScreen
        onPaired={(t) => {
          setStoredToken(t);
          setToken(t);
        }}
      />
    );
  }

  if (activePage === 'blocked') {
    return (
      <div className="min-h-screen overflow-x-hidden bg-surface text-on-surface">
        <Blocked onNavigate={setActivePage} />
      </div>
    );
  }

  return (
    <div className="flex min-h-screen flex-col overflow-x-hidden bg-surface pb-24 text-on-surface">
      <Header activePage={activePage} onNavigate={setActivePage} />
      <PageRenderer page={activePage} onNavigate={setActivePage} />
      <BottomNav activePage={activePage} onNavigate={setActivePage} />
    </div>
  );
}

function PageRenderer({
  page,
  onNavigate,
}: {
  page: PageId;
  onNavigate: (page: PageId) => void;
}) {
  switch (page) {
    case 'browser':
      return <Browser />;
    case 'requests':
      return <Requests />;
    case 'alerts':
      return <Alerts />;
    case 'home':
    default:
      return <Home onNavigate={onNavigate} />;
  }
}
