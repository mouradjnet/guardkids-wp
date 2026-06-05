import { useMutation } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { validateToken } from '../api/child';
import { ApiError } from '../api/client';
import { Icon } from '../components/Icon';

type Props = {
  onPaired: (token: string) => void;
};

const TOKEN_LENGTH = 64;

export function PairScreen({ onPaired }: Props) {
  const [raw, setRaw] = useState('');

  const mutation = useMutation({
    mutationFn: (token: string) => validateToken(token),
    onSuccess: (_data, token) => onPaired(token),
  });

  function submit(e: FormEvent) {
    e.preventDefault();
    const token = raw.trim().replace(/\s+/g, '').toLowerCase();
    if (token.length !== TOKEN_LENGTH || !/^[a-f0-9]+$/.test(token)) {
      mutation.reset();
      return;
    }
    mutation.mutate(token);
  }

  const errorMessage =
    mutation.error instanceof ApiError
      ? `Token rejeitado: ${mutation.error.message}`
      : mutation.error instanceof Error
        ? mutation.error.message
        : null;

  const cleaned = raw.trim().replace(/\s+/g, '');
  const tokenLooksValid = cleaned.length === TOKEN_LENGTH && /^[a-f0-9]+$/i.test(cleaned);

  return (
    <main className="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-primary-container/30 via-surface to-secondary-container/30 p-6">
      <div className="glass-panel w-full max-w-md rounded-3xl bg-surface p-8 shadow-ambient">
        <div className="mb-5 flex flex-col items-center gap-3 text-center">
          <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary text-white shadow-ambient">
            <Icon name="shield_lock" className="text-3xl" filled />
          </div>
          <h1 className="font-display text-headline-lg text-on-surface">Conectar dispositivo</h1>
          <p className="text-label-md text-on-surface-variant">
            Peça pro seu responsável gerar um token de pareamento no painel deles e cole abaixo.
          </p>
        </div>

        <form onSubmit={submit} className="space-y-4">
          <label htmlFor="pair-token" className="block">
            <span className="mb-1 block text-label-sm font-semibold text-on-surface-variant">
              Token (64 caracteres hexadecimais)
            </span>
            <textarea
              id="pair-token"
              value={raw}
              onChange={(e) => setRaw(e.target.value)}
              autoComplete="off"
              spellCheck={false}
              rows={3}
              placeholder="cole o token aqui"
              className="w-full resize-none rounded-xl border border-outline-variant bg-surface-container-low p-3 font-mono text-label-sm text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
            />
            <span className="mt-1 block text-right text-label-sm text-on-surface-variant">
              {cleaned.length}/{TOKEN_LENGTH}
            </span>
          </label>

          {errorMessage ? (
            <p role="alert" className="rounded-lg bg-error/10 p-3 text-label-sm text-error">
              {errorMessage}
            </p>
          ) : null}

          <button
            type="submit"
            disabled={!tokenLooksValid || mutation.isPending}
            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container disabled:opacity-60"
          >
            {mutation.isPending ? (
              <>
                <Icon name="progress_activity" className="animate-spin" />
                Validando…
              </>
            ) : (
              <>
                <Icon name="link" />
                Conectar
              </>
            )}
          </button>
        </form>
      </div>
    </main>
  );
}
