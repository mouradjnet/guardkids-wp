import { useEffect } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { listNotifications, markNotificationsRead } from '../api/child';
import type { Notification } from '../api/types';
import { EnableAlertsCard } from '../components/EnableAlertsCard';
import { Icon } from '../components/Icon';

function relative(iso: string | null): string {
  if (!iso) return '';
  const diffMin = Math.floor((Date.now() - new Date(iso).getTime()) / 60_000);
  if (Number.isNaN(diffMin)) return '';
  if (diffMin < 1) return 'agora';
  if (diffMin < 60) return `há ${diffMin} min`;
  const h = Math.floor(diffMin / 60);
  if (h < 24) return `há ${h}h`;
  return `há ${Math.floor(h / 24)}d`;
}

const styleFor: Record<string, { icon: string; bg: string; text: string }> = {
  request_approved: { icon: 'check_circle', bg: 'bg-secondary-container/40', text: 'text-secondary' },
  site_allowed:     { icon: 'public',       bg: 'bg-primary/10',            text: 'text-primary' },
  time_warning:     { icon: 'schedule',     bg: 'bg-orange-warm/15',        text: 'text-orange-warm' },
  bedtime_warning:  { icon: 'bedtime',      bg: 'bg-orange-warm/15',        text: 'text-orange-warm' },
  request_denied:   { icon: 'cancel',       bg: 'bg-error-container/60',    text: 'text-error' },
  blocked:          { icon: 'block',        bg: 'bg-error-container/60',    text: 'text-error' },
};

export function Alerts() {
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: ['child', 'notifications'],
    queryFn: listNotifications,
    refetchInterval: 60_000,
  });
  const markRead = useMutation({
    mutationFn: markNotificationsRead,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['child', 'me'] }),
  });

  useEffect(() => {
    markRead.mutate();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
      <EnableAlertsCard />
      <p className="px-1 text-label-md text-on-surface-variant">
        Avisos novinhos pra você.
      </p>

      {query.isLoading && (
        <div className="glass-panel h-24 animate-pulse rounded-2xl bg-surface-container-low" />
      )}

      {query.error && (
        <div className="glass-panel flex flex-col items-center gap-2 rounded-2xl bg-error/5 p-4 text-error">
          <Icon name="error" className="text-2xl" />
          <p className="text-label-sm">Não deu pra carregar seus avisos agora.</p>
        </div>
      )}

      {query.data && query.data.length === 0 && (
        <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl p-6 text-center text-on-surface-variant">
          <Icon name="notifications_off" className="text-3xl text-primary" filled />
          <p className="text-label-md font-semibold">Nenhum aviso por aqui</p>
          <p className="text-label-sm">Quando algo acontecer, aparece aqui.</p>
        </div>
      )}

      {query.data && query.data.length > 0 && (
        <div className="glass-panel rounded-2xl shadow-ambient">
          <ul className="divide-y divide-outline-variant/50">
            {query.data.map((n: Notification) => {
              const s = styleFor[n.type] ?? styleFor.blocked;
              return (
                <li key={n.id} className="flex items-start gap-3 p-4">
                  <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${s.bg}`}>
                    <Icon name={s.icon} className={s.text} filled />
                  </div>
                  <div className="flex-1">
                    <div className="text-label-md font-semibold text-on-surface">{n.title}</div>
                    {n.body && <div className="text-label-sm text-on-surface-variant">{n.body}</div>}
                  </div>
                  <span className="text-label-sm text-on-surface-variant">{relative(n.createdAt)}</span>
                </li>
              );
            })}
          </ul>
        </div>
      )}
    </main>
  );
}
