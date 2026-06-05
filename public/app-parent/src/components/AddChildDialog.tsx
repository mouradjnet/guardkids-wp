import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { createChild } from '../api/children';
import { ApiError } from '../api/client';
import { Icon } from './Icon';

type Props = {
  open: boolean;
  onClose: () => void;
};

const inputClass =
  'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-base text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none';

export function AddChildDialog({ open, onClose }: Props) {
  const queryClient = useQueryClient();
  const firstInputRef = useRef<HTMLInputElement>(null);
  const [name, setName] = useState('');
  const [age, setAge] = useState('');
  const [device, setDevice] = useState('');
  const [limitMinutes, setLimitMinutes] = useState('60');

  const mutation = useMutation({
    mutationFn: createChild,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['children'] });
      setName('');
      setAge('');
      setDevice('');
      setLimitMinutes('60');
      onClose();
    },
  });

  const handleClose = () => {
    mutation.reset();
    onClose();
  };

  useEffect(() => {
    if (!open) return;
    const t = window.setTimeout(() => firstInputRef.current?.focus(), 50);
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') handleClose();
    };
    window.addEventListener('keydown', onKey);
    return () => {
      window.clearTimeout(t);
      window.removeEventListener('keydown', onKey);
    };
    // handleClose recriado a cada render mas o efeito só roda quando `open` muda
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  if (!open) return null;

  function submit(e: FormEvent) {
    e.preventDefault();
    const trimmed = name.trim();
    if (!trimmed) return;
    mutation.mutate({
      name: trimmed,
      age: age === '' ? null : Number(age),
      device: device.trim() || null,
      limit_minutes: limitMinutes === '' ? 60 : Number(limitMinutes),
    });
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
      aria-labelledby="add-child-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-on-surface/40 p-4"
      onClick={(e) => {
        if (e.target === e.currentTarget) handleClose();
      }}
    >
      <form
        onSubmit={submit}
        className="glass-panel w-full max-w-md rounded-2xl bg-surface p-6 shadow-ambient"
      >
        <div className="flex items-start justify-between">
          <h2
            id="add-child-title"
            className="font-display text-headline-md text-on-surface"
          >
            Adicionar novo filho
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
          Crie o perfil — você poderá ajustar regras e avatar depois.
        </p>

        <div className="mt-5 space-y-4">
          <Field label="Nome *" htmlFor="ac-name">
            <input
              ref={firstInputRef}
              id="ac-name"
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              className={inputClass}
              placeholder="Ex.: Lucas"
            />
          </Field>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Idade" htmlFor="ac-age">
              <input
                id="ac-age"
                type="number"
                min={0}
                max={21}
                value={age}
                onChange={(e) => setAge(e.target.value)}
                className={inputClass}
                placeholder="9"
              />
            </Field>

            <Field label="Limite diário (min)" htmlFor="ac-limit">
              <input
                id="ac-limit"
                type="number"
                min={0}
                max={1440}
                value={limitMinutes}
                onChange={(e) => setLimitMinutes(e.target.value)}
                className={inputClass}
                placeholder="60"
              />
            </Field>
          </div>

          <Field label="Dispositivo" htmlFor="ac-device">
            <input
              id="ac-device"
              type="text"
              value={device}
              onChange={(e) => setDevice(e.target.value)}
              className={inputClass}
              placeholder="Tablet do Lucas"
            />
          </Field>
        </div>

        {errorMessage && (
          <p
            role="alert"
            className="mt-4 rounded-lg bg-error/10 p-3 text-label-sm text-error"
          >
            {errorMessage}
          </p>
        )}

        <div className="mt-6 flex justify-end gap-2">
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
            disabled={mutation.isPending || !name.trim()}
            className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container disabled:opacity-60"
          >
            {mutation.isPending ? (
              <>
                <Icon name="progress_activity" className="animate-spin text-sm" />
                Salvando…
              </>
            ) : (
              'Adicionar'
            )}
          </button>
        </div>
      </form>
    </div>
  );
}

function Field({
  label,
  htmlFor,
  children,
}: {
  label: string;
  htmlFor: string;
  children: ReactNode;
}) {
  return (
    <label htmlFor={htmlFor} className="block">
      <span className="mb-1 block text-label-sm font-semibold text-on-surface-variant">
        {label}
      </span>
      {children}
    </label>
  );
}
