import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { MutationError } from '../components/MutationError';
import {
  approveRedemption,
  createReward,
  deleteReward,
  denyRedemption,
  listPendingRedemptions,
  listRewards,
  updateReward,
} from '../api/rewards';

export function Recompensas() {
  const qc = useQueryClient();
  const rewards = useQuery({ queryKey: ['rewards'], queryFn: listRewards });
  const pending = useQuery({ queryKey: ['redemptions', 'pending'], queryFn: listPendingRedemptions });

  const [title, setTitle] = useState('');
  const [cost, setCost] = useState('');
  const [error, setError] = useState<string | null>(null);

  const createMut = useMutation({
    mutationFn: () => createReward({ title: title.trim(), costCoins: Number(cost) }),
    onSuccess: () => {
      setTitle('');
      setCost('');
      qc.invalidateQueries({ queryKey: ['rewards'] });
    },
  });
  const toggleMut = useMutation({
    mutationFn: (r: { id: number; active: boolean }) => updateReward(r.id, { active: !r.active }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rewards'] }),
  });
  const removeMut = useMutation({
    mutationFn: (id: number) => deleteReward(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['rewards'] }),
  });
  const decideMut = useMutation({
    mutationFn: (v: { id: number; approve: boolean }) =>
      v.approve ? approveRedemption(v.id) : denyRedemption(v.id),
    onSuccess: () => {
      setError(null);
      qc.invalidateQueries({ queryKey: ['redemptions', 'pending'] });
    },
    onError: () => setError('Não foi possível aprovar: saldo insuficiente do filho.'),
  });

  const list = rewards.data ?? [];
  const queue = pending.data ?? [];

  return (
    <main className="mx-auto w-full max-w-[1440px] flex-1 space-y-6 p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <div>
        <h1 className="font-display text-headline-lg text-on-background">Recompensas</h1>
        <p className="text-body-md text-on-surface-variant">
          Crie recompensas que seus filhos compram com GuardCoins e aprove os resgates.
        </p>
      </div>

      <section className="rounded-2xl border border-outline-variant bg-surface p-4">
        <h2 className="mb-3 font-display text-title-md text-on-surface">Gerir recompensas</h2>
        <div className="mb-4 flex flex-wrap items-end gap-2">
          <label className="flex flex-col text-label-sm text-on-surface-variant">
            Título
            <input
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              className="mt-1 rounded-lg border border-outline-variant bg-surface px-3 py-2 text-on-surface"
            />
          </label>
          <label className="flex flex-col text-label-sm text-on-surface-variant">
            Custo (coins)
            <input
              type="number"
              value={cost}
              onChange={(e) => setCost(e.target.value)}
              className="mt-1 w-32 rounded-lg border border-outline-variant bg-surface px-3 py-2 text-on-surface"
            />
          </label>
          <button
            type="button"
            disabled={title.trim() === '' || Number(cost) < 1 || createMut.isPending}
            onClick={() => createMut.mutate()}
            className="rounded-xl bg-primary px-4 py-2 text-label-md font-semibold text-white disabled:opacity-40"
          >
            Adicionar
          </button>
        </div>
        {list.length === 0 ? (
          <p className="text-label-sm text-on-surface-variant">Nenhuma recompensa ainda.</p>
        ) : (
          <ul className="space-y-2">
            {list.map((r) => (
              <li
                key={r.id}
                className="flex items-center justify-between rounded-lg bg-surface-container-low p-3"
              >
                <span className="text-label-md text-on-surface">
                  {r.title} · <span className="text-orange-500">{r.costCoins}</span>
                  {!r.active && (
                    <span className="ml-2 text-label-sm text-on-surface-variant">(inativa)</span>
                  )}
                </span>
                <span className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => toggleMut.mutate(r)}
                    className="text-label-sm text-primary"
                  >
                    {r.active ? 'Desativar' : 'Ativar'}
                  </button>
                  <button
                    type="button"
                    onClick={() => removeMut.mutate(r.id)}
                    className="text-label-sm text-error"
                  >
                    Remover
                  </button>
                </span>
              </li>
            ))}
          </ul>
        )}
        {removeMut.error ? (
          <MutationError prefix="Falha ao remover" error={removeMut.error} />
        ) : null}
        {(createMut.error ?? toggleMut.error) ? (
          <MutationError
            prefix="Falha na recompensa"
            error={createMut.error ?? toggleMut.error}
          />
        ) : null}
      </section>

      <section className="rounded-2xl border border-outline-variant bg-surface p-4">
        <h2 className="mb-3 font-display text-title-md text-on-surface">Resgates pendentes</h2>
        {error && (
          <p className="mb-2 rounded-lg bg-error/10 p-2 text-label-sm text-error">{error}</p>
        )}
        {queue.length === 0 ? (
          <p className="text-label-sm text-on-surface-variant">Nenhum resgate pendente.</p>
        ) : (
          <ul className="space-y-2">
            {queue.map((q) => (
              <li
                key={q.id}
                className="flex items-center justify-between rounded-lg bg-surface-container-low p-3"
              >
                <span className="text-label-md text-on-surface">
                  <strong>{q.childName}</strong> quer <strong>{q.title}</strong> ·{' '}
                  <span className="text-orange-500">{q.costCoins}</span>
                </span>
                <span className="flex gap-2">
                  <button
                    type="button"
                    onClick={() => decideMut.mutate({ id: q.id, approve: true })}
                    className="rounded-lg bg-primary px-3 py-1 text-label-sm font-semibold text-white"
                  >
                    Aprovar
                  </button>
                  <button
                    type="button"
                    onClick={() => decideMut.mutate({ id: q.id, approve: false })}
                    className="rounded-lg border border-outline-variant px-3 py-1 text-label-sm text-on-surface"
                  >
                    Negar
                  </button>
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </main>
  );
}
