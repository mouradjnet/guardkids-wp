import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent, type ReactNode } from 'react';
import { ApiError } from '../api/client';
import {
  activateGuardian,
  listGuardians,
  removeGuardian,
  resendInvite,
  updateGuardianRole,
} from '../api/guardians';
import { clearHistory, deleteAllData, exportData } from '../api/privacy';
import { clearPin, getPinStatus, setPin } from '../api/security';
import { listSettings, updateSettings, type SettingsBag } from '../api/settings';
import type { Guardian, GuardianRole, GuardianWithInvite } from '../api/types';
import { Icon } from '../components/Icon';
import { DeleteAccountDialog } from '../components/DeleteAccountDialog';
import { PinDialog } from '../components/PinDialog';
import { TwoFactorSection } from '../components/TwoFactorSection';
import { InviteGuardianDialog } from '../components/InviteGuardianDialog';
import { InviteLinkPanel } from '../components/InviteLinkPanel';
import { PageHeader } from '../components/PageHeader';

export function Settings() {
  const queryClient = useQueryClient();
  const settingsQuery = useQuery({ queryKey: ['settings'], queryFn: listSettings });
  const guardiansQuery = useQuery({ queryKey: ['guardians'], queryFn: listGuardians });
  const [inviteOpen, setInviteOpen] = useState(false);

  const mutation = useMutation({
    mutationFn: updateSettings,
    onSuccess: (full) => queryClient.setQueryData(['settings'], full),
  });

  const [deleteOpen, setDeleteOpen] = useState(false);
  const [pinOpen, setPinOpen] = useState(false);

  const pinStatusQuery = useQuery({ queryKey: ['security', 'pin'], queryFn: getPinStatus });
  const hasPin = pinStatusQuery.data?.pinSet ?? false;

  const setPinMutation = useMutation({
    mutationFn: setPin,
    onSuccess: (status) => {
      setPinOpen(false);
      queryClient.setQueryData(['security', 'pin'], status);
    },
  });

  const clearPinMutation = useMutation({
    mutationFn: clearPin,
    onSuccess: (status) => queryClient.setQueryData(['security', 'pin'], status),
  });

  const handleClearPin = () => {
    if (window.confirm('Remover o PIN? O ambiente seguro deixará de poder ser destravado por PIN no aparelho da criança.')) {
      clearPinMutation.mutate();
    }
  };

  const exportMutation = useMutation({
    mutationFn: exportData,
    onSuccess: (data) => {
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `guardkids-export-${new Date().toISOString().slice(0, 10)}.json`;
      a.click();
      URL.revokeObjectURL(url);
    },
  });

  const clearMutation = useMutation({ mutationFn: clearHistory });

  const deleteMutation = useMutation({
    mutationFn: () => deleteAllData('EXCLUIR'),
    onSuccess: () => {
      setDeleteOpen(false);
      queryClient.invalidateQueries();
    },
  });

  const handleClearHistory = () => {
    const ok = window.confirm(
      'Limpar histórico antigo? Eventos e pedidos com mais de 90 dias (e localizações com mais de 30 dias) serão removidos permanentemente.',
    );
    if (ok) clearMutation.mutate();
  };

  const bag: SettingsBag = settingsQuery.data ?? {};
  const get = (key: string, fallback: boolean) => {
    const v = bag[key];
    return typeof v === 'boolean' ? v : fallback;
  };
  const set = (key: string, value: boolean) => mutation.mutate({ [key]: value });

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Configurações"
        subtitle="Notificações, segurança da conta e gestão da família."
      />

      {settingsQuery.error ? <LoadError error={settingsQuery.error} /> : null}

      <Section
        icon="notifications"
        iconTone="primary"
        title="Notificações"
        subtitle="Como você quer ser avisado sobre o que acontece"
      >
        <SettingToggleRow
          settingsKey="notifications.push"
          title="Notificações push"
          description="Recebe alertas no celular sobre pedidos e bloqueios."
          fallback={true}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          locked
          get={get}
          set={set}
        />
        <SettingToggleRow
          settingsKey="notifications.email"
          title="Resumo diário por email"
          description="Email todo dia às 22h com o que aconteceu na família."
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
        <SettingToggleRow
          settingsKey="notifications.realtime"
          title="Alertas em tempo real"
          description="Vibração na hora de cada pedido ou tentativa de site bloqueado."
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          locked
          get={get}
          set={set}
        />
        <SettingToggleRow
          settingsKey="notifications.weekly_report"
          title="Relatório semanal"
          description="Toda segunda às 8h com gráficos da semana anterior."
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
        {mutation.error ? <MutationError error={mutation.error} /> : null}
      </Section>

      <Section
        icon="lock"
        iconTone="secondary"
        title="Segurança"
        subtitle="Proteção da conta e do ambiente das crianças"
      >
        <SettingToggleRow
          settingsKey="security.pin_child"
          title="PIN no painel infantil"
          description="Exige o PIN dos pais pra destravar o ambiente seguro no aparelho da criança (ex.: a tela de bloqueio de horário)."
          fallback={true}
          activeBadge="Ativo"
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
        <PinManageRow
          hasPin={hasPin}
          loading={pinStatusQuery.isLoading}
          clearing={clearPinMutation.isPending}
          onManage={() => setPinOpen(true)}
          onClear={handleClearPin}
        />
        {get('security.pin_child', true) && !hasPin ? (
          <p className="px-1 text-label-sm text-tertiary">
            Defina um PIN pra o desbloqueio funcionar no aparelho da criança.
          </p>
        ) : null}
        <TwoFactorSection />
        <SettingToggleRow
          settingsKey="security.auto_logout"
          title="Logout automático por inatividade"
          description="Desconecta o painel após um tempo parado, por segurança."
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
        {get('security.auto_logout', false) ? (
          <div className="flex items-center justify-between gap-4 rounded-xl border border-outline-variant bg-surface-container-low p-4">
            <label htmlFor="auto-logout-minutes" className="text-body-md text-on-surface">
              Tempo de inatividade
            </label>
            <select
              id="auto-logout-minutes"
              className="rounded-lg border border-outline-variant bg-surface px-3 py-2 text-body-md text-on-surface"
              value={Number(bag['security.auto_logout_minutes']) || 15}
              disabled={mutation.isPending}
              onChange={(e) =>
                mutation.mutate({ 'security.auto_logout_minutes': Number(e.target.value) })
              }
            >
              <option value={5}>5 minutos</option>
              <option value={15}>15 minutos</option>
              <option value={30}>30 minutos</option>
              <option value={60}>60 minutos</option>
            </select>
          </div>
        ) : null}
        <SessionsBlock />
      </Section>

      <Section
        icon="diversity_3"
        iconTone="tertiary"
        title="Família"
        subtitle="Pessoas que podem administrar essa conta"
        action={
          <button
            type="button"
            onClick={() => setInviteOpen(true)}
            className="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-sm transition-colors hover:bg-primary-container"
          >
            <Icon name="person_add" className="text-sm" filled />
            Convidar
          </button>
        }
      >
        <GuardiansList query={guardiansQuery} />
      </Section>

      <Section
        icon="location_on"
        iconTone="primary"
        title="Localização"
        subtitle="Compartilhamento de localização pelos filhos"
      >
        <SettingToggleRow
          settingsKey="location_enabled"
          title="Permitir compartilhamento de localização"
          description="Quando desligado, o app-child para de enviar localização imediatamente. Padrão: desligado."
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
      </Section>

      <Section
        icon="workspace_premium"
        iconTone="primary"
        title="Premium"
        subtitle="Configurações relacionadas à licença e upgrade"
      >
        <UpgradeUrlRow
          currentValue={typeof bag.upgrade_url === 'string' ? bag.upgrade_url : ''}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          onSave={(value) => mutation.mutate({ upgrade_url: value })}
        />
      </Section>

      <Section
        icon="policy"
        iconTone="primary"
        title="Privacidade"
        subtitle="Seu controle sobre os dados da família"
      >
        <ActionRow
          icon="download"
          title="Exportar todos os dados"
          description="Baixa um JSON com tudo: filhos, pedidos, histórico, regras e configurações."
          actionLabel="Solicitar"
          onClick={() => exportMutation.mutate()}
          pending={exportMutation.isPending}
        />
        {exportMutation.error ? <MutationError error={exportMutation.error} /> : null}
        <ActionRow
          icon="cleaning_services"
          title="Limpar histórico"
          description="Remove eventos e pedidos com mais de 90 dias e localizações com mais de 30 dias."
          actionLabel="Limpar"
          tone="warn"
          onClick={handleClearHistory}
          pending={clearMutation.isPending}
        />
        {clearMutation.data ? (
          <p className="text-label-sm text-on-surface-variant">
            Removidos: {clearMutation.data.usage_events} eventos, {clearMutation.data.locations}{' '}
            localizações, {clearMutation.data.requests} pedidos.
          </p>
        ) : null}
        {clearMutation.error ? <MutationError error={clearMutation.error} /> : null}
        <ActionRow
          icon="delete_forever"
          title="Excluir conta e todos os dados"
          description="Apaga os dados da família. Mantém guardiões e licença. Ação irreversível."
          actionLabel="Excluir"
          tone="danger"
          onClick={() => setDeleteOpen(true)}
          pending={deleteMutation.isPending}
        />
      </Section>

      <DeleteAccountDialog
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        onConfirm={() => deleteMutation.mutate()}
        pending={deleteMutation.isPending}
        error={
          deleteMutation.error instanceof ApiError
            ? `${deleteMutation.error.message} (${deleteMutation.error.status})`
            : deleteMutation.error instanceof Error
              ? deleteMutation.error.message
              : null
        }
      />

      <InviteGuardianDialog open={inviteOpen} onClose={() => setInviteOpen(false)} />

      <PinDialog
        open={pinOpen}
        hasPin={hasPin}
        onClose={() => setPinOpen(false)}
        onConfirm={(pin) => setPinMutation.mutate(pin)}
        pending={setPinMutation.isPending}
        error={
          setPinMutation.error instanceof ApiError
            ? `${setPinMutation.error.message} (${setPinMutation.error.status})`
            : setPinMutation.error instanceof Error
              ? setPinMutation.error.message
              : null
        }
      />
    </main>
  );
}

