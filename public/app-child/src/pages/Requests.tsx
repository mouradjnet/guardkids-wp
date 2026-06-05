import { useState } from 'react';
import { Icon } from '../components/Icon';
import { myRequests, type MyRequest, type MyRequestStatus } from '../data/mockData';

type Form = 'none' | 'time' | 'site';

export function Requests() {
  const [openForm, setOpenForm] = useState<Form>('none');

  return (
    <main className="flex flex-1 flex-col gap-stack-lg px-container-padding-mobile py-stack-md">
      <section className="grid grid-cols-2 gap-3">
        <ActionCard
          icon="more_time"
          tone="orange"
          title="Pedir mais tempo"
          subtitle="Estendido por +15 ou +30 min"
          onClick={() => setOpenForm(openForm === 'time' ? 'none' : 'time')}
          active={openForm === 'time'}
        />
        <ActionCard
          icon="public"
          tone="primary"
          title="Pedir site"
          subtitle="Liberar um site novo"
          onClick={() => setOpenForm(openForm === 'site' ? 'none' : 'site')}
          active={openForm === 'site'}
        />
      </section>

      {openForm === 'time' && <TimeRequestForm onClose={() => setOpenForm('none')} />}
      {openForm === 'site' && <SiteRequestForm onClose={() => setOpenForm('none')} />}

      <section className="flex flex-col gap-3">
        <h3 className="px-1 font-display text-headline-md text-primary">Meus pedidos</h3>
        <div className="glass-panel rounded-2xl shadow-ambient">
          <ul className="divide-y divide-outline-variant/50">
            {myRequests.map((r) => (
              <RequestRow key={r.id} req={r} />
            ))}
          </ul>
        </div>
      </section>
    </main>
  );
}

function ActionCard({
  icon,
  tone,
  title,
  subtitle,
  onClick,
  active,
}: {
  icon: string;
  tone: 'orange' | 'primary';
  title: string;
  subtitle: string;
  onClick: () => void;
  active: boolean;
}) {
  const ring = tone === 'orange' ? 'bg-orange-warm/15 text-orange-warm' : 'bg-primary/10 text-primary';
  return (
    <button
      type="button"
      onClick={onClick}
      className={`flex flex-col items-start gap-2 rounded-2xl border p-4 text-left shadow-ambient transition-colors active:scale-95 ${
        active
          ? 'border-primary bg-surface-container-highest'
          : 'border-outline-variant bg-surface-container-high hover:bg-surface-container-highest'
      }`}
    >
      <div className={`flex h-12 w-12 items-center justify-center rounded-full ${ring}`}>
        <Icon name={icon} className="text-2xl" filled />
      </div>
      <div>
        <div className="text-label-md font-bold text-on-surface">{title}</div>
        <div className="text-label-sm text-on-surface-variant">{subtitle}</div>
      </div>
    </button>
  );
}

function TimeRequestForm({ onClose }: { onClose: () => void }) {
  return (
    <section className="glass-panel rounded-2xl p-5 shadow-ambient">
      <div className="mb-3 flex items-center justify-between">
        <h4 className="font-display text-label-md font-bold text-on-surface">
          Quanto tempo a mais?
        </h4>
        <button
          type="button"
          onClick={onClose}
          aria-label="Fechar"
          className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50"
        >
          <Icon name="close" />
        </button>
      </div>
      <div className="grid grid-cols-3 gap-2">
        {[15, 30, 45].map((m) => (
          <button
            key={m}
            type="button"
            className="rounded-xl border border-outline-variant bg-surface-container py-2 text-label-md font-bold text-on-surface hover:bg-surface-variant"
          >
            +{m} min
          </button>
        ))}
      </div>
      <label className="mt-4 block">
        <span className="text-label-sm text-on-surface-variant">Motivo (opcional)</span>
        <textarea
          rows={3}
          placeholder="Tô quase passando essa fase..."
          className="mt-1 w-full resize-none rounded-xl border border-outline-variant bg-surface-container-low p-3 text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
        />
      </label>
      <button
        type="button"
        className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-orange-warm py-3 text-label-md font-bold text-white shadow-sm hover:bg-orange-warm/90"
      >
        <Icon name="send" className="text-sm" filled />
        Enviar pedido
      </button>
    </section>
  );
}

function SiteRequestForm({ onClose }: { onClose: () => void }) {
  return (
    <section className="glass-panel rounded-2xl p-5 shadow-ambient">
      <div className="mb-3 flex items-center justify-between">
        <h4 className="font-display text-label-md font-bold text-on-surface">
          Qual site você quer acessar?
        </h4>
        <button
          type="button"
          onClick={onClose}
          aria-label="Fechar"
          className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50"
        >
          <Icon name="close" />
        </button>
      </div>
      <label className="block">
        <span className="text-label-sm text-on-surface-variant">Site</span>
        <input
          type="text"
          placeholder="ex: coolmathgames.com"
          className="mt-1 w-full rounded-xl border border-outline-variant bg-surface-container-low p-3 text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
        />
      </label>
      <label className="mt-3 block">
        <span className="text-label-sm text-on-surface-variant">Por que você quer acessar?</span>
        <textarea
          rows={3}
          placeholder="Tem jogos pra aprender matemática..."
          className="mt-1 w-full resize-none rounded-xl border border-outline-variant bg-surface-container-low p-3 text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
        />
      </label>
      <button
        type="button"
        className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-label-md font-bold text-white shadow-sm hover:bg-primary/90"
      >
        <Icon name="send" className="text-sm" filled />
        Enviar pedido
      </button>
    </section>
  );
}

function RequestRow({ req }: { req: MyRequest }) {
  const style = statusStyle(req.status);
  return (
    <li className="flex items-center gap-3 p-4">
      <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${style.bg}`}>
        <Icon name={style.icon} className={style.text} filled />
      </div>
      <div className="flex-1">
        <div className="text-label-md font-semibold text-on-surface">{req.title}</div>
        <div className="text-label-sm text-on-surface-variant">{req.detail}</div>
      </div>
      <div className="text-right">
        <div className={`text-label-sm font-bold ${style.text}`}>{style.label}</div>
        <div className="text-label-sm text-on-surface-variant">{req.whenLabel}</div>
      </div>
    </li>
  );
}

function statusStyle(status: MyRequestStatus): {
  icon: string;
  label: string;
  text: string;
  bg: string;
} {
  switch (status) {
    case 'approved':
      return {
        icon: 'check_circle',
        label: 'Aprovado',
        text: 'text-secondary',
        bg: 'bg-secondary-container/40',
      };
    case 'denied':
      return {
        icon: 'cancel',
        label: 'Negado',
        text: 'text-error',
        bg: 'bg-error-container/60',
      };
    case 'pending':
      return {
        icon: 'hourglass_empty',
        label: 'Aguardando',
        text: 'text-orange-warm',
        bg: 'bg-orange-warm/15',
      };
  }
}
