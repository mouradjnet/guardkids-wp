import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { activateLicense, deactivateLicense, type LicenseStatus } from '../api/license';
import { ApiError } from '../api/client';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { useLicense } from '../hooks/useLicense';

export function License() {
  const license = useLicense();
  const queryClient = useQueryClient();
  const [keyInput, setKeyInput] = useState('');

  const activate = useMutation({
    mutationFn: (key: string) => activateLicense(key),
    onSuccess: (snapshot) => {
      queryClient.setQueryData(['license'], snapshot);
      setKeyInput('');
    },
  });

  const deactivate = useMutation({
    mutationFn: () => deactivateLicense(),
    onSuccess: (snapshot) => {
      queryClient.setQueryData(['license'], snapshot);
    },
  });

  function submit(e: FormEvent) {
    e.preventDefault();
    // Tira QUALQUER whitespace (incluindo newlines internas). Sem isso o WP
    // `sanitize_text_field` converte quebras em espaço, destruindo o base64url
    // e fazendo a verificação Ed25519 falhar — bug visto em smoke 2026-06-09.
    const key = keyInput.replace(/\s+/g, '');
    if (key === '') return;
    activate.mutate(key);
  }

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Licença"
        subtitle="Gerencie sua licença premium do GuardKids."
      />

      {license.isLoading ? (
        <section className="glass-panel flex items-center justify-center rounded-2xl p-10 text-on-surface-variant shadow-ambient">
          <Icon name="progress_activity" className="animate-spin text-2xl" />
        </section>
      ) : (
        <StatusHero license={license} />
      )}

      {license.snapshot !== null && license.status !== 'none' && (
        <DetailsCard
          email={license.email}
          activatedAt={license.activatedAt}
          expiresAt={license.expiresAt}
          features={license.features}
        />
      )}

      <ActivateCard
        value={keyInput}
        onChange={setKeyInput}
        onSubmit={submit}
        submitting={activate.isPending}
        error={activate.error}
      />

      {license.status === 'active' || license.status === 'expired' ? (
        <DeactivateCard
          onDeactivate={() => deactivate.mutate()}
          deactivating={deactivate.isPending}
        />
      ) : null}
    </main>
  );
}