function GuardiansList({
  query,
}: {
  query: ReturnType<typeof useQuery<Guardian[]>>;
}) {
  if (query.isLoading) {
    return (
      <div className="flex items-center justify-center gap-2 rounded-xl border border-outline-variant bg-surface-container-low p-4 text-on-surface-variant">
        <Icon name="progress_activity" className="animate-spin text-sm" />
        <span className="text-label-sm">Carregando guardiões…</span>
      </div>
    );
  }
  if (query.error) {
    return (
      <p role="alert" className="rounded-lg bg-error/10 p-3 text-label-sm text-error">
        Falha ao carregar guardiões.
      </p>
    );
  }
  const items = query.data ?? [];
  if (items.length === 0) {
    return (
      <p className="rounded-xl border border-dashed border-outline-variant bg-surface-container-low p-4 text-label-sm text-on-surface-variant">
        Sem guardiões cadastrados — clique em "Convidar".
      </p>
    );
  }
  return (
    <ul className="space-y-2">
      {items.map((g) => (
        <GuardianRow key={g.id} guardian={g} />
      ))}
    </ul>
  );
}

function UpgradeUrlRow({
  currentValue,
  loading,
  saving,
  onSave,
}: {
  currentValue: string;
  loading: boolean;
  saving: boolean;
  onSave: (value: string) => void;
}) {
  // `key` no input força reset quando o server value muda externamente
  // (ex.: mutation success). Mantém UX simples sem useEffect.
  return (
    <UpgradeUrlForm
      key={currentValue}
      initialValue={currentValue}
      loading={loading}
      saving={saving}
      onSave={onSave}
    />
  );
}

