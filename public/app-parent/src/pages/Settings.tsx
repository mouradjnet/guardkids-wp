import { useState } from 'react';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { guardians, type Guardian } from '../data/mockData';

export function Settings() {
  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Configurações"
        subtitle="Notificações, segurança da conta e gestão da família."
      />

      <NotificationsCard />
      <SecurityCard />
      <FamilyCard />
      <PrivacyCard />
    </main>
  );
}

function NotificationsCard() {
  const [push, setPush] = useState(true);
  const [email, setEmail] = useState(true);
  const [realtime, setRealtime] = useState(false);
  const [weeklyReport, setWeeklyReport] = useState(true);

  return (
    <Section
      icon="notifications"
      iconTone="primary"
      title="Notificações"
      subtitle="Como você quer ser avisado sobre o que acontece"
    >
      <ToggleRow
        title="Notificações push"
        description="Recebe alertas no celular sobre pedidos e bloqueios."
        value={push}
        onChange={() => setPush((v) => !v)}
      />
      <ToggleRow
        title="Resumo diário por email"
        description="Email todo dia às 22h com o que aconteceu na família."
        value={email}
        onChange={() => setEmail((v) => !v)}
      />
      <ToggleRow
        title="Alertas em tempo real"
        description="Vibração na hora de cada pedido ou tentativa de site bloqueado."
        value={realtime}
        onChange={() => setRealtime((v) => !v)}
      />
      <ToggleRow
        title="Relatório semanal"
        description="Toda segunda às 8h com gráficos da semana anterior."
        value={weeklyReport}
        onChange={() => setWeeklyReport((v) => !v)}
      />
    </Section>
  );
}

function SecurityCard() {
  const [twoFA, setTwoFA] = useState(true);
  const [pinChild, setPinChild] = useState(true);
  const [autoLogout, setAutoLogout] = useState(false);

  return (
    <Section
      icon="lock"
      iconTone="secondary"
      title="Segurança"
      subtitle="Proteção da conta e do ambiente das crianças"
    >
      <ToggleRow
        title="Autenticação em 2 fatores (2FA)"
        description="Pede código no celular além da senha ao logar."
        value={twoFA}
        onChange={() => setTwoFA((v) => !v)}
        badge={twoFA ? { tone: 'mint', label: 'Ativo' } : undefined}
      />
      <ToggleRow
        title="PIN no painel infantil"
        description="A criança precisa de PIN pra trocar de perfil ou sair do ambiente seguro."
        value={pinChild}
        onChange={() => setPinChild((v) => !v)}
      />
      <ToggleRow
        title="Logout automático em 7 dias"
        description="Por segurança, força login novo depois de 7 dias sem usar."
        value={autoLogout}
        onChange={() => setAutoLogout((v) => !v)}
      />
      <div className="rounded-xl border border-primary/30 bg-primary/5 p-4">
        <div className="mb-2 flex items-center gap-2">
          <Icon name="devices" className="text-primary" />
          <h4 className="font-display text-label-md font-bold text-on-surface">
            Sessões ativas
          </h4>
        </div>
        <p className="mb-3 text-label-sm text-on-surface-variant">
          2 dispositivos conectados nessa conta agora.
        </p>
        <div className="space-y-2">
          <SessionRow label="MacBook Pro — Sala" detail="Você • IP 191.0.0.12 • agora" current />
          <SessionRow label="iPhone 14 — Quarto" detail="Você • IP 192.0.0.45 • há 2h" />
        </div>
      </div>
    </Section>
  );
}

function FamilyCard() {
  return (
    <Section
      icon="diversity_3"
      iconTone="tertiary"
      title="Família"
      subtitle="Pessoas que podem administrar essa conta"
      action={
        <button
          type="button"
          className="inline-flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-sm hover:bg-primary-container"
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
  );
}

function PrivacyCard() {
  return (
    <Section
      icon="policy"
      iconTone="primary"
      title="Privacidade"
      subtitle="Seu controle sobre os dados da família"
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
  );
}

function Section({
  icon,
  iconTone,
  title,
  subtitle,
  action,
  children,
}: {
  icon: string;
  iconTone: 'primary' | 'secondary' | 'tertiary';
  title: string;
  subtitle: string;
  action?: React.ReactNode;
  children: React.ReactNode;
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
            <h3 className="font-display text-headline-md text-on-surface">{title}</h3>
            <p className="text-label-sm text-on-surface-variant">{subtitle}</p>
          </div>
        </div>
        {action}
      </header>
      <div className="space-y-3">{children}</div>
    </section>
  );
}

function ToggleRow({
  title,
  description,
  value,
  onChange,
  badge,
}: {
  title: string;
  description: string;
  value: boolean;
  onChange: () => void;
  badge?: { tone: 'mint'; label: string };
}) {
  return (
    <div className="flex items-start justify-between gap-4 rounded-xl border border-outline-variant bg-surface-container-low p-4">
      <div className="flex-1">
        <div className="flex items-center gap-2">
          <h4 className="text-label-md font-bold text-on-surface">{title}</h4>
          {badge && (
            <span className="rounded-full bg-secondary-container/40 px-2 py-0.5 text-label-sm font-semibold text-secondary">
              {badge.label}
            </span>
          )}
        </div>
        <p className="mt-0.5 text-label-sm text-on-surface-variant">{description}</p>
      </div>
      <button
        type="button"
        role="switch"
        aria-checked={value}
        onClick={onChange}
        className={`relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors ${
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
          className="text-label-md font-semibold text-error hover:underline"
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
      {guardian.role !== 'admin' && (
        <button
          type="button"
          aria-label="Mais ações"
          className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50"
        >
          <Icon name="more_vert" />
        </button>
      )}
    </li>
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
  const buttonClass =
    tone === 'danger'
      ? 'bg-error text-white hover:bg-error/90'
      : tone === 'warn'
        ? 'bg-tertiary-fixed-dim text-on-tertiary-fixed-variant hover:bg-tertiary-fixed'
        : 'border border-outline-variant bg-surface-container text-on-surface hover:bg-surface-variant';
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
        className={`shrink-0 rounded-lg px-4 py-2 text-label-md font-semibold transition-colors ${buttonClass}`}
      >
        {actionLabel}
      </button>
    </div>
  );
}
