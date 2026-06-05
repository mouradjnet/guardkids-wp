import { useState } from 'react';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { licenseInfo } from '../data/mockData';

export function License() {
  const [showKey, setShowKey] = useState(false);
  const [newKey, setNewKey] = useState('');

  const pct = Math.round((1 - licenseInfo.daysLeft / 365) * 100);

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Licença"
        subtitle="Gerencie sua licença premium, domínio ativado e renovações."
      />

      <section className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary via-primary-container to-primary p-8 text-white shadow-ambient md:p-10">
        <div className="pointer-events-none absolute -right-10 -top-10 opacity-10">
          <span className="material-symbols-outlined" style={{ fontSize: 220 }}>
            workspace_premium
          </span>
        </div>
        <div className="relative z-10 flex flex-col items-start gap-5 md:flex-row md:items-center md:justify-between">
          <div className="max-w-xl space-y-3">
            <span className="inline-flex items-center gap-1 rounded-full bg-secondary-container/30 px-3 py-1 text-label-sm font-bold text-secondary-fixed">
              <Icon name="verified" className="text-sm" filled />
              Licença ativa
            </span>
            <h2 className="font-display text-headline-lg text-white">
              GuardKids {licenseInfo.plan}
            </h2>
            <p className="text-body-md text-white/85">
              Ativado em {licenseInfo.activatedAt}, expira em {licenseInfo.expiresAt}.
            </p>

            <div className="space-y-2 pt-2">
              <div className="flex items-center justify-between text-label-sm">
                <span className="text-white/70">Tempo até renovar</span>
                <span className="font-semibold text-white">{licenseInfo.daysLeft} dias</span>
              </div>
              <div className="h-2 w-full overflow-hidden rounded-full bg-white/15">
                <div
                  className="h-full rounded-full bg-secondary-fixed-dim"
                  style={{ width: `${pct}%` }}
                />
              </div>
            </div>
          </div>

          <div className="flex flex-col gap-3 md:items-end">
            <div className="rounded-xl bg-white/10 p-3 text-right backdrop-blur">
              <div className="text-label-sm text-white/70">Vagas usadas</div>
              <div className="font-display text-display-lg leading-none text-white">
                {licenseInfo.seatsUsed}
                <span className="text-headline-md font-semibold text-white/70">
                  /{licenseInfo.seats}
                </span>
              </div>
            </div>
            <button
              type="button"
              className="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-label-md font-bold text-primary shadow-sm transition-colors hover:bg-white/95"
            >
              <Icon name="autorenew" className="text-sm" filled />
              Renovar agora
            </button>
          </div>
        </div>
      </section>

      <div className="grid grid-cols-1 gap-gutter lg:grid-cols-2">
        <article className="glass-panel rounded-2xl p-6 shadow-ambient">
          <header className="mb-4 flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
              <Icon name="key" className="text-2xl" filled />
            </div>
            <div>
              <h3 className="font-display text-headline-md text-on-surface">Chave de licença</h3>
              <p className="text-label-sm text-on-surface-variant">
                Use essa chave em outro domínio ou após reinstalar
              </p>
            </div>
          </header>

          <div className="flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-low p-3">
            <Icon name="vpn_key" className="text-on-surface-variant" />
            <code className="flex-1 truncate font-mono text-label-md text-on-surface">
              {showKey ? licenseInfo.key : '•'.repeat(licenseInfo.key.length)}
            </code>
            <button
              type="button"
              onClick={() => setShowKey((v) => !v)}
              aria-label={showKey ? 'Ocultar chave' : 'Mostrar chave'}
              className="rounded-lg p-2 text-on-surface-variant hover:bg-surface-variant"
            >
              <Icon name={showKey ? 'visibility_off' : 'visibility'} />
            </button>
            <button
              type="button"
              aria-label="Copiar chave"
              className="rounded-lg p-2 text-on-surface-variant hover:bg-surface-variant"
            >
              <Icon name="content_copy" />
            </button>
          </div>

          <ul className="mt-4 space-y-2 text-label-md text-on-surface">
            <DetailRow icon="domain" label="Domínio ativo" value={licenseInfo.domain} />
            <DetailRow icon="event_available" label="Ativado em" value={licenseInfo.activatedAt} />
            <DetailRow icon="event_busy" label="Expira em" value={licenseInfo.expiresAt} />
            <DetailRow
              icon="autorenew"
              label="Renovação automática"
              value={licenseInfo.autoRenew ? 'Ativada' : 'Desativada'}
            />
          </ul>
        </article>

        <article className="glass-panel rounded-2xl p-6 shadow-ambient">
          <header className="mb-4 flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-secondary-container/60 text-secondary">
              <Icon name="add_circle" className="text-2xl" filled />
            </div>
            <div>
              <h3 className="font-display text-headline-md text-on-surface">Ativar nova chave</h3>
              <p className="text-label-sm text-on-surface-variant">
                Use uma nova chave de licença para esse site
              </p>
            </div>
          </header>

          <label className="block">
            <span className="text-label-sm text-on-surface-variant">Chave de licença</span>
            <input
              type="text"
              value={newKey}
              onChange={(e) => setNewKey(e.target.value)}
              placeholder="GK-PRO-2025-XXXX-XXXX-XXXX"
              className="mt-1 w-full rounded-xl border border-outline-variant bg-surface-container-low px-4 py-3 font-mono text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
            />
          </label>
          <button
            type="button"
            className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-label-md font-bold text-white shadow-sm transition-colors hover:bg-primary-container disabled:cursor-not-allowed disabled:opacity-50"
            disabled={newKey.trim().length === 0}
          >
            <Icon name="bolt" className="text-sm" filled />
            Ativar licença
          </button>
          <p className="mt-3 text-label-sm text-on-surface-variant">
            Ao ativar, a chave anterior será desvinculada deste domínio. Você poderá usá-la em outro
            site.
          </p>
        </article>
      </div>

      <section className="glass-panel rounded-2xl p-6 shadow-ambient">
        <h3 className="mb-4 font-display text-headline-md text-on-surface">Histórico de cobrança</h3>
        <div className="overflow-hidden rounded-xl border border-outline-variant">
          <table className="w-full text-left text-label-md">
            <thead className="bg-surface-container-low text-on-surface">
              <tr>
                <th className="px-4 py-3 font-bold">Data</th>
                <th className="px-4 py-3 font-bold">Período</th>
                <th className="px-4 py-3 font-bold">Valor</th>
                <th className="px-4 py-3 text-center font-bold">Status</th>
                <th className="px-4 py-3 text-right font-bold">Recibo</th>
              </tr>
            </thead>
            <tbody>
              <BillingRow date="12/03/2026" period="12/03/26 → 12/03/27" amount="R$ 228,00" status="paid" />
              <BillingRow date="12/03/2025" period="12/03/25 → 12/03/26" amount="R$ 199,00" status="paid" />
              <BillingRow date="12/03/2024" period="12/03/24 → 12/03/25" amount="R$ 199,00" status="paid" />
            </tbody>
          </table>
        </div>
      </section>

      <section className="grid grid-cols-1 gap-gutter md:grid-cols-3">
        <SupportCard
          icon="support_agent"
          title="Suporte premium"
          body="Chat com SLA de 4h em horário comercial."
          cta="Abrir chamado"
        />
        <SupportCard
          icon="swap_horiz"
          title="Transferir licença"
          body="Mover essa chave pra outro domínio que você administra."
          cta="Transferir"
        />
        <SupportCard
          icon="cancel_schedule_send"
          title="Cancelar renovação"
          body="Você mantém o premium até a data de expiração."
          cta="Cancelar"
          tone="danger"
        />
      </section>
    </main>
  );
}

