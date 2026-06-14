import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { createRequest, listMyRequests } from '../api/child';
import { ApiError } from '../api/client';
import type { CreateRequestInput, MyRequest, MyRequestStatus } from '../api/types';
import { Icon } from '../components/Icon';

type FormKind = 'none' | 'time' | 'site';
const TIME_PRESETS = [15, 30, 45];

function formatRelative(iso: string | null): string {
  if (!iso) return '';
  const date = new Date(iso);
  const diffMs = Date.now() - date.getTime();
  if (Number.isNaN(diffMs)) return '';
  const diffMin = Math.floor(diffMs / 60_000);
  if (diffMin < 1) return 'agora';
  if (diffMin < 60) return `há ${diffMin} min`;
  const diffH = Math.floor(diffMin / 60);
  if (diffH < 24) return `há ${diffH}h`;
  const diffD = Math.floor(diffH / 24);
  if (diffD < 7) return `há ${diffD}d`;
  return date.toLocaleDateString('pt-BR');
}

export function Requests() {
  const [openForm, setOpenForm] = useState<FormKind>('none');
  const queryClient = useQueryClient();
  const listQuery = useQuery({ queryKey: ['child', 'requests'], queryFn: listMyRequests });

  const mutation = useMutation({
    mutationFn: createRequest,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['child', 'requests'] });
      setOpenForm('none');
    },
  });

  return (
    <main className="flex flex-1 flex-col gap-stack-lg px-container-padding-mobile py-stack-md">
      <section>
        <h3 className="mb-2 px-1 text-label-md font-semibold text-on-surface-variant">
          Pedidos rápidos
        </h3>
        <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
          <QuickChip
            icon="sports_esports"
            label="Jogar +30 min"
            disabled={mutation.isPending}
            onClick={() =>
              mutation.mutate({
                kind: 'extra_time',
                description: 'Mais tempo de tela —',
                highlight: '+30 min',
              })
            }
          />
          <QuickChip
            icon="smart_display"
            label="Assistir YouTube"
            disabled={mutation.isPending}
            onClick={() =>
              mutation.mutate({
                kind: 'unblock_site',
                description: 'Liberar site',
                highlight: 'youtube.com',
              })
            }
          />
          <QuickChip
            icon="public"
            label="Liberar um site"
            disabled={mutation.isPending}
            onClick={() => setOpenForm('site')}
          />
          <QuickChip
            icon="task_alt"
            label="Terminei a tarefa"
            disabled={mutation.isPending}
            onClick={() =>
              mutation.mutate({
                kind: 'other',
                description: 'Terminei minha tarefa',
                highlight: '✅',
              })
            }
          />
          <QuickChip
            icon="forum"
            label="Enviar mensagem"
            disabled={mutation.isPending}
            onClick={() => setOpenForm('time')}
          />
        </div>
      </section>

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

      {openForm === 'time' && (
        <TimeRequestForm
          submitting={mutation.isPending}
          error={mutation.error}
          onSubmit={(input) => mutation.mutate(input)}
          onClose={() => setOpenForm('none')}
        />
      )}
      {openForm === 'site' && (
        <SiteRequestForm
          submitting={mutation.isPending}
          error={mutation.error}
          onSubmit={(input) => mutation.mutate(input)}
          onClose={() => setOpenForm('none')}
        />
      )}

      <section className="flex flex-col gap-3">
        <h3 className="px-1 font-display text-headline-md text-primary">Meus pedidos</h3>

        {listQuery.isLoading && (
          <div className="glass-panel h-24 animate-pulse rounded-2xl bg-surface-container-low" />
        )}

        {listQuery.error ? (
          <div className="glass-panel flex flex-col items-center gap-2 rounded-2xl bg-error/5 p-4 text-error">
            <Icon name="error" className="text-2xl" />
            <p className="text-label-sm">
              {listQuery.error instanceof ApiError
                ? `${listQuery.error.message} (${listQuery.error.status})`
                : listQuery.error instanceof Error
                  ? listQuery.error.message
                  : 'Erro desconhecido.'}
            </p>
          </div>
        ) : null}

        {listQuery.data && listQuery.data.length === 0 && (
          <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl p-6 text-center text-on-surface-variant">
            <Icon name="inbox" className="text-3xl text-primary" filled />
            <p className="text-label-md font-semibold">Nenhum pedido ainda</p>
            <p className="text-label-sm">Toque acima pra fazer o seu primeiro pedido.</p>
          </div>
        )}

        {listQuery.data && listQuery.data.length > 0 && (
          <div className="glass-panel rounded-2xl shadow-ambient">
            <ul className="divide-y divide-outline-variant/50">
              {listQuery.data.map((r) => (
                <RequestRow key={r.id} req={r} />
              ))}
            </ul>
          </div>
        )}
      </section>
    </main>
  );
}

