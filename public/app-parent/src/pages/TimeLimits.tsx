import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { listChildren, updateChild } from '../api/children';
import { ApiError } from '../api/client';
import type { Child } from '../api/types';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';

const PRESET_MINUTES = [60, 90, 120, 180, 240];

const WEEK_DAYS = [
  { id: 'mon', label: 'Seg' },
  { id: 'tue', label: 'Ter' },
  { id: 'wed', label: 'Qua' },
  { id: 'thu', label: 'Qui' },
  { id: 'fri', label: 'Sex' },
  { id: 'sat', label: 'Sáb' },
  { id: 'sun', label: 'Dom' },
] as const;
type WeekDay = (typeof WEEK_DAYS)[number]['id'];

type DayBlock = { start: number; end: number; kind: 'sleep' | 'school' | 'free' | 'play'; label: string };

const SAMPLE_DAY_BLOCKS: DayBlock[] = [
  { start: 0, end: 7, kind: 'sleep', label: 'Bedtime' },
  { start: 7, end: 12, kind: 'school', label: 'School' },
  { start: 12, end: 14, kind: 'free', label: 'Almoço' },
  { start: 14, end: 18, kind: 'school', label: 'School' },
  { start: 18, end: 21, kind: 'play', label: 'Play' },
  { start: 21, end: 24, kind: 'sleep', label: 'Bedtime' },
];

const BLOCK_COLORS: Record<DayBlock['kind'], { bg: string; text: string }> = {
  sleep: { bg: 'bg-primary', text: 'text-white' },
  school: { bg: 'bg-primary-container', text: 'text-on-primary-container' },
  free: { bg: 'bg-surface-container-high', text: 'text-on-surface' },
  play: { bg: 'bg-orange-warm', text: 'text-white' },
};

export function TimeLimits() {
  const childrenQuery = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const [selectedId, setSelectedId] = useState<number | null>(null);

  useEffect(() => {
    if (selectedId === null && childrenQuery.data && childrenQuery.data.length > 0) {
      setSelectedId(childrenQuery.data[0].id);
    }
  }, [selectedId, childrenQuery.data]);

  const selected =
    childrenQuery.data?.find((c) => c.id === selectedId) ?? null;

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Limites de Tempo"
        subtitle="Defina rotina, tempo diário e horário de dormir pra cada filho."
      />

      {childrenQuery.isLoading && (
        <div className="glass-panel h-20 animate-pulse rounded-2xl bg-surface-container-low" />
      )}

      {childrenQuery.error ? (
        <ListError error={childrenQuery.error} />
      ) : null}

      {childrenQuery.data && childrenQuery.data.length === 0 && (
        <Empty
          icon="child_care"
          title="Sem filhos cadastrados"
          subtitle="Vá em Filhos e adicione pelo menos uma criança pra configurar limites."
        />
      )}

      {childrenQuery.data && childrenQuery.data.length > 0 && (
        <>
          <section className="glass-panel flex flex-wrap gap-2 rounded-2xl p-3 shadow-ambient">
            {childrenQuery.data.map((c) => (
              <ChildChip
                key={c.id}
                child={c}
                active={c.id === selectedId}
                onClick={() => setSelectedId(c.id)}
              />
            ))}
          </section>

          {selected && (
            <>
              <div className="grid grid-cols-1 gap-gutter lg:grid-cols-2">
                <DailyTimeCard child={selected} />
                <BedtimeCard />
              </div>
              <WeeklyCard />
              <TimelineCard childName={selected.name} />
            </>
          )}
        </>
      )}
    </main>
  );
}

function ChildChip({
  child,
  active,
  onClick,
}: {
  child: Child;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={
        active
          ? 'flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-sm'
          : 'flex items-center gap-2 rounded-full px-4 py-2 text-label-md font-semibold text-on-surface-variant hover:bg-surface-container'
      }
    >
      {child.avatarUrl ? (
        <img
          src={child.avatarUrl}
          alt=""
          className={`h-7 w-7 rounded-full object-cover ${active ? 'ring-2 ring-white' : ''}`}
        />
      ) : (
        <span
          className={`flex h-7 w-7 items-center justify-center rounded-full bg-surface-container font-display text-label-sm font-semibold ${
            active ? 'text-primary ring-2 ring-white' : 'text-on-surface-variant'
          }`}
        >
          {child.name.charAt(0).toUpperCase()}
        </span>
      )}
      {child.name}
    </button>
  );
}