function DetailRow({ icon, label, value }: { icon: string; label: string; value: string }) {
  return (
    <li className="flex items-center gap-3 rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2">
      <Icon name={icon} className="text-on-surface-variant" />
      <span className="flex-1 text-label-sm text-on-surface-variant">{label}</span>
      <span className="font-semibold text-on-surface">{value}</span>
    </li>
  );
}

function BillingRow({
  date,
  period,
  amount,
  status,
}: {
  date: string;
  period: string;
  amount: string;
  status: 'paid' | 'pending';
}) {
  return (
    <tr className="border-t border-outline-variant bg-white">
      <td className="px-4 py-3 text-on-surface">{date}</td>
      <td className="px-4 py-3 text-on-surface-variant">{period}</td>
      <td className="px-4 py-3 font-semibold text-on-surface">{amount}</td>
      <td className="px-4 py-3 text-center">
        <span
          className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-label-sm font-semibold ${
            status === 'paid'
              ? 'bg-secondary-container/40 text-secondary'
              : 'bg-tertiary-fixed-dim text-on-tertiary-fixed-variant'
          }`}
        >
          <Icon name={status === 'paid' ? 'check_circle' : 'schedule'} className="text-sm" filled />
          {status === 'paid' ? 'Pago' : 'Pendente'}
        </span>
      </td>
      <td className="px-4 py-3 text-right">
        <button
          type="button"
          className="inline-flex items-center gap-1 text-label-md font-semibold text-primary hover:underline"
        >
          <Icon name="download" className="text-sm" />
          PDF
        </button>
      </td>
    </tr>
  );
}

function SupportCard({
  icon,
  title,
  body,
  cta,
  tone,
}: {
  icon: string;
  title: string;
  body: string;
  cta: string;
  tone?: 'danger';
}) {
  return (
    <article className="glass-panel flex flex-col gap-3 rounded-2xl p-5 shadow-ambient">
      <div
        className={`flex h-11 w-11 items-center justify-center rounded-xl ${
          tone === 'danger' ? 'bg-error-container/60 text-error' : 'bg-surface-container-high text-primary'
        }`}
      >
        <Icon name={icon} className="text-2xl" filled />
      </div>
      <h4 className="font-display text-headline-md text-on-surface">{title}</h4>
      <p className="text-body-md text-on-surface-variant">{body}</p>
      <button
        type="button"
        className={`mt-auto inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-label-md font-bold transition-colors ${
          tone === 'danger'
            ? 'bg-error text-white hover:bg-error/90'
            : 'border border-outline-variant bg-surface-container text-on-surface hover:bg-surface-variant'
        }`}
      >
        {cta}
      </button>
    </article>
  );
}
