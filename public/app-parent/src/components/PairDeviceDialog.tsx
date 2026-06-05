import { useMutation } from '@tanstack/react-query';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { pairChildDevice } from '../api/children';
import { ApiError } from '../api/client';
import { Icon } from './Icon';

type Props = {
  childId: number;
  childName: string;
  open: boolean;
  onClose: () => void;
};

export function PairDeviceDialog({ childId, childName, open, onClose }: Props) {
  const [label, setLabel] = useState('');
  const [copied, setCopied] = useState(false);
  const labelInputRef = useRef<HTMLInputElement>(null);

  const mutation = useMutation({
    mutationFn: () => pairChildDevice(childId, label.trim() || undefined),
  });

  const handleClose = () => {
    mutation.reset();
    setLabel('');
    setCopied(false);
    onClose();
  };

  useEffect(() => {
    if (!open) return;
    const t = window.setTimeout(() => labelInputRef.current?.focus(), 50);
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') handleClose();
    };
    window.addEventListener('keydown', onKey);
    return () => {
      window.clearTimeout(t);
      window.removeEventListener('keydown', onKey);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  if (!open) return null;

  function submit(e: FormEvent) {
    e.preventDefault();
    if (mutation.data) return;
    mutation.mutate();
  }

  async function copy() {
    if (!mutation.data) return;
    try {
      await navigator.clipboard.writeText(mutation.data.token);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      // fallback se Clipboard API negada
      const ta = document.createElement('textarea');
      ta.value = mutation.data.token;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      ta.remove();
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    }
  }

  const errorMessage =
    mutation.error instanceof ApiError
      ? `${mutation.error.message} (${mutation.error.status})`
      : mutation.error instanceof Error
        ? mutation.error.message
        : null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="pair-device-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-on-surface/40 p-4"
      onClick={(e) => {
        if (e.target === e.currentTarget) handleClose();
      }}
    >
      <div className="glass-panel w-full max-w-md rounded-2xl bg-surface p-6 shadow-ambient">
        <div className="flex items-start justify-between">
          <h2 id="pair-device-title" className="font-display text-headline-md text-on-surface">
            Parear dispositivo
          </h2>
          <button
            type="button"
            aria-label="Fechar"
            onClick={handleClose}
            className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50 hover:text-primary"
          >
            <Icon name="close" />
          </button>
        </div>
        <p className="mt-1 text-label-sm text-on-surface-variant">
          Gera um token único pro app-child do <strong>{childName}</strong>. O token aparece{' '}
          <strong>uma vez</strong> — copie e cole no dispositivo da criança.
        </p>

        {!mutation.data ? (
          <form onSubmit={submit} className="mt-5 space-y-4">
            <label htmlFor="pd-label" className="block">
              <span className="mb-1 block text-label-sm font-semibold text-on-surface-variant">
                Nome do dispositivo (opcional)
              </span>
              <input
                ref={labelInputRef}
                id="pd-label"
                type="text"
                value={label}
                onChange={(e) => setLabel(e.target.value)}
                placeholder="Ex.: Tablet do Lucas"
                className="w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-base text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none"
              />
            </label>

            {errorMessage ? (
              <p role="alert" className="rounded-lg bg-error/10 p-3 text-label-sm text-error">
                {errorMessage}
              </p>
            ) : null}

            <div className="flex justify-end gap-2">
              <button
                type="button"
                onClick={handleClose}
                disabled={mutation.isPending}
                className="rounded-lg border border-outline-variant bg-surface-container px-4 py-2 text-label-md font-semibold text-on-surface hover:bg-surface-variant disabled:opacity-50"
              >
                Cancelar
              </button>
              <button
                type="submit"
                disabled={mutation.isPending}
                className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container disabled:opacity-60"
              >
                {mutation.isPending ? (
                  <>
                    <Icon name="progress_activity" className="animate-spin text-sm" />
                    Gerando…
                  </>
                ) : (
                  'Gerar token'
                )}
              </button>
            </div>
          </form>
        ) : (
          <div className="mt-5 space-y-4">
            <div className="rounded-xl border border-tertiary-container/60 bg-tertiary-container/20 p-3 text-label-sm text-on-tertiary-fixed-variant">
              <div className="flex items-start gap-2">
                <Icon name="warning" className="text-base text-tertiary-container" filled />
                <span>{mutation.data.notice}</span>
              </div>
            </div>

            <div>
              <span className="block text-label-sm font-semibold text-on-surface-variant">
                Token
              </span>
              <div className="mt-1 flex items-center gap-2 rounded-lg border border-outline-variant bg-surface-container-low p-3">
                <code className="flex-1 break-all font-mono text-label-sm text-on-surface">
                  {mutation.data.token}
                </code>
                <button
                  type="button"
                  onClick={copy}
                  className="inline-flex shrink-0 items-center gap-1 rounded-md bg-primary px-3 py-1.5 text-label-sm font-semibold text-white hover:bg-primary-container"
                >
                  <Icon name={copied ? 'check' : 'content_copy'} className="text-sm" />
                  {copied ? 'Copiado' : 'Copiar'}
                </button>
              </div>
            </div>

            <p className="text-label-sm text-on-surface-variant">
              No dispositivo da criança, abra o app-child e cole esse token na tela de pareamento.
            </p>

            <div className="flex justify-end">
              <button
                type="button"
                onClick={handleClose}
                className="rounded-lg bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-ambient hover:bg-primary-container"
              >
                Concluído
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
