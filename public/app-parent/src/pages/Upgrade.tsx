import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import { planFeatures } from '../data/mockData';

export function Upgrade() {
  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Upgrade Premium"
        subtitle="Desbloqueie todo o potencial do GuardKids WP pra sua família."
      />

      <section className="glass-panel relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary to-primary-container p-8 text-white shadow-ambient md:p-10">
        <div className="pointer-events-none absolute -right-10 -top-10 opacity-10">
          <span className="material-symbols-outlined" style={{ fontSize: 240 }}>
            workspace_premium
          </span>
        </div>
        <div className="relative z-10 flex flex-col items-start gap-4 md:flex-row md:items-center md:justify-between">
          <div className="max-w-xl">
            <span className="inline-flex items-center gap-1 rounded-full bg-tertiary-fixed-dim/30 px-3 py-1 text-label-sm font-bold text-tertiary-fixed">
              <Icon name="bolt" className="text-sm" filled />
              Oferta de lançamento
            </span>
            <h2 className="mt-3 font-display text-headline-lg text-white">
              Proteção completa para todos os seus filhos.
            </h2>
            <p className="mt-2 text-body-md text-white/80">
              Navegador infantil seguro, relatórios completos, rotina escolar e
              filhos ilimitados — tudo em um único plano.
            </p>
          </div>
          <div className="text-right">
            <div className="text-label-sm uppercase tracking-wider text-white/70">A partir de</div>
            <div className="font-display text-display-lg leading-none text-white">
              R$ 19<span className="text-headline-md font-bold">/mês</span>
            </div>
            <div className="text-label-sm text-white/80">Cancele quando quiser.</div>
          </div>
        </div>
      </section>

      <div className="grid grid-cols-1 gap-gutter lg:grid-cols-2">
        <PlanCard
          plan="free"
          name="GuardKids Free"
          price="R$ 0"
          tagline="Pra começar com o essencial."
          ctaLabel="Plano atual"
          ctaTone="secondary"
        />
        <PlanCard
          plan="premium"
          name="GuardKids Premium"
          price="R$ 19/mês"
          tagline="Recomendado para famílias com 2+ filhos."
          ctaLabel="Fazer upgrade agora"
          ctaTone="primary"
          recommended
        />
      </div>

      <section className="glass-panel rounded-2xl p-6 shadow-ambient md:p-8">
        <h3 className="font-display text-headline-md text-primary">Comparativo completo</h3>
        <div className="mt-4 overflow-hidden rounded-xl border border-outline-variant">
          <table className="w-full text-left text-sm">
            <thead className="bg-surface-container-low text-on-surface">
              <tr>
                <th className="px-4 py-3 text-label-md font-bold">Recurso</th>
                <th className="px-4 py-3 text-center text-label-md font-bold">Free</th>
                <th className="px-4 py-3 text-center text-label-md font-bold text-primary">Premium</th>
              </tr>
            </thead>
            <tbody>
              {planFeatures.map((f, idx) => (
                <tr key={f.id} className={idx % 2 ? 'bg-surface-container-low/40' : 'bg-white'}>
                  <td className="px-4 py-3 text-on-surface">{f.label}</td>
                  <td className="px-4 py-3 text-center">
                    <CellValue value={f.free} muted />
                  </td>
                  <td className="px-4 py-3 text-center">
                    <CellValue value={f.premium} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="grid grid-cols-1 gap-gutter md:grid-cols-3">
        <FaqCard
          icon="security"
          title="Pagamento seguro"
          body="Processamento por gateway PCI-DSS. Não armazenamos dados do cartão."
        />
        <FaqCard
          icon="autorenew"
          title="Cancele a qualquer momento"
          body="Sem fidelidade. Você mantém os dados mesmo se rebaixar para o Free."
        />
        <FaqCard
          icon="support_agent"
          title="Suporte prioritário"
          body="Premium tem fila exclusiva no chat e SLA de 4 horas em horário comercial."
        />
      </section>
    </main>
  );
}

function PlanCard({
  plan,
  name,
  price,
  tagline,
  ctaLabel,
  ctaTone,
  recommended,
}: {
  plan: 'free' | 'premium';
  name: string;
  price: string;
  tagline: string;
  ctaLabel: string;
  ctaTone: 'primary' | 'secondary';
  recommended?: boolean;
}) {
  const features = planFeatures.filter((f) => (plan === 'premium' ? !!f.premium : !!f.free));
  return (
    <article
      className={`glass-panel relative flex flex-col gap-4 rounded-2xl p-6 shadow-ambient md:p-8 ${
        recommended ? 'border-2 border-primary' : ''
      }`}
    >
      {recommended && (
        <span className="absolute -top-3 left-6 inline-flex items-center gap-1 rounded-full bg-primary px-3 py-1 text-label-sm font-bold text-white shadow">
          <Icon name="star" className="text-sm" filled />
          Recomendado
        </span>
      )}

      <div className="flex items-center gap-3">
        <div
          className={`flex h-12 w-12 items-center justify-center rounded-xl ${
            plan === 'premium' ? 'bg-primary text-white' : 'bg-surface-container-high text-primary'
          }`}
        >
          <Icon name={plan === 'premium' ? 'workspace_premium' : 'shield'} className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-headline-md text-on-surface">{name}</h3>
          <p className="text-label-sm text-on-surface-variant">{tagline}</p>
        </div>
      </div>

      <div className="flex items-end gap-2">
        <span className="font-display text-display-lg leading-none text-primary">{price}</span>
      </div>

      <ul className="space-y-2">
        {features.map((f) => (
          <li key={f.id} className="flex items-center gap-2 text-label-md text-on-surface">
            <Icon name="check_circle" className="text-secondary" filled />
            <span>{f.label}</span>
            {typeof (plan === 'premium' ? f.premium : f.free) === 'string' && (
              <span className="text-label-sm text-on-surface-variant">
                ({String(plan === 'premium' ? f.premium : f.free)})
              </span>
            )}
          </li>
        ))}
      </ul>

      <button
        type="button"
        className={`mt-auto inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-label-md font-bold transition-colors ${
          ctaTone === 'primary'
            ? 'bg-primary text-white hover:bg-primary-container'
            : 'border border-outline-variant bg-surface-container text-on-surface hover:bg-surface-variant'
        }`}
      >
        {ctaTone === 'primary' && <Icon name="bolt" className="text-sm" filled />}
        {ctaLabel}
      </button>
    </article>
  );
}

function CellValue({ value, muted }: { value: string | boolean; muted?: boolean }) {
  if (value === true) {
    return <Icon name="check_circle" className="text-secondary" filled />;
  }
  if (value === false) {
    return <Icon name="cancel" className="text-on-surface-variant/60" filled />;
  }
  return (
    <span className={`text-label-md font-semibold ${muted ? 'text-on-surface-variant' : 'text-primary'}`}>
      {value}
    </span>
  );
}

function FaqCard({
  icon,
  title,
  body,
}: {
  icon: string;
  title: string;
  body: string;
}) {
  return (
    <article className="glass-panel flex flex-col gap-2 rounded-2xl p-5 shadow-ambient">
      <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-surface-container-high text-primary">
        <Icon name={icon} className="text-2xl" filled />
      </div>
      <h4 className="font-display text-headline-md text-on-surface">{title}</h4>
      <p className="text-body-md text-on-surface-variant">{body}</p>
    </article>
  );
}
