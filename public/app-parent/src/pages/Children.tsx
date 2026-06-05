import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { listChildren } from '../api/children';
import type { Child } from '../api/types';
import { ApiError } from '../api/client';
import { AddChildDialog } from '../components/AddChildDialog';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';

function formatHM(min: number) {
  const h = Math.floor(min / 60);
  const m = min % 60;
  return `${h}h ${String(m).padStart(2, '0')}m`;
}

export function Children() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['children'],
    queryFn: listChildren,
  });
  const [dialogOpen, setDialogOpen] = useState(false);
  const openDialog = () => setDialogOpen(true);
  const closeDialog = () => setDialogOpen(false);

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Filhos"
        subtitle="Gerencie os perfis das suas crianças e configure individualmente."
        action={
          <button
            type="button"
            onClick={openDialog}
            className="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-3 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container"
          >
            <Icon name="add" className="text-lg" />
            Adicionar Novo Filho
          </button>
        }
      />

      {isLoading && <LoadingState />}
      {error && <ErrorState error={error} />}
      {data && (
        <div className="grid grid-cols-1 gap-gutter md:grid-cols-2 xl:grid-cols-3">
          {data.map((child) => (
            <ChildProfileCard key={child.id} child={child} />
          ))}
          <AddChildCard onClick={openDialog} />
        </div>
      )}

      <AddChildDialog open={dialogOpen} onClose={closeDialog} />
    </main>
  );
}

function LoadingState() {
  return (
    <div className="glass-panel flex h-40 items-center justify-center rounded-2xl text-on-surface-variant">
      <Icon name="progress_activity" className="animate-spin text-2xl" />
      <span className="ml-2 text-label-md">Carregando filhos…</span>
    </div>
  );
}

function ErrorState({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido ao carregar filhos.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}

function ChildProfileCard({ child }: { child: Child }) {
  const pct = child.limitMinutes > 0 ? Math.round((child.usedMinutes / child.limitMinutes) * 100) : 0;
  const online = child.status === 'online';

  return (
    <article className="glass-panel relative overflow-hidden rounded-2xl p-6 shadow-ambient transition-shadow hover:shadow-md">
      <div className="flex items-start gap-4">
        <div className="relative">
          {child.avatarUrl ? (
            <img
              src={child.avatarUrl}
              alt={`${child.name} avatar`}
              className={`h-20 w-20 rounded-2xl border-2 border-surface-variant object-cover ${
                online ? '' : 'grayscale-[20%]'
              }`}
            />
          ) : (
            <div
              className={`flex h-20 w-20 items-center justify-center rounded-2xl border-2 border-surface-variant bg-surface-container font-display text-2xl text-on-surface-variant ${
                online ? '' : 'grayscale-[20%]'
              }`}
            >
              {child.name.charAt(0).toUpperCase()}
            </div>
          )}
          <div
            className={`absolute -bottom-1 -right-1 h-4 w-4 rounded-full border-2 border-white ${
              online ? 'bg-secondary pulse-green' : 'bg-outline-variant'
            }`}
          />
        </div>
        <div className="flex-1">
          <div className="flex items-center justify-between">
            <h3 className="font-display text-headline-md text-on-surface">{child.name}</h3>
            <button
              type="button"
              aria-label="Mais ações"
              className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50 hover:text-primary"
            >
              <Icon name="more_vert" />
            </button>
          </div>
          <p className="mt-1 text-label-sm text-on-surface-variant">
            {child.age !== null ? `${child.age} anos` : 'Idade não informada'}
            {child.device ? ` • ${child.device}` : ''}
          </p>
          <span
            className={`mt-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-label-sm ${
              online
                ? 'bg-secondary-container/30 text-secondary'
                : 'bg-surface-variant/50 text-on-surface-variant'
            }`}
          >
            <span
              className={`h-1.5 w-1.5 rounded-full ${
                online ? 'bg-secondary' : 'bg-outline-variant'
              }`}
            />
            {online ? 'Online agora' : 'Offline'}
          </span>
        </div>
      </div>

      <div className="mt-5 grid grid-cols-2 gap-2 text-center">
        <MetricChip label="Hoje" value={formatHM(child.usedMinutes)} icon="schedule" />
        <MetricChip label="Limite" value={formatHM(child.limitMinutes)} icon="timer" />
      </div>

      <div className="mt-5">
        <div className="mb-1 flex items-center justify-between text-label-sm">
          <span className="text-on-surface-variant">Tempo usado hoje</span>
          <span className="font-semibold text-on-surface">{pct}%</span>
        </div>
        <div className="h-2 w-full overflow-hidden rounded-full bg-surface-container">
          <div
            className="h-full rounded-full bg-primary transition-all"
            style={{ width: `${Math.min(pct, 100)}%` }}
          />
        </div>
      </div>

      <div className="mt-5 flex gap-2">
        <button
          type="button"
          className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline-variant bg-surface-container py-2 text-label-sm font-semibold text-on-surface transition-colors hover:bg-surface-variant"
        >
          <Icon name="edit" className="text-sm" />
          Editar
        </button>
        <button
          type="button"
          className={`flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline-variant py-2 text-label-sm font-semibold transition-colors ${
            online
              ? 'bg-error/10 text-error hover:bg-error/20'
              : 'bg-surface-container text-on-surface-variant'
          }`}
          disabled={!online}
        >
          <Icon name={online ? 'pause' : 'play_arrow'} className="text-sm" />
          {online ? 'Pausar' : 'Retomar'}
        </button>
        <button
          type="button"
          className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline-variant bg-surface-container py-2 text-label-sm font-semibold text-on-surface transition-colors hover:bg-surface-variant"
        >
          <Icon name="history" className="text-sm" />
          Histórico
        </button>
      </div>
    </article>
  );
}

function MetricChip({
  label,
  value,
  icon,
}: {
  label: string;
  value: string;
  icon: string;
}) {
  return (
    <div className="rounded-xl border border-outline-variant bg-surface-container-low px-3 py-2">
      <div className="flex items-center justify-center gap-1 text-on-surface-variant">
        <Icon name={icon} className="text-sm" />
        <span className="text-label-sm">{label}</span>
      </div>
      <div className="mt-1 font-display text-base font-bold text-primary">{value}</div>
    </div>
  );
}

function AddChildCard({ onClick }: { onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="group flex min-h-[280px] flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed border-outline-variant bg-surface-container-low p-6 text-on-surface-variant transition-colors hover:border-primary hover:bg-surface-container hover:text-primary"
    >
      <div className="flex h-14 w-14 items-center justify-center rounded-full bg-surface-container-high text-primary transition-colors group-hover:bg-primary group-hover:text-white">
        <Icon name="add" className="text-3xl" />
      </div>
      <div className="font-display text-headline-md">Adicionar Novo Filho</div>
      <p className="text-center text-label-sm">
        Crie um novo perfil com avatar e configure regras individuais.
      </p>
    </button>
  );
}
