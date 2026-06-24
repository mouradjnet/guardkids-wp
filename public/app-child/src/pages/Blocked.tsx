import { useEffect, useState } from 'react';
import { verifyPin } from '../api/child';
import type { ScheduleReason } from '../api/types';
import { Icon } from '../components/Icon';
import type { PageId } from '../data/mockData';

type BlockedProps = {
  onNavigate: (page: PageId) => void;
  reason: ScheduleReason | null;
  unlockAt: string | null;
  /** Quando true, oculta o botão Voltar (bloqueio real, não preview). */
  lockedMode?: boolean;
  /** Habilita o desbloqueio por PIN dos pais (vem do /child/me). */
  pinUnlockEnabled?: boolean;
  /** Chamado quando o PIN dos pais confere — libera o ambiente por um tempo. */
  onPinUnlock?: () => void;
};

const MESSAGE_BY_REASON: Record<ScheduleReason, string> = {
  bedtime: 'A hora de dormir começou. Descansa que amanhã tem mais!',
  weekday: 'Hoje é dia de pausa de tela. Aproveita pra fazer outras coisas!',
  limit: 'Você usou todo o tempo de tela de hoje. Amanhã recarrega!',
};

const LABEL_BY_REASON: Record<ScheduleReason, string> = {
  bedtime: 'Bedtime',
  weekday: 'Dia de pausa',
  limit: 'Tempo esgotado',
};

const ICON_BY_REASON: Record<ScheduleReason, string> = {
  bedtime: 'bedtime',
  weekday: 'bedtime',
  limit: 'timer_off',
};

const ALTERNATIVES = [
  { id: 'a1', icon: 'menu_book', label: 'Ler um livro' },
  { id: 'a2', icon: 'extension', label: 'Montar quebra-cabeça' },
  { id: 'a3', icon: 'bedtime', label: 'Descansar os olhos' },
];

function formatHMS(sec: number) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  return [h, m, s].map((n) => String(n).padStart(2, '0')).join(':');
}

function secondsUntil(iso: string | null): number {
  if (!iso) return 0;
  const diff = Math.floor((new Date(iso).getTime() - Date.now()) / 1000);
  return diff > 0 ? diff : 0;
}

