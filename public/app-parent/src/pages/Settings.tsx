import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent, type ReactNode } from 'react';
import { ApiError } from '../api/client';
import { listSettings, updateSettings, type SettingsBag } from '../api/settings';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { guardians, type Guardian } from '../data/mockData';

export function Settings() {
  const queryClient = useQueryClient();
  const settingsQuery = useQuery({ queryKey: ['settings'], queryFn: listSettings });

  const mutation = useMutation({
    mutationFn: updateSettings,
    onSuccess: (full) => queryClient.setQueryData(['settings'], full),
  });

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
          get={get}
          set={set}
        />
        <SettingToggleRow
          settingsKey="notifications.email"
          title="Resumo diário por email"
          description="Email todo dia às 22h com o que aconteceu na família."
          fallback={true}
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
          get={get}
          set={set}
        />
        <SettingToggleRow
          settingsKey="notifications.weekly_report"
          title="Relatório semanal"
          description="Toda segunda às 8h com gráficos da semana anterior."
          fallback={true}
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
          settingsKey="security.two_fa"
          title="Autenticação em 2 fatores (2FA)"
          description="Pede código no celular além da senha ao logar."
          fallback={false}
          activeBadge="Ativo"
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
        <SettingToggleRow
          settingsKey="security.pin_child"
          title="PIN no painel infantil"
          description="A criança precisa de PIN pra trocar de perfil ou sair do ambiente seguro."
          fallback={true}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
        <SettingToggleRow
          settingsKey="security.auto_logout"
          title="Logout automático em 7 dias"
          description="Por segurança, força login novo depois de 7 dias sem usar."
          fallback={false}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          get={get}
          set={set}
        />
        <SessionsBlock />
      </Section>

      <Section
        icon="diversity_3"
        iconTone="tertiary"
        title="Família"
        subtitle="Pessoas que podem administrar essa conta"
        comingSoon
        action={
          <button
            type="button"
            disabled
            className="inline-flex items-center gap-2 rounded-full bg-primary/40 px-4 py-2 text-label-md font-semibold text-white shadow-sm opacity-60"
          >
            <Icon name="person_add" className="text-sm" filled />
            Convidar
          </button>
        }
      >
        <ul className="space-y-2">
          {guardians.map((g) => (
            <GuardianRow key={g.id} guardian={g} />
          ))}
        </ul>
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
          currentValue={typeof bag.guardkids_upgrade_url === 'string' ? bag.guardkids_upgrade_url : ''}
          loading={settingsQuery.isLoading}
          saving={mutation.isPending}
          onSave={(value) => mutation.mutate({ guardkids_upgrade_url: value })}
        />
      </Section>

      <Section
        icon="policy"
        iconTone="primary"
        title="Privacidade"
        subtitle="Seu controle sobre os dados da família"
        comingSoon
      >
        <ActionRow
          icon="download"
          title="Exportar todos os dados"
          description="ZIP com tudo: filhos, pedidos, histórico, regras e configurações."
          actionLabel="Solicitar"
        />
        <ActionRow
          icon="cleaning_services"
          title="Limpar histórico"
          description="Remove relatórios, bloqueios e pedidos anteriores a 90 dias."
          actionLabel="Limpar"
          tone="warn"
        />
        <ActionRow
          icon="delete_forever"
          title="Excluir conta e todos os dados"
          description="Ação irreversível. Pede confirmação dupla."
          actionLabel="Excluir"
          tone="danger"
        />
      </Section>
    </main>
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
  get: (key: string, fallback: boolean) => boolean;
  set: (key: string, value: boolean) => void;
}) {
  const value = get(settingsKey, fallback);
  const disabled = loading || saving;
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
      <div className="space-y-2">
        <SessionRow label="MacBook Pro — Sala" detail="Você • IP 191.0.0.12 • agora" current />
        <SessionRow label="iPhone 14 — Quarto" detail="Você • IP 192.0.0.45 • há 2h" />
      </div>
    </div>
  );
}

function SessionRow({
  label,
  detail,
  current,
}: {
  label: string;
  detail: string;
  current?: boolean;
}) {
  return (
    <div className="flex items-center gap-3 rounded-lg bg-white p-3">
      <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-surface-container-high text-primary">
        <Icon name={label.includes('iPhone') ? 'smartphone' : 'computer'} />
      </div>
      <div className="flex-1">
        <div className="flex items-center gap-2 text-label-md font-semibold text-on-surface">
          {label}
          {current && (
            <span className="rounded-full bg-secondary-container/40 px-2 py-0.5 text-label-sm font-semibold text-secondary">
              Este dispositivo
            </span>
          )}
        </div>
        <div className="text-label-sm text-on-surface-variant">{detail}</div>
      </div>
      {!current && (
        <button
          type="button"
          disabled
          className="text-label-md font-semibold text-error/40"
        >
          Encerrar
        </button>
      )}
    </div>
  );
}

function GuardianRow({ guardian }: { guardian: Guardian }) {
  return (
    <li className="flex items-center gap-3 rounded-xl border border-outline-variant bg-surface-container-low p-3">
      <div className="flex h-11 w-11 items-center justify-center rounded-full bg-primary-container text-on-primary-container font-display text-headline-md font-bold">
        {guardian.name.charAt(0)}
      </div>
      <div className="flex-1">
        <div className="flex items-center gap-2">
          <span className="text-label-md font-semibold text-on-surface">{guardian.name}</span>
          {guardian.role === 'admin' && (
            <span className="rounded-full bg-primary px-2 py-0.5 text-label-sm font-semibold text-white">
              Admin
            </span>
          )}
          {guardian.pendingInvite && (
            <span className="rounded-full bg-orange-warm/15 px-2 py-0.5 text-label-sm font-semibold text-orange-warm">
              Convite pendente
            </span>
          )}
        </div>
        <div className="text-label-sm text-on-surface-variant">{guardian.email}</div>
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

function ActionRow({
  icon,
  title,
  description,
  actionLabel,
  tone,
}: {
  icon: string;
  title: string;
  description: string;
  actionLabel: string;
  tone?: 'warn' | 'danger';
}) {
  return (
    <div className="flex items-start gap-3 rounded-xl border border-outline-variant bg-surface-container-low p-4 opacity-60">
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
        disabled
        className="shrink-0 rounded-lg border border-outline-variant bg-surface-container px-4 py-2 text-label-md font-semibold text-on-surface-variant"
      >
        {actionLabel}
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