function DailyTimeCard({ child }: { child: Child }) {
  const queryClient = useQueryClient();
  const [optimistic, setOptimistic] = useState<number | null>(null);
  const value = optimistic ?? child.limitMinutes;

  const mutation = useMutation({
    mutationFn: (limit_minutes: number) =>
      updateChild(child.id, { limit_minutes }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['children'] });
      setOptimistic(null);
    },
    onError: () => {
      setOptimistic(null);
    },
  });

  function pick(m: number) {
    setOptimistic(m);
    mutation.mutate(m);
  }

  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
            <Icon name="schedule" className="text-2xl" filled />
          </div>
          <div>
            <h3 className="font-display text-headline-md text-on-surface">Tempo diário</h3>
            <p className="text-label-sm text-on-surface-variant">
              Limite máximo de tela por dia
            </p>
          </div>
        </div>
        {mutation.isPending && (
          <Icon
            name="progress_activity"
            className="animate-spin text-lg text-primary"
            aria-label="Salvando"
          />
        )}
      </header>

      <div className="text-center">
        <span className="font-display text-display-lg leading-none text-primary">
          {Math.floor(value / 60)}h{value % 60 ? ` ${value % 60}min` : ''}
        </span>
      </div>

      <div className="mt-4 grid grid-cols-5 gap-2">
        {PRESET_MINUTES.map((m) => (
          <button
            key={m}
            type="button"
            disabled={mutation.isPending}
            onClick={() => pick(m)}
            className={
              value === m
                ? 'rounded-xl bg-primary py-2 text-label-md font-bold text-white shadow-sm disabled:opacity-80'
                : 'rounded-xl border border-outline-variant bg-surface-container-low py-2 text-label-md font-semibold text-on-surface hover:bg-surface-variant disabled:opacity-60'
            }
          >
            {Math.floor(m / 60)}h{m % 60 ? `${m % 60}` : ''}
          </button>
        ))}
      </div>

      {mutation.error ? (
        <p role="alert" className="mt-3 rounded-lg bg-error/10 p-2 text-label-sm text-error">
          Falha ao salvar:{' '}
          {mutation.error instanceof ApiError
            ? `${mutation.error.message} (${mutation.error.status})`
            : mutation.error instanceof Error
              ? mutation.error.message
              : 'erro desconhecido'}
        </p>
      ) : null}
    </article>
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

function BedtimeCard() {
  const start = '21:30';
  const end = '07:00';
  const enabled = true;
  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-surface-container-highest text-primary">
            <Icon name="bedtime" className="text-2xl" filled />
          </div>
          <div>
            <h3 className="flex items-center gap-2 font-display text-headline-md text-on-surface">
              Modo dormir
              <ComingSoonBadge />
            </h3>
            <p className="text-label-sm text-on-surface-variant">
              Bloqueia tudo durante a noite
            </p>
          </div>
        </div>
        <Toggle on={enabled} onToggle={() => undefined} disabled />
      </header>

      <div className="grid grid-cols-2 gap-3">
        <TimeInput label="Começa às" value={start} onChange={() => undefined} icon="dark_mode" disabled />
        <TimeInput label="Termina às" value={end} onChange={() => undefined} icon="wb_sunny" disabled />
      </div>

      <div className="mt-4 flex items-center gap-2 rounded-xl border border-primary/20 bg-primary/5 p-3 text-label-sm text-on-surface-variant">
        <Icon name="info" className="text-base text-primary" />
        Configuração de horário de dormir vai vir na próxima fase. Por enquanto, o backend já honra o schedule definido em outros canais.
      </div>
    </article>
  );
}

function TimeInput({
  label,
  value,
  onChange,
  icon,
  disabled,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  icon: string;
  disabled?: boolean;
}) {
  return (
    <label className={`block ${disabled ? 'cursor-not-allowed opacity-50' : ''}`}>
      <span className="text-label-sm text-on-surface-variant">{label}</span>
      <div className="mt-1 flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-low p-3">
        <Icon name={icon} className="text-base text-on-surface-variant" />
        <input
          type="time"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
          className="flex-1 bg-transparent font-display text-headline-md font-bold text-primary outline-none disabled:cursor-not-allowed"
        />
      </div>
    </label>
  );
}