export function Blocked({
  onNavigate,
  reason,
  unlockAt,
  lockedMode = false,
  pinUnlockEnabled = false,
  onPinUnlock,
}: BlockedProps) {
  const [remaining, setRemaining] = useState(() => secondsUntil(unlockAt));

  useEffect(() => {
    setRemaining(secondsUntil(unlockAt));
    const id = window.setInterval(() => {
      setRemaining(secondsUntil(unlockAt));
    }, 1000);
    return () => window.clearInterval(id);
  }, [unlockAt]);

  const effectiveReason: ScheduleReason = reason ?? 'bedtime';

  return (
    <main className="flex min-h-screen flex-1 flex-col items-center bg-gradient-to-b from-primary to-primary-container px-container-padding-mobile pb-24 pt-stack-lg text-white">
      <div className="flex w-full justify-end">
        {!lockedMode && (
          <button
            type="button"
            onClick={() => onNavigate('home')}
            aria-label="Voltar"
            className="rounded-full p-2 text-white/80 hover:bg-white/10"
          >
            <Icon name="close" />
          </button>
        )}
      </div>

      <div className="mt-6 flex flex-col items-center text-center">
        <div className="relative flex h-32 w-32 items-center justify-center rounded-full bg-white/10 ring-8 ring-white/5">
          <span
            className="material-symbols-outlined text-white"
            style={{ fontSize: 72, fontVariationSettings: "'FILL' 1" }}
          >
            {ICON_BY_REASON[effectiveReason]}
          </span>
        </div>

        <span className="mt-6 inline-flex items-center gap-2 rounded-full bg-tertiary-fixed-dim/25 px-3 py-1 text-label-sm font-bold text-tertiary-fixed">
          <Icon name="lock" className="text-sm" filled />
          Modo {LABEL_BY_REASON[effectiveReason]}
        </span>

        <h1 className="mt-3 font-display text-headline-lg text-white">
          Este conteúdo não está disponível agora
        </h1>
        <p className="mt-2 max-w-sm text-body-md text-white/85">
          {MESSAGE_BY_REASON[effectiveReason]}
        </p>
        <p className="mt-3 max-w-sm text-label-md text-white/70">
          Converse com seus responsáveis caso precise de acesso.
        </p>
      </div>

      <section className="mt-8 w-full max-w-sm">
        <p className="text-center text-label-sm uppercase tracking-wider text-white/70">
          Libera em
        </p>
        <div className="mt-2 flex justify-center">
          <div className="glass-panel rounded-2xl px-6 py-4 text-center text-primary shadow-ambient">
            <span className="font-display text-display-lg font-bold leading-none tabular-nums">
              {formatHMS(remaining)}
            </span>
          </div>
        </div>
      </section>

      <section className="mt-8 w-full max-w-sm">
        <p className="mb-3 px-1 text-label-md font-bold text-white/85">Que tal isso?</p>
        <div className="space-y-2">
          {ALTERNATIVES.map((alt) => (
            <div
              key={alt.id}
              className="glass-panel flex items-center gap-3 rounded-2xl p-3 text-primary shadow-ambient"
            >
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
                <Icon name={alt.icon} className="text-xl" filled />
              </div>
              <div className="text-label-md font-semibold text-on-surface">{alt.label}</div>
            </div>
          ))}
        </div>
      </section>

      <button
        type="button"
        onClick={() => onNavigate('requests')}
        className="mt-8 inline-flex w-full max-w-sm items-center justify-center gap-2 rounded-xl bg-orange-warm py-3 text-label-md font-bold text-white shadow-sm transition-colors hover:bg-orange-warm/90"
      >
        <Icon name="more_time" className="text-sm" filled />
        Solicitar acesso
      </button>

      <p className="mt-3 text-center text-label-sm text-white/60">
        Seus responsáveis vão receber a solicitação na hora.
      </p>

      {lockedMode && pinUnlockEnabled && onPinUnlock ? (
        <PinUnlock onUnlock={onPinUnlock} />
      ) : null}
    </main>
  );
}

function PinUnlock({ onUnlock }: { onUnlock: () => void }) {
  const [open, setOpen] = useState(false);
  const [pin, setPin] = useState('');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    setPending(true);
    setError(null);
    try {
      const res = await verifyPin(pin);
      if (res.ok) {
        onUnlock();
      } else {
        setError('PIN incorreto.');
        setPin('');
      }
    } catch {
      setError('Não foi possível verificar agora. Tente de novo.');
    } finally {
      setPending(false);
    }
  };

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="mt-6 text-label-sm font-semibold text-white/70 underline underline-offset-4 hover:text-white"
      >
        Sou responsável · desbloquear com PIN
      </button>
    );
  }

  return (
    <div className="mt-6 w-full max-w-sm rounded-2xl bg-white/10 p-4">
      <label className="block text-label-sm font-semibold text-white/85">
        PIN dos responsáveis
        <input
          type="password"
          inputMode="numeric"
          autoFocus
          value={pin}
          onChange={(e) => setPin(e.target.value.replace(/\D/g, '').slice(0, 6))}
          className="mt-1 w-full rounded-lg bg-white/90 px-3 py-2 text-center font-mono text-lg tracking-widest text-primary focus:outline-none"
        />
      </label>
      {error ? (
        <p role="alert" className="mt-2 text-label-sm text-tertiary-fixed">
          {error}
        </p>
      ) : null}
      <div className="mt-3 flex gap-2">
        <button
          type="button"
          onClick={() => {
            setOpen(false);
            setPin('');
            setError(null);
          }}
          className="flex-1 rounded-lg bg-white/15 py-2 text-label-md font-semibold text-white"
        >
          Cancelar
        </button>
        <button
          type="button"
          onClick={submit}
          disabled={pin.length < 4 || pending}
          className="flex-1 rounded-lg bg-white py-2 text-label-md font-bold text-primary disabled:opacity-50"
        >
          {pending ? 'Verificando…' : 'Liberar'}
        </button>
      </div>
    </div>
  );
}
