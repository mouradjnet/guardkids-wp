import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { getMe, reportScheduleBlock } from './api/child';
import { getStoredToken, setStoredToken } from './api/token';
import { BottomNav } from './components/BottomNav';
import { Header } from './components/Header';
import { createLocationTracker, type LocationTracker } from './lib/locationTracker';
import { createUsageTracker, setActiveTracker, type UsageTracker } from './lib/usageTracker';
import { Alerts } from './pages/Alerts';
import { Blocked } from './pages/Blocked';
import { Browser } from './pages/Browser';
import { Home } from './pages/Home';
import { Localizacao } from './pages/Localizacao';
import { PairScreen } from './pages/PairScreen';
import { Requests } from './pages/Requests';
import type { PageId } from './data/mockData';

let trackerSingleton: UsageTracker | null = null;
let locationTrackerSingleton: LocationTracker | null = null;

export default function App() {
  const [token, setToken] = useState<string | null>(() => getStoredToken());
  const [activePage, setActivePage] = useState<PageId>('home');

  // /child/me em alto nível pra (a) compartilhar cache com Home e (b) detectar
  // schedule.isBlocked e forçar entrada em <Blocked />. Refetch a cada 60s pra
  // capturar bedtime/weekday novo sem o user tocar nada.
  const meQuery = useQuery({
    queryKey: ['child', 'me'],
    queryFn: getMe,
    enabled: !!token,
    refetchInterval: 60_000,
  });
  const realIsBlocked = meQuery.data?.schedule?.isBlocked === true;
  const blockReason = meQuery.data?.schedule?.reason ?? null;
  const unlockAt = meQuery.data?.schedule?.unlockAt ?? null;

  useEffect(() => {
    if (!token) return;
    if (!trackerSingleton) trackerSingleton = createUsageTracker();
    trackerSingleton.start();
    setActiveTracker(trackerSingleton);
    if (!locationTrackerSingleton) {
      locationTrackerSingleton = createLocationTracker(token);
    }
    locationTrackerSingleton.start();
    return () => {
      trackerSingleton?.stop();
      setActiveTracker(null);
      locationTrackerSingleton?.stop();
    };
  }, [token]);

  useEffect(() => {
    if (realIsBlocked && activePage !== 'blocked') {
      setActivePage('blocked');
    }
  }, [realIsBlocked, activePage]);

  // Dedupe robusto: a "sessão de bloqueio" é identificada por reason+unlockAt.
  // Guardamos a última chave reportada em localStorage pra sobreviver a hard
  // refresh, refetch interval e re-renders. O useRef original quebrava porque
  // qualquer remount do componente (refresh, error boundary, etc) resetava
  // o ref e o app postava de novo. Visto em prod: 4 schedule_block events na
  // mesma sessão de bedtime (refetchInterval=60s gerava posts duplicados).
  useEffect(() => {
    if (!realIsBlocked || !unlockAt) return;
    if (blockReason !== 'bedtime' && blockReason !== 'weekday') return;

    const key = `${blockReason}:${unlockAt}`;
    if (window.localStorage.getItem('gk:lastReportedBlock') === key) return;

    window.localStorage.setItem('gk:lastReportedBlock', key);
    reportScheduleBlock(blockReason).catch(() => {
      /* silent — não trava UI por erro de telemetria */
    });
  }, [realIsBlocked, blockReason, unlockAt]);

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
        <Blocked
          onNavigate={setActivePage}
          reason={meQuery.data?.schedule?.reason ?? 'bedtime'}
          unlockAt={meQuery.data?.schedule?.unlockAt ?? null}
          lockedMode={realIsBlocked}
        />
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
    case 'location':
      return <Localizacao />;
    case 'home':
    default:
      return <Home onNavigate={onNavigate} />;
  }
}
