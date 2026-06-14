import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState, type ChangeEvent } from 'react';
import {
  deleteChild,
  listChildren,
  pauseChild,
  resumeChild,
  updateChild,
  uploadAvatar,
} from '../api/children';
import type { Child } from '../api/types';
import { ApiError } from '../api/client';
import { AddChildDialog } from '../components/AddChildDialog';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { PairDeviceDialog } from '../components/PairDeviceDialog';

function formatHM(min: number) {
  const h = Math.floor(min / 60);
  const m = min % 60;
  return `${h}h ${String(m).padStart(2, '0')}m`;
}

function errorMessage(err: unknown): string {
  if (err instanceof ApiError) return `${err.message} (${err.status})`;
  if (err instanceof Error) return err.message;
  return 'Erro desconhecido.';
}

export function Children() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['children'],
    queryFn: listChildren,
  });
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<Child | null>(null);
  const [pairing, setPairing] = useState<Child | null>(null);

  const openCreate = () => {
    setEditing(null);
    setDialogOpen(true);
  };
  const openEdit = (child: Child) => {
    setEditing(child);
    setDialogOpen(true);
  };
  const closeDialog = () => {
    setDialogOpen(false);
    setEditing(null);
  };

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Filhos"
        subtitle="Gerencie os perfis das suas crianças e configure individualmente."
        action={
          <button
            type="button"
            onClick={openCreate}
            className="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-3 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container"
          >
            <Icon name="add" className="text-lg" />
            Conectar Dispositivo Infantil
          </button>
        }
      />

      {isLoading && <LoadingState />}
      {error && <ErrorState error={error} />}
      {data && (
        <div className="grid grid-cols-1 gap-gutter md:grid-cols-2 xl:grid-cols-3">
          {data.map((child) => (
            <ChildProfileCard
              key={child.id}
              child={child}
              onPair={() => setPairing(child)}
              onEdit={() => openEdit(child)}
            />
          ))}
          <AddChildCard onClick={openCreate} />
        </div>
      )}

      <AddChildDialog
        open={dialogOpen}
        onClose={closeDialog}
        child={editing ?? undefined}
      />
      {pairing && (
        <PairDeviceDialog
          childId={pairing.id}
          childName={pairing.name}
          open
          onClose={() => setPairing(null)}
        />
      )}
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
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar</p>
      <p className="text-label-sm text-error/80">{errorMessage(error)}</p>
    </div>
  );
}

type CardProps = {
  child: Child;
  onPair: () => void;
  onEdit: () => void;
};