function StatusHero({ license }: { license: ReturnType<typeof useLicense> }) {
  const { status, daysLeft, email } = license;

  const visual = visualFor(status);
  const pct = daysLeft !== null && license.expiresAt
    ? Math.max(0, Math.min(100, Math.round((daysLeft / 365) * 100)))
    : 0;

  return (
    <section
      data-testid="license-hero"
      data-status={status}
      className={`relative overflow-hidden rounded-2xl ${visual.bg} p-8 text-white shadow-ambient md:p-10`}
    >
      <div className="pointer-events-none absolute -right-10 -top-10 opacity-10">
        <span className="material-symbols-outlined" style={{ fontSize: 220 }}>
          {visual.icon}
        </span>
      </div>
      <div className="relative z-10 flex flex-col items-start gap-5 md:flex-row md:items-center md:justify-between">
        <div className="max-w-xl space-y-3">
          <span className="inline-flex items-center gap-1 rounded-full bg-white/15 px-3 py-1 text-label-sm font-bold text-white">
            <Icon name={visual.badgeIcon} className="text-sm" filled />
            {visual.badge}
          </span>
          <h2 className="font-display text-headline-lg text-white">{visual.title}</h2>
          <p className="text-body-md text-white/85">{visual.subtitle(license)}</p>
          {email && (
            <p className="text-label-sm text-white/70">Cliente: {email}</p>
          )}

          {status === 'active' && daysLeft !== null && (
            <div className="space-y-2 pt-2">
              <div className="flex items-center justify-between text-label-sm">
                <span className="text-white/70">Tempo até renovar</span>
                <span className="font-semibold text-white">{daysLeft} dias</span>
              </div>
              <div className="h-2 w-full overflow-hidden rounded-full bg-white/15">
                <div
                  className="h-full rounded-full bg-white"
                  style={{ width: `${pct}%` }}
                />
              </div>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}

type Visual = {
  bg: string;
  icon: string;
  badge: string;
  badgeIcon: string;
  title: string;
  subtitle: (license: ReturnType<typeof useLicense>) => string;
};

function visualFor(status: LicenseStatus): Visual {
  switch (status) {
    case 'active':
      return {
        bg: 'bg-gradient-to-br from-primary via-primary-container to-primary',
        icon: 'workspace_premium',
        badge: 'Licença ativa',
        badgeIcon: 'verified',
        title: 'GuardKids Premium',
        subtitle: (l) =>
          l.expiresAt
            ? `Expira em ${formatDate(l.expiresAt)}.`
            : 'Premium ativo nesta instalação.',
      };
    case 'expired':
      return {
        bg: 'bg-gradient-to-br from-orange-warm via-orange-warm to-orange-warm/80',
        icon: 'schedule',
        badge: 'Licença expirada',
        badgeIcon: 'warning',
        title: 'Sua licença Premium expirou',
        subtitle: (l) =>
          l.expiresAt
            ? `Expirou em ${formatDate(l.expiresAt)}. Você ainda vê seus dados antigos, mas features premium estão bloqueadas.`
            : 'Você ainda vê seus dados antigos, mas features premium estão bloqueadas.',
      };
    case 'domain_mismatch':
      return {
        bg: 'bg-gradient-to-br from-error to-error/80',
        icon: 'domain_disabled',
        badge: 'Domínio diferente',
        badgeIcon: 'error',
        title: 'Esta chave é de outro domínio',
        subtitle: () =>
          'A licença foi emitida pra um domínio diferente desta instalação. Solicite uma chave nova ou desative no domínio anterior.',
      };
    case 'revoked':
      return {
        bg: 'bg-gradient-to-br from-error to-error/80',
        icon: 'gpp_bad',
        badge: 'Chave revogada',
        badgeIcon: 'error',
        title: 'Esta chave foi revogada',
        subtitle: () => 'Entre em contato com o suporte para regularizar.',
      };
    case 'none':
    default:
      return {
        bg: 'bg-gradient-to-br from-surface-container-highest via-surface-container-high to-surface-container-highest text-on-surface',
        icon: 'lock_open',
        badge: 'Plano Free',
        badgeIcon: 'shield',
        title: 'Você está no Plano Free',
        subtitle: () =>
          'Cole sua chave de licença abaixo pra desbloquear as features premium.',
      };
  }
}

function DetailsCard({
  email,
  activatedAt,
  expiresAt,
  features,
}: {
  email: string | null;
  activatedAt: string | null;
  expiresAt: string | null;
  features: string[];
}) {
  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
          <Icon name="description" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-headline-md text-on-surface">
            Detalhes da licença
          </h3>
          <p className="text-label-sm text-on-surface-variant">
            Dados extraídos da chave assinada
          </p>
        </div>
      </header>

      <ul className="space-y-2 text-label-md text-on-surface">
        {email && <DetailRow icon="alternate_email" label="E-mail" value={email} />}
        {activatedAt && (
          <DetailRow icon="event_available" label="Ativada em" value={activatedAt} />
        )}
        {expiresAt && (
          <DetailRow icon="event_busy" label="Expira em" value={formatDate(expiresAt)} />
        )}
      </ul>

      {features.length > 0 && (
        <div className="mt-4">
          <p className="mb-2 text-label-sm font-semibold text-on-surface-variant">
            Features liberadas
          </p>
          <div className="flex flex-wrap gap-2">
            {features.map((f) => (
              <span
                key={f}
                className="inline-flex items-center gap-1 rounded-full bg-primary-container px-3 py-1 text-label-sm font-semibold text-on-primary-container"
              >
                <Icon name="check_circle" className="text-sm" filled />
                {f}
              </span>
            ))}
          </div>
        </div>
      )}
    </article>
  );
}

function ActivateCard({
  value,
  onChange,
  onSubmit,
  submitting,
  error,
}: {
  value: string;
  onChange: (v: string) => void;
  onSubmit: (e: FormEvent) => void;
  submitting: boolean;
  error: unknown;
}) {
  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-secondary-container/60 text-secondary">
          <Icon name="add_circle" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-headline-md text-on-surface">
            Ativar nova chave
          </h3>
          <p className="text-label-sm text-on-surface-variant">
            Cole a chave que você recebeu por e-mail
          </p>
        </div>
      </header>

      <form onSubmit={onSubmit}>
        <label className="block">
          <span className="text-label-sm text-on-surface-variant">
            Chave de licença
          </span>
          <textarea
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder="cole sua chave aqui"
            rows={3}
            spellCheck={false}
            autoComplete="off"
            className="mt-1 w-full resize-none rounded-xl border border-outline-variant bg-surface-container-low px-4 py-3 font-mono text-label-sm text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
          />
        </label>

        {error !== null && error !== undefined && (
          <p role="alert" className="mt-3 rounded-lg bg-error/10 p-3 text-label-sm text-error">
            {errorMessage(error)}
          </p>
        )}

        <button
          type="submit"
          disabled={submitting || value.trim() === ''}
          className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-label-md font-bold text-white shadow-sm transition-colors hover:bg-primary-container disabled:cursor-not-allowed disabled:opacity-50"
        >
          <Icon
            name={submitting ? 'progress_activity' : 'bolt'}
            className={`text-sm ${submitting ? 'animate-spin' : ''}`}
            filled={!submitting}
          />
          {submitting ? 'Validando…' : 'Ativar licença'}
        </button>
      </form>
    </article>
  );
}

function DeactivateCard({
  onDeactivate,
  deactivating,
}: {
  onDeactivate: () => void;
  deactivating: boolean;
}) {
  function confirmAndDeactivate() {
    if (
      typeof window !== 'undefined' &&
      window.confirm(
        'Tem certeza? A licença será removida deste domínio. Você pode reativar a mesma chave em outro WordPress.',
      )
    ) {
      onDeactivate();
    }
  }

  return (
    <article className="glass-panel flex flex-col gap-3 rounded-2xl border border-error/30 bg-error/5 p-6">
      <header className="flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-error-container/60 text-error">
          <Icon name="link_off" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-headline-md text-on-surface">
            Desativar licença
          </h3>
          <p className="text-label-sm text-on-surface-variant">
            Libera a chave pra ser usada em outro domínio
          </p>
        </div>
      </header>

      <button
        type="button"
        onClick={confirmAndDeactivate}
        disabled={deactivating}
        className="inline-flex items-center justify-center gap-2 self-start rounded-xl border border-error/40 bg-error/10 px-4 py-2 text-label-md font-bold text-error transition-colors hover:bg-error/20 disabled:opacity-50"
      >
        <Icon
          name={deactivating ? 'progress_activity' : 'link_off'}
          className={`text-sm ${deactivating ? 'animate-spin' : ''}`}
        />
        {deactivating ? 'Desativando…' : 'Desativar nesta instalação'}
      </button>
    </article>
  );
}

function DetailRow({
  icon,
  label,
  value,
}: {
  icon: string;
  label: string;
  value: string;
}) {
  return (
    <li className="flex items-center gap-3 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2">
      <Icon name={icon} className="text-on-surface-variant" />
      <span className="flex-1 text-label-sm text-on-surface-variant">{label}</span>
      <span className="font-semibold text-on-surface">{value}</span>
    </li>
  );
}

function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.message;
  }
  if (error instanceof Error) {
    return error.message;
  }
  return 'Erro ao ativar a licença.';
}

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleDateString('pt-BR');
  } catch {
    return iso;
  }
}
