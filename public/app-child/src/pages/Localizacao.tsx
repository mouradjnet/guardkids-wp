import { useEffect, useState } from 'react';
import { Icon } from '../components/Icon';

type Permission = 'unknown' | 'pending' | 'granted' | 'denied';

export function Localizacao() {
  const [permission, setPermission] = useState<Permission>('unknown');

  useEffect(() => {
    if (typeof navigator === 'undefined' || !navigator.permissions) return;
    navigator.permissions
      .query({ name: 'geolocation' as PermissionName })
      .then((status) => {
        if (status.state === 'granted') setPermission('granted');
        else if (status.state === 'denied') setPermission('denied');
      })
      .catch(() => {
        /* fallback: deixa unknown e usuário clica botão */
      });
  }, []);

  const requestPermission = () => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) return;
    setPermission('pending');
    navigator.geolocation.getCurrentPosition(
      () => setPermission('granted'),
      () => setPermission('denied'),
      { enableHighAccuracy: true, timeout: 8_000 },
    );
  };

  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-stack-md px-container-padding-mobile py-stack-md text-center">
      {permission === 'granted' ? (
        <ActiveCard />
      ) : permission === 'denied' ? (
        <DeniedCard onRetry={requestPermission} />
      ) : (
        <ShareCard onShare={requestPermission} pending={permission === 'pending'} />
      )}
    </main>
  );
}

function ShareCard({ onShare, pending }: { onShare: () => void; pending: boolean }) {
  return (
    <div className="glass-panel flex max-w-sm flex-col items-center gap-4 rounded-3xl bg-surface p-6 shadow-ambient">
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-primary">
        <Icon name="location_on" className="text-4xl" filled />
      </div>
      <h2 className="font-display text-headline-md text-on-surface">Compartilhar localização</h2>
      <p className="text-label-md text-on-surface-variant">
        Sua localização aparece pro responsável <strong>apenas enquanto este app está aberto</strong>.
        Quando fechar, para de compartilhar.
      </p>
      <button
        type="button"
        onClick={onShare}
        disabled={pending}
        className="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-3 text-label-md font-semibold text-white shadow-ambient hover:bg-primary-container disabled:opacity-60"
      >
        {pending ? (
          <>
            <Icon name="progress_activity" className="animate-spin text-sm" />
            Pedindo permissão…
          </>
        ) : (
          'Permitir localização'
        )}
      </button>
    </div>
  );
}

function ActiveCard() {
  return (
    <div className="glass-panel flex max-w-sm flex-col items-center gap-4 rounded-3xl bg-surface p-6 shadow-ambient">
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-secondary-container/40 text-secondary">
        <Icon name="check_circle" className="text-4xl" filled />
      </div>
      <h2 className="font-display text-headline-md text-on-surface">Localização ativa</h2>
      <p className="text-label-md text-on-surface-variant">
        Sua localização está sendo compartilhada com o responsável.
      </p>
      <p className="rounded-xl bg-primary/5 px-4 py-3 text-label-sm text-on-surface-variant">
        💡 Mantenha este app aberto pra continuar compartilhando.
      </p>
    </div>
  );
}

function DeniedCard({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="glass-panel flex max-w-sm flex-col items-center gap-4 rounded-3xl bg-surface p-6 shadow-ambient">
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-error-container/60 text-error">
        <Icon name="location_off" className="text-4xl" filled />
      </div>
      <h2 className="font-display text-headline-md text-on-surface">Permissão negada</h2>
      <p className="text-label-md text-on-surface-variant">
        Sem permissão de localização, o app não consegue mostrar onde você está.
        Você pode liberar nas configurações do navegador.
      </p>
      <button
        type="button"
        onClick={onRetry}
        className="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-3 text-label-md font-semibold text-white shadow-ambient hover:bg-primary-container"
      >
        Tentar novamente
      </button>
    </div>
  );
}