function UpgradeUrlForm({
  initialValue,
  loading,
  saving,
  onSave,
}: {
  initialValue: string;
  loading: boolean;
  saving: boolean;
  onSave: (value: string) => void;
}) {
  const [value, setValue] = useState(initialValue);

  function submit(e: FormEvent) {
    e.preventDefault();
    const trimmed = value.trim();
    if (trimmed === initialValue) return;
    onSave(trimmed);
  }

  const isDirty = value.trim() !== initialValue;

  return (
    <form
      onSubmit={submit}
      className="flex flex-col gap-2 rounded-xl border border-outline-variant bg-surface-container-low p-4"
    >
      <label className="block">
        <span className="text-label-md font-bold text-on-surface">
          Link de upgrade
        </span>
        <p className="mt-0.5 text-label-sm text-on-surface-variant">
          URL que abre quando o usuário clica em "Fazer upgrade" no painel.
          Deixe em branco pra ocultar o botão até você configurar.
        </p>
        <input
          type="url"
          value={value}
          onChange={(e) => setValue(e.target.value)}
          placeholder="https://comprar.exemplo.com/premium"
          disabled={loading}
          aria-label="Link de upgrade"
          className="mt-2 w-full rounded-xl border border-outline-variant bg-surface px-4 py-3 text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
        />
      </label>
      <button
        type="submit"
        disabled={!isDirty || saving || loading}
        className="inline-flex items-center justify-center gap-2 self-end rounded-xl bg-primary px-5 py-2.5 text-label-md font-bold text-white shadow-sm transition-colors hover:bg-primary-container disabled:cursor-not-allowed disabled:opacity-50"
      >
        <Icon
          name={saving ? 'progress_activity' : 'save'}
          className={`text-sm ${saving ? 'animate-spin' : ''}`}
          filled={!saving}
        />
        {saving ? 'Salvando…' : 'Salvar'}
      </button>
    </form>
  );
}

