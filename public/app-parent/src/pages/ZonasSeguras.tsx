import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { ApiError } from '../api/client';
import { deleteSafeZone, listSafeZones } from '../api/safeZones';
import type { SafeZone } from '../api/types';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { PremiumLock } from '../components/PremiumLock';
import { SafeZoneDialog } from '../components/SafeZoneDialog';
import { useLicense } from '../hooks/useLicense';

export function ZonasSeguras() {
  const license = useLicense();

  if (!license.isLoading && !license.can('location')) {
    return (
      <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
        <PageHeader
          title="Zonas seguras"
          subtitle="Receba alertas quando seu filho chegar ou sair de lugares marcados."
        />
        <div className="min-h-[300px]">
          <PremiumLock
            featureId="location"
            title="Zonas seguras é uma feature Premium"
            description="Defina escola, casa de avós e outros pontos pra ver chegadas e saídas."
          />
        </div>
      </main>
    );
  }

  return <ZonasSegurasContent />;
}

function ZonasSegurasContent() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['safe-zones'],
    queryFn: listSafeZones,
  });
  const queryClient = useQueryClient();
  const [dialogState, setDialogState] = useState<
    { open: false } | { open: true; mode: 'create' } | { open: true; mode: 'edit'; zone: SafeZone }
  >({ open: false });
  const [confirmDelete, setConfirmDelete] = useState<SafeZone | null>(null);

  const deleteMutation = useMutation({
    mutationFn: deleteSafeZone,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['safe-zones'] });
      setConfirmDelete(null);
    },
  });

  const openCreate = () => setDialogState({ open: true, mode: 'create' });
  const openEdit = (zone: SafeZone) => setDialogState({ open: true, mode: 'edit', zone });
  const closeDialog = () => setDialogState({ open: false });

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Zonas Seguras"
        subtitle="Cadastre lugares importantes — casa, escola, casa da avó."
        action={
          <button
            type="button"
            onClick={openCreate}
            className="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-3 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container"
          >
            <Icon name="add" className="text-lg" />
            Nova zona
          </button>
        }
      />

      {isLoading && <LoadingState />}
      {error && <ErrorState error={error} />}
      {data && data.length === 0 && <EmptyState onAdd={openCreate} />}
      {data && data.length > 0 && (
        <div className="grid grid-cols-1 gap-gutter md:grid-cols-2 xl:grid-cols-3">
          {data.map((zone) => (
            <ZoneCard
              key={zone.id}
              zone={zone}
              onEdit={() => openEdit(zone)}
              onDelete={() => setConfirmDelete(zone)}
            />
          ))}
        </div>
      )}

      {dialogState.open && dialogState.mode === 'create' && (
        <SafeZoneDialog open mode="create" onClose={closeDialog} />
      )}
      {dialogState.open && dialogState.mode === 'edit' && (
        <SafeZoneDialog open mode="edit" initial={dialogState.zone} onClose={closeDialog} />
      )}

      {confirmDelete && (
        <ConfirmDialog
          name={confirmDelete.name}
          pending={deleteMutation.isPending}
          onCancel={() => setConfirmDelete(null)}
          onConfirm={() => deleteMutation.mutate(confirmDelete.id)}
        />
      )}
    </main>
  );
}

function ZoneCard({
  zone,
  onEdit,
  onDelete,
}: {
  zone: SafeZone;
  onEdit: () => void;
  onDelete: () => void;
}) {
  return (
    <article className="glass-panel flex flex-col gap-3 rounded-2xl p-6 shadow-ambient">
      <div className="flex items-start gap-3">
        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary/10 text-primary">
          <Icon name="shield" className="text-2xl" />
        </div>
        <div className="flex-1">
          <h3 className="font-display text-headline-md text-on-surface">{zone.name}</h3>
          {zone.address && (
            <p className="mt-1 text-label-sm text-on-surface-variant">{zone.address}</p>
          )}
          <p className="mt-1 text-label-sm text-on-surface-variant">Raio {zone.radiusMeters}m</p>
        </div>
      </div>
      <div className="flex gap-2">
        <button
          type="button"
          onClick={onEdit}
          className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline-variant bg-surface-container py-2 text-label-sm font-semibold text-on-surface hover:bg-surface-variant"
        >
          <Icon name="edit" className="text-sm" />
          Editar
        </button>
        <button
          type="button"
          onClick={onDelete}
          className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline-variant bg-error/10 py-2 text-label-sm font-semibold text-error hover:bg-error/20"
        >
          <Icon name="delete" className="text-sm" />
          Excluir
        </button>
      </div>
    </article>
  );
}

function LoadingState() {
  return (
    <div className="glass-panel flex h-40 items-center justify-center rounded-2xl text-on-surface-variant">
      <Icon name="progress_activity" className="animate-spin text-2xl" />
      <span className="ml-2 text-label-md">Carregando zonas…</span>
    </div>
  );
}

function ErrorState({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}

function EmptyState({ onAdd }: { onAdd: () => void }) {
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-3 rounded-2xl p-8 text-on-surface-variant">
      <Icon name="shield" className="text-4xl text-primary" />
      <p className="font-display text-headline-md text-on-surface">Nenhuma zona cadastrada</p>
      <p className="max-w-md text-center text-label-sm">
        Cadastre os lugares da rotina dos seus filhos — Casa, Escola, Casa da avó — com
        endereço e raio.
      </p>
      <button
        type="button"
        onClick={onAdd}
        className="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-3 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container"
      >
        <Icon name="add" className="text-lg" />
        Criar primeira zona
      </button>
    </div>
  );
}

function ConfirmDialog({
  name,
  pending,
  onCancel,
  onConfirm,
}: {
  name: string;
  pending: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="confirm-delete-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-on-surface/40 p-4"
      onClick={(e) => {
        if (e.target === e.currentTarget) onCancel();
      }}
    >
      <div className="glass-panel w-full max-w-sm rounded-2xl bg-surface p-6 shadow-ambient">
        <h2 id="confirm-delete-title" className="font-display text-headline-md text-on-surface">
          Excluir zona
        </h2>
        <p className="mt-2 text-label-md text-on-surface-variant">
          Tem certeza que quer excluir <strong>{name}</strong>? Essa ação não pode ser desfeita.
        </p>
        <div className="mt-6 flex justify-end gap-2">
          <button
            type="button"
            onClick={onCancel}
            disabled={pending}
            className="rounded-lg border border-outline-variant bg-surface-container px-4 py-2 text-label-md font-semibold text-on-surface hover:bg-surface-variant disabled:opacity-50"
          >
            Cancelar
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={pending}
            className="inline-flex items-center gap-2 rounded-lg bg-error px-4 py-2 text-label-md font-semibold text-white shadow-ambient hover:bg-error/80 disabled:opacity-60"
          >
            {pending ? (
              <>
                <Icon name="progress_activity" className="animate-spin text-sm" />
                Excluindo…
              </>
            ) : (
              'Excluir'
            )}
          </button>
        </div>
      </div>
    </div>
  );
}