function Toggle({
  on,
  onToggle,
  disabled,
}: {
  on: boolean;
  onToggle: () => void;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      onClick={onToggle}
      disabled={disabled}
      className={`relative inline-flex h-7 w-12 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
        on ? 'bg-primary' : 'bg-outline-variant'
      }`}
    >
      <span
        className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform ${
          on ? 'translate-x-6' : 'translate-x-1'
        }`}
      />
    </button>
  );
}

function WeeklyCard() {
  const enabled = new Set<WeekDay>(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);

  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-secondary-container/60 text-secondary">
          <Icon name="event_repeat" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="flex items-center gap-2 font-display text-headline-md text-on-surface">
            Dias permitidos
            <ComingSoonBadge />
          </h3>
          <p className="text-label-sm text-on-surface-variant">
            Pré-visualização da configuração semanal
          </p>
        </div>
      </header>

      <div className="grid grid-cols-7 gap-2">
        {WEEK_DAYS.map((d) => {
          const active = enabled.has(d.id);
          return (
            <button
              key={d.id}
              type="button"
              disabled
              className={
                active
                  ? 'flex flex-col items-center justify-center gap-1 rounded-xl bg-primary py-3 text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50'
                  : 'flex flex-col items-center justify-center gap-1 rounded-xl border border-outline-variant bg-surface-container-low py-3 text-on-surface-variant disabled:cursor-not-allowed disabled:opacity-50'
              }
            >
              <span className="text-label-md font-bold">{d.label}</span>
              <Icon
                name={active ? 'check_circle' : 'block'}
                className={`text-sm ${active ? 'text-white' : 'text-on-surface-variant'}`}
                filled
              />
            </button>
          );
        })}
      </div>
    </article>
  );
}

function TimelineCard({ childName }: { childName: string }) {
  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-orange-warm/15 text-orange-warm">
          <Icon name="timeline" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="flex items-center gap-2 font-display text-headline-md text-on-surface">
            Linha do dia — {childName}
            <ComingSoonBadge />
          </h3>
          <p className="text-label-sm text-on-surface-variant">
            Mockup visual de 24h — vai vir do tracking real quando houver tabela de uso.
          </p>
        </div>
      </header>

      <div className="flex h-12 w-full overflow-hidden rounded-xl border border-outline-variant">
        {SAMPLE_DAY_BLOCKS.map((b, idx) => {
          const c = BLOCK_COLORS[b.kind];
          const width = ((b.end - b.start) / 24) * 100;
          return (
            <div
              key={idx}
              title={`${b.label} • ${b.start}h–${b.end}h`}
              style={{ width: `${width}%` }}
              className={`flex items-center justify-center text-[10px] font-bold ${c.bg} ${c.text}`}
            >
              <span className="hidden truncate px-1 md:inline">{b.label}</span>
            </div>
          );
        })}
      </div>

      <div className="mt-3 flex flex-wrap gap-4 text-label-sm text-on-surface-variant">
        <LegendItem color="bg-primary" label="Bedtime" />
        <LegendItem color="bg-primary-container" label="School Time" />
        <LegendItem color="bg-orange-warm" label="Play Time" />
        <LegendItem color="bg-surface-container-high" label="Livre" />
      </div>

      <div className="mt-3 grid grid-cols-9 text-center text-[10px] text-on-surface-variant">
        {[0, 3, 6, 9, 12, 15, 18, 21, 24].map((h) => (
          <span key={h}>{String(h).padStart(2, '0')}h</span>
        ))}
      </div>
    </article>
  );
}

function LegendItem({ color, label }: { color: string; label: string }) {
  return (
    <div className="flex items-center gap-2">
      <span className={`inline-block h-3 w-3 rounded-sm ${color}`} />
      {label}
    </div>
  );
}

function Empty({
  icon,
  title,
  subtitle,
}: {
  icon: string;
  title: string;
  subtitle: string;
}) {
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-3 rounded-2xl p-12 text-center shadow-ambient">
      <div className="flex h-16 w-16 items-center justify-center rounded-full bg-surface-container-high text-primary">
        <Icon name={icon} className="text-3xl" />
      </div>
      <h3 className="font-display text-headline-md text-on-surface">{title}</h3>
      <p className="text-body-md text-on-surface-variant">{subtitle}</p>
    </div>
  );
}

function ListError({ error }: { error: unknown }) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : 'Erro desconhecido.';
  return (
    <div className="glass-panel flex flex-col items-center justify-center gap-2 rounded-2xl bg-error/5 p-6 text-error">
      <Icon name="error" className="text-3xl" />
      <p className="text-label-md font-semibold">Falha ao carregar filhos</p>
      <p className="text-label-sm text-error/80">{message}</p>
    </div>
  );
}
