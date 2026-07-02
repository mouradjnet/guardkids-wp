import { useState } from 'react';
import { getPermission, isPushSupported, subscribe } from '../lib/push';
import { Icon } from './Icon';

export function EnableAlertsCard() {
  const [hidden, setHidden] = useState(!isPushSupported() || getPermission() !== 'default');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(false);

  if (hidden) return null;

  async function onEnable() {
    setBusy(true);
    setError(false);
    try {
      await subscribe();
      setHidden(true);
    } catch {
      setError(true);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="glass-panel flex items-center justify-between gap-3 rounded-2xl border border-primary/20 bg-primary/5 p-4 shadow-ambient">
      <div className="flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
          <Icon name="notifications_active" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-label-md font-bold text-on-surface">Ativar avisos</h3>
          <p className="text-label-sm text-on-surface-variant">
            {error ? 'Não deu pra ativar. Tenta de novo.' : 'Receba avisos mesmo com o app fechado.'}
          </p>
        </div>
      </div>
      <button
        type="button"
        onClick={onEnable}
        disabled={busy}
        aria-label="Ativar avisos"
        className="shrink-0 rounded-xl bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-sm transition-colors hover:bg-primary/90 disabled:opacity-60"
      >
        {busy ? 'Ativando…' : 'Ativar'}
      </button>
    </div>
  );
}