function SettingToggleRow({
  settingsKey,
  title,
  description,
  fallback,
  activeBadge,
  loading,
  saving,
  locked,
  get,
  set,
}: {
  settingsKey: string;
  title: string;
  description: string;
  fallback: boolean;
  activeBadge?: string;
  loading: boolean;
  saving: boolean;
  locked?: boolean;
  get: (key: string, fallback: boolean) => boolean;
  set: (key: string, value: boolean) => void;
}) {
  const value = get(settingsKey, fallback);
  const disabled = loading || saving || locked === true;
  return (
    <div className="flex items-start justify-between gap-4 rounded-xl border border-outline-variant bg-surface-container-low p-4">
      <div className="flex-1">
        <div className="flex items-center gap-2">
          <h4 className="text-label-md font-bold text-on-surface">{title}</h4>
          {activeBadge && value ? (
            <span className="rounded-full bg-secondary-container/40 px-2 py-0.5 text-label-sm font-semibold text-secondary">
              {activeBadge}
            </span>
          ) : null}
        </div>
        <p className="mt-0.5 text-label-sm text-on-surface-variant">{description}</p>
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={value}
        disabled={disabled}
        onClick={() => set(settingsKey, !value)}
        className={`relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors disabled:opacity-60 ${
          value ? 'bg-primary' : 'bg-outline-variant'
        }`}
      >
        <span
          className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform ${
            value ? 'translate-x-6' : 'translate-x-1'
          }`}
        />
      </button>
    </div>
  );
}

function SessionsBlock() {
  return (
    <div className="rounded-xl border border-primary/30 bg-primary/5 p-4">
      <div className="mb-2 flex items-center gap-2">
        <Icon name="devices" className="text-primary" />
        <h4 className="font-display text-label-md font-bold text-on-surface">
          Sessões ativas
        </h4>
        <ComingSoonBadge />
      </div>
      <p className="mb-3 text-label-sm text-on-surface-variant">
        Auditoria de dispositivos entra junto com tabela de sessões na próxima migration.
      </p>
      <div className="flex flex-col items-center gap-2 rounded-lg bg-white py-6 text-center text-on-surface-variant">
        <Icon name="devices_other" className="text-3xl" />
        <p className="text-label-sm">
          Sem dispositivos pra mostrar ainda. Quando a tabela de sessões existir, os
          aparelhos logados nesta conta vão aparecer aqui.
        </p>
      </div>
    </div>
  );
}

function GuardianRow({ guardian }: { guardian: Guardian }) {
  const queryClient = useQueryClient();
  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['guardians'] });
  const [resent, setResent] = useState<GuardianWithInvite | null>(null);

  const roleMutation = useMutation({
    mutationFn: (role: GuardianRole) => updateGuardianRole(guardian.id, role),
    onSuccess: invalidate,
  });
  const activateMutation = useMutation({
    mutationFn: () => activateGuardian(guardian.id),
    onSuccess: invalidate,
  });
  const removeMutation = useMutation({
    mutationFn: () => removeGuardian(guardian.id),
    onSuccess: invalidate,
  });
  const resendMutation = useMutation({
    mutationFn: () => resendInvite(guardian.id),
    onSuccess: (data) => {
      setResent(data);
      invalidate();
    },
  });

  const errorOf = (m: { error: unknown }): string | null => {
    if (m.error instanceof ApiError) return `${m.error.message} (${m.error.status})`;
    if (m.error instanceof Error) return m.error.message;
    return null;
  };
  const error =
    errorOf(roleMutation) ??
    errorOf(activateMutation) ??
    errorOf(removeMutation) ??
    errorOf(resendMutation);

  const toggleRole = () => {
    const next: GuardianRole = guardian.role === 'admin' ? 'collaborator' : 'admin';
    const label = next === 'admin' ? 'promover a administrador' : 'rebaixar a colaborador';
    if (window.confirm(`Deseja ${label} ${guardian.name}?`)) {
      roleMutation.mutate(next);
    }
  };

  const remove = () => {
    if (window.confirm(`Remover ${guardian.name} da família? Essa ação não pode ser desfeita.`)) {
      removeMutation.mutate();
    }
  };

  const busy =
    roleMutation.isPending ||
    activateMutation.isPending ||
    removeMutation.isPending ||
    resendMutation.isPending;
  const pending = guardian.status === 'pending';

  return (
    <li className="flex flex-col gap-2 rounded-xl border border-outline-variant bg-surface-container-low p-3 sm:flex-row sm:items-center">
      <div className="flex flex-1 items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-full bg-primary-container text-on-primary-container font-display text-headline-md font-bold">
          {guardian.name.charAt(0).toUpperCase()}
        </div>
        <div className="flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-label-md font-semibold text-on-surface">{guardian.name}</span>
            <span
              className={`rounded-full px-2 py-0.5 text-label-sm font-semibold ${
                guardian.role === 'admin'
                  ? 'bg-primary text-white'
                  : 'bg-surface-container-high text-on-surface'
              }`}
            >
              {guardian.role === 'admin' ? 'Admin' : 'Colaborador'}
            </span>
            {pending && (
              <span className="rounded-full bg-orange-warm/15 px-2 py-0.5 text-label-sm font-semibold text-orange-warm">
                Pendente
              </span>
            )}
          </div>
          <div className="text-label-sm text-on-surface-variant">{guardian.email}</div>
          {error && (
            <p role="alert" className="mt-1 text-label-sm text-error">
              {error}
            </p>
          )}
          {resent && (
            <div className="mt-2">
              <InviteLinkPanel
                url={resent.inviteUrl}
                message="Novo link gerado (o anterior expirou). Compartilhe com o guardião:"
              />
            </div>
          )}
        </div>
      </div>
      <div className="flex items-center gap-1">
        {pending && (
          <>
            <button
              type="button"
              onClick={() => resendMutation.mutate()}
              disabled={busy}
              aria-label={`Reenviar convite para ${guardian.name}`}
              title="Reenviar convite"
              className="rounded-full p-2 text-primary transition-colors hover:bg-primary-container/40 disabled:opacity-50"
            >
              <Icon
                name={resendMutation.isPending ? 'progress_activity' : 'send'}
                className={`text-base ${resendMutation.isPending ? 'animate-spin' : ''}`}
              />
            </button>
            <button
              type="button"
              onClick={() => activateMutation.mutate()}
              disabled={busy}
              aria-label={`Ativar ${guardian.name}`}
              title="Ativar guardião"
              className="rounded-full p-2 text-secondary transition-colors hover:bg-secondary-container/40 disabled:opacity-50"
            >
              <Icon
                name={activateMutation.isPending ? 'progress_activity' : 'check'}
                className={`text-base ${activateMutation.isPending ? 'animate-spin' : ''}`}
              />
            </button>
          </>
        )}
        <button
          type="button"
          onClick={toggleRole}
          disabled={busy}
          aria-label={
            guardian.role === 'admin'
              ? `Rebaixar ${guardian.name} para colaborador`
              : `Promover ${guardian.name} a administrador`
          }
          title={guardian.role === 'admin' ? 'Rebaixar' : 'Promover'}
          className="rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-variant/60 hover:text-primary disabled:opacity-50"
        >
          <Icon
            name={
              roleMutation.isPending
                ? 'progress_activity'
                : guardian.role === 'admin'
                  ? 'arrow_downward'
                  : 'arrow_upward'
            }
            className={`text-base ${roleMutation.isPending ? 'animate-spin' : ''}`}
          />
        </button>
        <button
          type="button"
          onClick={remove}
          disabled={busy}
          aria-label={`Remover ${guardian.name}`}
          title="Remover"
          className="rounded-full p-2 text-error transition-colors hover:bg-error/10 disabled:opacity-50"
        >
          <Icon
            name={removeMutation.isPending ? 'progress_activity' : 'delete'}
            className={`text-base ${removeMutation.isPending ? 'animate-spin' : ''}`}
          />
        </button>
      </div>
    </li>
  );
}

function Section({
  icon,
  iconTone,
  title,
  subtitle,
  comingSoon,
  action,
  children,
}: {
  icon: string;
  iconTone: 'primary' | 'secondary' | 'tertiary';
  title: string;
  subtitle: string;
  comingSoon?: boolean;
  action?: ReactNode;
  children: ReactNode;
}) {
  const toneMap = {
    primary: 'bg-primary-container text-on-primary-container',
    secondary: 'bg-secondary-container/60 text-secondary',
    tertiary: 'bg-tertiary-fixed text-on-tertiary-fixed-variant',
  };
  return (
    <section className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-start justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className={`flex h-11 w-11 items-center justify-center rounded-xl ${toneMap[iconTone]}`}>
            <Icon name={icon} className="text-2xl" filled />
          </div>
          <div>
            <h3 className="flex items-center gap-2 font-display text-headline-md text-on-surface">
              {title}
              {comingSoon ? <ComingSoonBadge /> : null}
            </h3>
            <p className="text-label-sm text-on-surface-variant">{subtitle}</p>
          </div>
        </div>
        {action}
      </header>
      <div className="space-y-3">{children}</div>
    </section>
  );
}

function ComingSoonBadge() {
  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-tertiary-container/40 px-2 py-0.5 text-xs font-semibold text-tertiary-container">
      <Icon name="hourglass_empty" className="text-xs" />
      Em breve
    </span>
  );
}

function PinManageRow({
  hasPin,
  loading,
  clearing,
  onManage,
  onClear,
}: {
  hasPin: boolean;
  loading: boolean;
  clearing: boolean;
  onManage: () => void;
  onClear: () => void;
}) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-xl border border-outline-variant bg-surface-container-low p-4">
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-surface-container-high text-primary">
          <Icon name="pin" className="text-xl" filled />
        </div>
        <div>
          <h4 className="text-label-md font-bold text-on-surface">PIN de desbloqueio</h4>
          <p className="mt-0.5 text-label-sm text-on-surface-variant">
            {loading ? 'Carregando…' : hasPin ? 'PIN configurado.' : 'Nenhum PIN definido ainda.'}
          </p>
        </div>
      </div>
      <div className="flex shrink-0 items-center gap-2">
        {hasPin ? (
          <button
            type="button"
            onClick={onClear}
            disabled={clearing}
            className="rounded-lg border border-error/40 bg-error/10 px-3 py-2 text-label-md font-semibold text-error hover:bg-error/20 disabled:opacity-60"
          >
            {clearing ? '…' : 'Remover'}
          </button>
        ) : null}
        <button
          type="button"
          onClick={onManage}
          disabled={loading}
          className="rounded-lg border border-outline-variant bg-surface-container px-4 py-2 text-label-md font-semibold text-on-surface hover:bg-surface-variant disabled:opacity-60"
        >
          {hasPin ? 'Trocar' : 'Definir PIN'}
        </button>
      </div>
    </div>
  );
}

function ActionRow({
  icon,
  title,
  description,
  actionLabel,
  tone,
  onClick,
  pending,
}: {
  icon: string;
  title: string;
  description: string;
  actionLabel: string;
  tone?: 'warn' | 'danger';
  onClick?: () => void;
  pending?: boolean;
}) {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-outline-variant bg-surface-container-low p-4">
      <div
        className={`flex h-10 w-10 items-center justify-center rounded-xl ${
          tone === 'danger'
            ? 'bg-error-container/60 text-error'
            : tone === 'warn'
              ? 'bg-tertiary-fixed-dim text-on-tertiary-fixed-variant'
              : 'bg-surface-container-high text-primary'
        }`}
      >
        <Icon name={icon} className="text-xl" filled />
      </div>
      <div className="flex-1">
        <h4 className="text-label-md font-bold text-on-surface">{title}</h4>
        <p className="mt-0.5 text-label-sm text-on-surface-variant">{description}</p>
      </div>
      <button
        type="button"
        onClick={onClick}
        disabled={pending}
        className={`shrink-0 rounded-lg border px-4 py-2 text-label-md font-semibold disabled:opacity-60 ${
          tone === 'danger'
            ? 'border-error/40 bg-error/10 text-error hover:bg-error/20'
            : 'border-outline-variant bg-surface-container text-on-surface hover:bg-surface-variant'
        }`}
      >
        {pending ? '…' : actionLabel}
      </button>
    </div>
  );
}

function LoadError({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar configurações</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}

function MutationError({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'erro desconhecido';
  return (
    <p role="alert" className="rounded-lg bg-error/10 p-2 text-label-sm text-error">
      Falha ao salvar: {message}
    </p>
  );
}
