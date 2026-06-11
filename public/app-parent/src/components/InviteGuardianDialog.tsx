import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { createGuardian } from '../api/guardians';
import { ApiError } from '../api/client';
import type { GuardianRole } from '../api/types';
import { Icon } from './Icon';

type Props = {
  open: boolean;
  onClose: () => void;
};

const inputClass =
  'w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 text-base text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none';

export function InviteGuardianDialog({ open, onClose }: Props) {
  const queryClient = useQueryClient();
  const firstInputRef = useRef<HTMLInputElement>(null);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<GuardianRole>('collaborator');

  const mutation = useMutation({
    mutationFn: createGuardian,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['guardians'] });
      setName('');
      setEmail('');
      setRole('collaborator');
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  if (!open) return null;

  function submit(e: FormEvent) {
    e.preventDefault();
    const trimmedName = name.trim();
    const trimmedEmail = email.trim().toLowerCase();
    if (!trimmedName || !trimmedEmail) return;
    mutation.mutate({ name: trimmedName, email: trimmedEmail, role });
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
      aria-labelledby="invite-guardian-title"
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
            id="invite-guardian-title"
            className="font-display text-headline-md text-on-surface"
          >
            Convidar guardião
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
          Adicione alguém à família. O guardião fica como "pendente" até você ativar.
        </p>

        <div className="mt-5 space-y-4">
          <Field label="Nome *" htmlFor="ig-name">
            <input
              ref={firstInputRef}
              id="ig-name"
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
              className={inputClass}
              placeholder="Ex.: Marina"
            />
          </Field>

          <Field label="E-mail *" htmlFor="ig-email">
            <input
              id="ig-email"
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className={inputClass}
              placeholder="marina@familia.com"
            />
          </Field>

          <Field label="Papel" htmlFor="ig-role">
            <select
              id="ig-role"
              value={role}
              onChange={(e) => setRole(e.target.value as GuardianRole)}
              className={inputClass}
            >
              <option value="collaborator">Colaborador (decide pedidos)</option>
              <option value="admin">Administrador (acesso total)</option>
            </select>
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
            disabled={mutation.isPending || !name.trim() || !email.trim()}
            className="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container disabled:opacity-60"
          >
            {mutation.isPending ? (
              <>
                <Icon name="progress_activity" className="animate-spin text-sm" />
                Salvando…
              </>
            ) : (
              'Enviar convite'
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
