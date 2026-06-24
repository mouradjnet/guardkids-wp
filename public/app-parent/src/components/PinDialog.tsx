import { useState } from 'react';
import { Icon } from './Icon';

type Props = {
  open: boolean;
  hasPin: boolean;
  onClose: () => void;
  onConfirm: (pin: string) => void;
  pending: boolean;
  error?: string | null;
};

const PIN_PATTERN = /^\d{4,6}$/;

export function PinDialog({ open, hasPin, onClose, onConfirm, pending, error }: Props) {
  const [pin, setPin] = useState('');
  const [confirm, setConfirm] = useState('');
  if (!open) return null;

  const formatOk = PIN_PATTERN.test(pin);
  const matches = pin === confirm;
  const armed = formatOk && matches && !pending;
  const mismatch = confirm !== '' && !matches;

  const onlyDigits = (v: string) => v.replace(/\D/g, '').slice(0, 6);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-on-surface/40 p-4">
      <div
        role="dialog"
        aria-modal="true"
        className="w-full max-w-md rounded-2xl bg-surface p-6 shadow-ambient"
      >
        <div className="mb-3 flex items-center gap-2 text-primary">
          <Icon name="pin" className="text-2xl" filled />
          <h3 className="font-display text-headline-md text-on-surface">
            {hasPin ? 'Trocar PIN' : 'Definir PIN'}
          </h3>
        </div>
        <p className="mb-4 text-label-md text-on-surface-variant">
          O PIN tem de 4 a 6 dígitos. Use-o no aparelho da criança pra liberar o ambiente
          seguro (ex.: a tela de bloqueio de horário) por alguns minutos.
        </p>

        <label className="block text-label-sm font-semibold text-on-surface">
          Novo PIN
          <input
            type="password"
            inputMode="numeric"
            autoComplete="new-password"
            value={pin}
            onChange={(e) => setPin(onlyDigits(e.target.value))}
            className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 font-mono tracking-widest text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
          />
        </label>

        <label className="mt-3 block text-label-sm font-semibold text-on-surface">
          Confirmar PIN
          <input
            type="password"
            inputMode="numeric"
            autoComplete="new-password"
            value={confirm}
            onChange={(e) => setConfirm(onlyDigits(e.target.value))}
            className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 font-mono tracking-widest text-on-surface focus:outline-none focus:ring-2 focus:ring-primary"
          />
        </label>

        {mismatch ? (
          <p role="alert" className="mt-2 text-label-sm text-error">
            Os PINs não conferem.
          </p>
        ) : null}
        {error ? (
          <p role="alert" className="mt-2 text-label-sm text-error">
            {error}
          </p>
        ) : null}

        <div className="mt-5 flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={pending}
            className="rounded-lg border border-outline-variant bg-surface-container px-4 py-2 text-label-md font-semibold text-on-surface disabled:opacity-60"
          >
            Cancelar
          </button>
          <button
            type="button"
            onClick={() => onConfirm(pin)}
            disabled={!armed}
            className="rounded-lg bg-primary px-4 py-2 text-label-md font-semibold text-white disabled:opacity-50"
          >
            {pending ? 'Salvando…' : 'Salvar PIN'}
          </button>
        </div>
      </div>
    </div>
  );
}