function QuickChip({
  icon,
  label,
  onClick,
  disabled,
}: {
  icon: string;
  label: string;
  onClick: () => void;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className="flex shrink-0 items-center gap-2 rounded-full border border-outline-variant bg-white px-3 py-2 text-label-sm font-semibold text-on-surface shadow-sm transition-colors hover:bg-primary-container/40 hover:text-primary disabled:opacity-60"
    >
      <Icon name={icon} className="text-base" filled />
      {label}
    </button>
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

function TimeRequestForm({
  submitting,
  error,
  onSubmit,
  onClose,
}: {
  submitting: boolean;
  error: unknown;
  onSubmit: (input: CreateRequestInput) => void;
  onClose: () => void;
}) {
  const [minutes, setMinutes] = useState<number>(30);
  const [reason, setReason] = useState('');

  function submit(e: FormEvent) {
    e.preventDefault();
    onSubmit({
      kind: 'extra_time',
      description: 'Mais tempo de tela —',
      highlight: `+${minutes} min`,
      reason: reason.trim() || undefined,
    });
  }

  return (
    <form onSubmit={submit} className="glass-panel rounded-2xl p-5 shadow-ambient">
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
        {TIME_PRESETS.map((m) => (
          <button
            key={m}
            type="button"
            onClick={() => setMinutes(m)}
            className={
              minutes === m
                ? 'rounded-xl bg-orange-warm py-2 text-label-md font-bold text-white shadow-sm'
                : 'rounded-xl border border-outline-variant bg-surface-container py-2 text-label-md font-bold text-on-surface hover:bg-surface-variant'
            }
          >
            +{m} min
          </button>
        ))}
      </div>
      <label className="mt-4 block">
        <span className="text-label-sm text-on-surface-variant">Motivo (opcional)</span>
        <textarea
          rows={3}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="Tô quase passando essa fase..."
          className="mt-1 w-full resize-none rounded-xl border border-outline-variant bg-surface-container-low p-3 text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
        />
      </label>
      <FormError error={error} />
      <button
        type="submit"
        disabled={submitting}
        className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-orange-warm py-3 text-label-md font-bold text-white shadow-sm hover:bg-orange-warm/90 disabled:opacity-60"
      >
        <Icon
          name={submitting ? 'progress_activity' : 'send'}
          className={`text-sm ${submitting ? 'animate-spin' : ''}`}
          filled={!submitting}
        />
        {submitting ? 'Enviando…' : 'Enviar pedido'}
      </button>
    </form>
  );
}

function SiteRequestForm({
  submitting,
  error,
  onSubmit,
  onClose,
}: {
  submitting: boolean;
  error: unknown;
  onSubmit: (input: CreateRequestInput) => void;
  onClose: () => void;
}) {
  const [site, setSite] = useState('');
  const [reason, setReason] = useState('');

  function submit(e: FormEvent) {
    e.preventDefault();
    const domain = site.trim().toLowerCase();
    if (!domain) return;
    onSubmit({
      kind: 'unblock_site',
      description: 'Liberar site',
      highlight: domain,
      reason: reason.trim() || undefined,
    });
  }

  return (
    <form onSubmit={submit} className="glass-panel rounded-2xl p-5 shadow-ambient">
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
          value={site}
          onChange={(e) => setSite(e.target.value)}
          placeholder="ex: coolmathgames.com"
          required
          className="mt-1 w-full rounded-xl border border-outline-variant bg-surface-container-low p-3 text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
        />
      </label>
      <label className="mt-3 block">
        <span className="text-label-sm text-on-surface-variant">Por que você quer acessar?</span>
        <textarea
          rows={3}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="Tem jogos pra aprender matemática..."
          className="mt-1 w-full resize-none rounded-xl border border-outline-variant bg-surface-container-low p-3 text-label-md text-on-surface placeholder:text-on-surface-variant focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
        />
      </label>
      <FormError error={error} />
      <button
        type="submit"
        disabled={submitting || !site.trim()}
        className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-label-md font-bold text-white shadow-sm hover:bg-primary/90 disabled:opacity-60"
      >
        <Icon
          name={submitting ? 'progress_activity' : 'send'}
          className={`text-sm ${submitting ? 'animate-spin' : ''}`}
          filled={!submitting}
        />
        {submitting ? 'Enviando…' : 'Enviar pedido'}
      </button>
    </form>
  );
}

function FormError({ error }: { error: unknown }) {
  if (!error) return null;
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <p role="alert" className="mt-3 rounded-lg bg-error/10 p-2 text-label-sm text-error">
      {message}
    </p>
  );
}

function RequestRow({ req }: { req: MyRequest }) {
  const style = statusStyle(req.status);
  const title = `${req.description ?? req.kind}${req.highlight ? ` ${req.highlight}` : ''}`.trim();
  return (
    <li className="flex items-center gap-3 p-4">
      <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${style.bg}`}>
        <Icon name={style.icon} className={style.text} filled />
      </div>
      <div className="flex-1">
        <div className="text-label-md font-semibold text-on-surface">{title}</div>
        {req.reason && (
          <div className="text-label-sm text-on-surface-variant">{req.reason}</div>
        )}
      </div>
      <div className="text-right">
        <div className={`text-label-sm font-bold ${style.text}`}>{style.label}</div>
        <div className="text-label-sm text-on-surface-variant">{formatRelative(req.createdAt)}</div>
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
      return { icon: 'check_circle', label: 'Aprovado', text: 'text-secondary', bg: 'bg-secondary-container/40' };
    case 'denied':
      return { icon: 'cancel', label: 'Negado', text: 'text-error', bg: 'bg-error-container/60' };
    case 'pending':
      return { icon: 'hourglass_empty', label: 'Aguardando', text: 'text-orange-warm', bg: 'bg-orange-warm/15' };
  }
}
