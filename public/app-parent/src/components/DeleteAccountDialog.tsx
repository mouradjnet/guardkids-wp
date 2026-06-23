import { useState } from 'react';
import { Icon } from './Icon';

type Props = {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  pending: boolean;
  error?: string | null;
};

const CONFIRM_WORD = 'EXCLUIR';

export function DeleteAccountDialog({ open, onClose, onConfirm, pending, error }: Props) {
  const [value, setValue] = useState('');
  if (!open) return null;

  const armed = value === CONFIRM_WORD && !pending;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-on-surface/40 p-4">
      <div
        role="dialog"
        aria-modal="true"
        className="w-full max-w-md rounded-2xl bg-surface p-6 shadow-ambient"
      >
        <div className="mb-3 flex items-center gap-2 text-error">
          <Icon name="warning" className="text-2xl" filled />
          <h3 className="font-display text-headline-md">Excluir conta e todos os dados</h3>
        </div>
        <p className="mb-4 text-label-md text-on-surface-variant">
          Isso remove permanentemente filhos, pedidos, regras, histórico e configurações.
          Guardiões e a licença são mantidos. Esta ação não pode ser desfeita.
        </p>
        <label className="block text-label-sm font-semibold text-on-surface">
          Digite <span className="font-mono text-error">{CONFIRM_WORD}</span> para confirmar
          <input
            type="text"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-on-surface focus:outline-none focus:ring-2 focus:ring-error"
          />
        </label>
        {error ? (
          <p role="alert" className="mt-2 text-label-sm text-error">
            Falha ao excluir: {error}
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
            onClick={onConfirm}
            disabled={!armed}
            className="rounded-lg bg-error px-4 py-2 text-label-md font-semibold text-white disabled:opacity-50"
          >
            {pending ? 'Excluindo…' : 'Excluir tudo'}
          </button>
        </div>
      </div>
    </div>
  );
}