function ChildProfileCard({ child, onPair, onEdit }: CardProps) {
  const queryClient = useQueryClient();
  const pct =
    child.limitMinutes > 0 ? Math.round((child.usedMinutes / child.limitMinutes) * 100) : 0;
  const online = child.status === 'online';
  const paused = child.status === 'paused';

  const [menuOpen, setMenuOpen] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const menuRef = useRef<HTMLDivElement | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  useEffect(() => {
    if (!menuOpen) return;
    function onDown(e: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setMenuOpen(false);
      }
    }
    window.addEventListener('mousedown', onDown);
    return () => window.removeEventListener('mousedown', onDown);
  }, [menuOpen]);

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['children'] });

  const pauseMutation = useMutation({
    mutationFn: () => (paused ? resumeChild(child.id) : pauseChild(child.id)),
    onSuccess: invalidate,
  });

  const deleteMutation = useMutation({
    mutationFn: () => deleteChild(child.id),
    onSuccess: invalidate,
  });

  const avatarMutation = useMutation({
    mutationFn: async (file: File) => {
      const url = await uploadAvatar(file);
      return updateChild(child.id, { avatar_url: url });
    },
    onSuccess: () => {
      setUploadError(null);
      invalidate();
    },
    onError: (err) => setUploadError(errorMessage(err)),
  });

  const handleAvatarClick = () => {
    if (avatarMutation.isPending) return;
    fileInputRef.current?.click();
  };
  const handleFileChange = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (file) avatarMutation.mutate(file);
  };

  const handleDelete = () => {
    setMenuOpen(false);
    const ok = window.confirm(
      `Excluir ${child.name}? Essa ação remove o perfil e não pode ser desfeita.`,
    );
    if (ok) deleteMutation.mutate();
  };

  const handlePauseToggle = () => {
    setMenuOpen(false);
    pauseMutation.mutate();
  };

  const handleEditClick = () => {
    setMenuOpen(false);
    onEdit();
  };
  const handlePairClick = () => {
    setMenuOpen(false);
    onPair();
  };

  const statusBadge = paused
    ? { className: 'bg-error/10 text-error', dot: 'bg-error', label: 'Pausado' }
    : online
      ? {
          className: 'bg-secondary-container/30 text-secondary',
          dot: 'bg-secondary',
          label: 'Online agora',
        }
      : {
          className: 'bg-surface-variant/50 text-on-surface-variant',
          dot: 'bg-outline-variant',
          label: 'Offline',
        };

  const pauseLabel = paused ? 'Retomar' : 'Pausar';
  const pauseIcon = paused ? 'play_arrow' : 'pause';
  const pausePending = pauseMutation.isPending;

  return (
    <article className="glass-panel relative overflow-hidden rounded-2xl p-6 shadow-ambient transition-shadow hover:shadow-md">
      <div className="flex items-start gap-4">
        <div className="relative">
          <button
            type="button"
            onClick={handleAvatarClick}
            disabled={avatarMutation.isPending}
            aria-label="Trocar foto"
            className="group relative block h-20 w-20 overflow-hidden rounded-2xl border-2 border-surface-variant focus:outline-none focus:ring-2 focus:ring-primary"
          >
            {child.avatarUrl ? (
              <img
                src={child.avatarUrl}
                alt={`${child.name} avatar`}
                className={`h-full w-full object-cover ${online ? '' : 'grayscale-[20%]'}`}
              />
            ) : (
              <div
                className={`flex h-full w-full items-center justify-center bg-surface-container font-display text-2xl text-on-surface-variant ${
                  online ? '' : 'grayscale-[20%]'
                }`}
              >
                {child.name.charAt(0).toUpperCase()}
              </div>
            )}
            <div className="absolute inset-0 flex items-center justify-center bg-on-surface/40 text-white opacity-0 transition-opacity group-hover:opacity-100">
              {avatarMutation.isPending ? (
                <Icon name="progress_activity" className="animate-spin text-2xl" />
              ) : (
                <Icon name="photo_camera" className="text-2xl" />
              )}
            </div>
          </button>
          <input
            ref={fileInputRef}
            type="file"
            accept="image/*"
            className="hidden"
            onChange={handleFileChange}
          />
          <div
            className={`absolute -bottom-1 -right-1 h-4 w-4 rounded-full border-2 border-white ${
              online ? 'bg-secondary pulse-green' : paused ? 'bg-error' : 'bg-outline-variant'
            }`}
          />
        </div>
        <div className="flex-1">
          <div className="flex items-center justify-between gap-1">
            <h3 className="font-display text-headline-md text-on-surface">{child.name}</h3>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={onPair}
                aria-label="Parear dispositivo"
                title="Parear dispositivo"
                className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50 hover:text-primary"
              >
                <Icon name="tablet_mac" />
              </button>
              <div ref={menuRef} className="relative">
                <button
                  type="button"
                  aria-label="Mais ações"
                  aria-haspopup="menu"
                  aria-expanded={menuOpen}
                  onClick={() => setMenuOpen((v) => !v)}
                  className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50 hover:text-primary"
                >
                  <Icon name="more_vert" />
                </button>
                {menuOpen && (
                  <div
                    role="menu"
                    className="absolute right-0 z-10 mt-1 w-48 overflow-hidden rounded-xl border border-outline-variant bg-surface shadow-ambient"
                  >
                    <MenuItem icon="tablet_mac" onClick={handlePairClick}>
                      Parear dispositivo
                    </MenuItem>
                    <MenuItem icon="edit" onClick={handleEditClick}>
                      Editar
                    </MenuItem>
                    <MenuItem
                      icon={pauseIcon}
                      onClick={handlePauseToggle}
                      disabled={pausePending}
                    >
                      {pauseLabel}
                    </MenuItem>
                    <MenuItem
                      icon="delete"
                      onClick={handleDelete}
                      disabled={deleteMutation.isPending}
                      destructive
                    >
                      Excluir
                    </MenuItem>
                  </div>
                )}
              </div>
            </div>
          </div>
          <p className="mt-1 text-label-sm text-on-surface-variant">
            {child.age !== null ? `${child.age} anos` : 'Idade não informada'}
            {child.device ? ` • ${child.device}` : ''}
          </p>
          <span
            className={`mt-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-label-sm ${statusBadge.className}`}
          >
            <span className={`h-1.5 w-1.5 rounded-full ${statusBadge.dot}`} />
            {statusBadge.label}
          </span>
          {uploadError && (
            <p role="alert" className="mt-1 text-label-sm text-error">
              {uploadError}
            </p>
          )}
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
          onClick={onEdit}
          className="flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline-variant bg-surface-container py-2 text-label-sm font-semibold text-on-surface transition-colors hover:bg-surface-variant"
        >
          <Icon name="edit" className="text-sm" />
          Editar
        </button>
        <button
          type="button"
          onClick={() => pauseMutation.mutate()}
          disabled={pausePending}
          className={`flex flex-1 items-center justify-center gap-1 rounded-lg border border-outline-variant py-2 text-label-sm font-semibold transition-colors disabled:opacity-60 ${
            paused
              ? 'bg-secondary-container/30 text-secondary hover:bg-secondary-container/50'
              : online
                ? 'bg-error/10 text-error hover:bg-error/20'
                : 'bg-surface-container text-on-surface hover:bg-surface-variant'
          }`}
        >
          <Icon
            name={pausePending ? 'progress_activity' : pauseIcon}
            className={`text-sm ${pausePending ? 'animate-spin' : ''}`}
          />
          {pauseLabel}
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

function MenuItem({
  icon,
  onClick,
  disabled,
  destructive,
  children,
}: {
  icon: string;
  onClick: () => void;
  disabled?: boolean;
  destructive?: boolean;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      role="menuitem"
      onClick={onClick}
      disabled={disabled}
      className={`flex w-full items-center gap-2 px-3 py-2 text-left text-label-md transition-colors disabled:opacity-50 ${
        destructive
          ? 'text-error hover:bg-error/10'
          : 'text-on-surface hover:bg-surface-variant/60'
      }`}
    >
      <Icon name={icon} className="text-base" />
      {children}
    </button>
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
      <div className="font-display text-headline-md">Conectar Dispositivo Infantil</div>
      <p className="text-center text-label-sm">
        Crie um novo perfil com avatar e configure regras individuais.
      </p>
    </button>
  );
}
