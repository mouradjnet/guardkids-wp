import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { listChildren, updateChild, type UpdateChildInput } from '../api/children';
import { getUsageHourly, type UsageHourly } from '../api/reports';
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

type HourKind = 'bedtime' | 'used' | 'free';
type DayBlock = { start: number; end: number; kind: HourKind; label: string };

const BLOCK_COLORS: Record<HourKind, { bg: string; text: string; legend: string }> = {
  bedtime: { bg: 'bg-primary', text: 'text-white', legend: 'Bloqueado' },
  used: { bg: 'bg-orange-warm', text: 'text-white', legend: 'Em uso' },
  free: { bg: 'bg-surface-container-high', text: 'text-on-surface', legend: 'Livre' },
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
                <BedtimeCard child={selected} />
              </div>
              <WeeklyCard child={selected} />
              <TimelineCard child={selected} />
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

function BedtimeCard({ child }: { child: Child }) {
  const queryClient = useQueryClient();
  const [enabled, setEnabled] = useState<boolean>(child.bedtimeEnabled);
  const [start, setStart] = useState<string>(child.bedtimeStart ?? '21:30');
  const [end, setEnd] = useState<string>(child.bedtimeEnd ?? '07:00');

  const mutation = useMutation({
    mutationFn: (input: UpdateChildInput) => updateChild(child.id, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['children'] }),
  });

  const save = (patch: UpdateChildInput) => mutation.mutate(patch);

  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-surface-container-highest text-primary">
            <Icon name="bedtime" className="text-2xl" filled />
          </div>
          <div>
            <h3 className="font-display text-headline-md text-on-surface">Modo dormir</h3>
            <p className="text-label-sm text-on-surface-variant">
              Bloqueia tudo durante a noite
            </p>
          </div>
        </div>
        <Toggle
          on={enabled}
          onToggle={() => {
            const next = !enabled;
            setEnabled(next);
            save({ bedtime_enabled: next, bedtime_start: start, bedtime_end: end });
          }}
        />
      </header>

      <div className={`grid grid-cols-2 gap-3 ${enabled ? '' : 'opacity-40'}`}>
        <TimeInput
          label="Começa às"
          value={start}
          onChange={(v) => {
            setStart(v);
            save({ bedtime_enabled: enabled, bedtime_start: v, bedtime_end: end });
          }}
          icon="dark_mode"
          disabled={!enabled}
        />
        <TimeInput
          label="Termina às"
          value={end}
          onChange={(v) => {
            setEnd(v);
            save({ bedtime_enabled: enabled, bedtime_start: start, bedtime_end: v });
          }}
          icon="wb_sunny"
          disabled={!enabled}
        />
      </div>

      {mutation.isPending && (
        <p className="mt-3 flex items-center gap-2 text-label-sm text-on-surface-variant">
          <Icon name="progress_activity" className="animate-spin text-sm text-primary" />
          Salvando…
        </p>
      )}

      {mutation.error ? (
        <p role="alert" className="mt-3 rounded-lg bg-error/10 p-2 text-label-sm text-error">
          {mutation.error instanceof ApiError && mutation.error.status === 402
            ? 'Rotina escolar é um recurso Premium. Faça upgrade pra ativar bedtime.'
            : mutation.error instanceof ApiError
              ? `${mutation.error.message} (${mutation.error.status})`
              : mutation.error instanceof Error
                ? mutation.error.message
                : 'Erro desconhecido ao salvar.'}
        </p>
      ) : null}
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

function decodeWeekdays(mask: string): Set<WeekDay> {
  const safe = /^[YN]{7}$/.test(mask) ? mask : 'YYYYYYY';
  const out = new Set<WeekDay>();
  WEEK_DAYS.forEach((d, idx) => {
    if (safe[idx] === 'Y') out.add(d.id);
  });
  return out;
}

function encodeWeekdays(set: Set<WeekDay>): string {
  return WEEK_DAYS.map((d) => (set.has(d.id) ? 'Y' : 'N')).join('');
}

function WeeklyCard({ child }: { child: Child }) {
  const queryClient = useQueryClient();
  const [enabled, setEnabled] = useState<Set<WeekDay>>(decodeWeekdays(child.allowedWeekdays));

  const mutation = useMutation({
    mutationFn: (input: UpdateChildInput) => updateChild(child.id, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['children'] }),
  });

  const toggle = (d: WeekDay) => {
    const next = new Set(enabled);
    if (next.has(d)) next.delete(d);
    else next.add(d);
    setEnabled(next);
    mutation.mutate({ allowed_weekdays: encodeWeekdays(next) });
  };

  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-secondary-container/60 text-secondary">
          <Icon name="event_repeat" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-headline-md text-on-surface">Dias permitidos</h3>
          <p className="text-label-sm text-on-surface-variant">
            Clique pra ativar/desativar — bloqueia o filho nos dias desligados
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
              onClick={() => toggle(d.id)}
              disabled={mutation.isPending}
              className={
                active
                  ? 'flex flex-col items-center justify-center gap-1 rounded-xl bg-primary py-3 text-white shadow-sm disabled:opacity-60'
                  : 'flex flex-col items-center justify-center gap-1 rounded-xl border border-outline-variant bg-surface-container-low py-3 text-on-surface-variant hover:bg-surface-variant disabled:opacity-60'
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

      {mutation.error ? (
        <p role="alert" className="mt-3 rounded-lg bg-error/10 p-2 text-label-sm text-error">
          {mutation.error instanceof ApiError && mutation.error.status === 402
            ? 'Rotina escolar é um recurso Premium. Faça upgrade pra restringir dias.'
            : mutation.error instanceof ApiError
              ? `${mutation.error.message} (${mutation.error.status})`
              : mutation.error instanceof Error
                ? mutation.error.message
                : 'Erro desconhecido ao salvar.'}
        </p>
      ) : null}
    </article>
  );
}

function TimelineCard({ child }: { child: Child }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['usage', 'hourly', child.id],
    queryFn: () => getUsageHourly(child.id),
    refetchInterval: 60_000,
  });

  const blocks = data ? classifyDay(child, data) : null;

  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-orange-warm/15 text-orange-warm">
          <Icon name="timeline" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-headline-md text-on-surface">
            Linha do dia — {child.name}
          </h3>
          <p className="text-label-sm text-on-surface-variant">
            {data ? `Visão das 24h de hoje (${data.date})` : 'Carregando uso de hoje…'}
          </p>
        </div>
      </header>

      {error ? (
        <p role="alert" className="rounded-lg bg-error/10 p-3 text-label-sm text-error">
          {error instanceof ApiError
            ? `${error.message} (${error.status})`
            : error instanceof Error
              ? error.message
              : 'Erro desconhecido.'}
        </p>
      ) : null}

      {isLoading && !error ? (
        <div className="h-12 animate-pulse rounded-xl bg-surface-container-low" />
      ) : null}

      {blocks ? (
        <div className="flex h-12 w-full overflow-hidden rounded-xl border border-outline-variant">
          {blocks.map((b, idx) => {
            const c = BLOCK_COLORS[b.kind];
            const width = ((b.end - b.start) / 24) * 100;
            return (
              <div
                key={idx}
                title={`${b.label} · ${b.start}h–${b.end}h`}
                style={{ width: `${width}%` }}
                className={`flex items-center justify-center text-[10px] font-bold ${c.bg} ${c.text}`}
              >
                <span className="hidden truncate px-1 md:inline">{b.label}</span>
              </div>
            );
          })}
        </div>
      ) : null}

      <div className="mt-3 flex flex-wrap gap-4 text-label-sm text-on-surface-variant">
        <LegendItem color="bg-primary" label="Bloqueado" />
        <LegendItem color="bg-orange-warm" label="Em uso" />
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

/** Hora local 0..23 dentro do range bedtime do filho (cross-midnight ok). */
function bedtimeContainsHour(child: Child, hour: number): boolean {
  if (!child.bedtimeEnabled) return false;
  if (!child.bedtimeStart || !child.bedtimeEnd) return false;
  const startH = parseHour(child.bedtimeStart);
  const endH = parseHour(child.bedtimeEnd);
  if (startH === null || endH === null) return false;
  if (startH === endH) return false;
  if (startH < endH) return hour >= startH && hour < endH;
  // cross-midnight (ex: 21 → 7): pega [21..23] ∪ [0..7)
  return hour >= startH || hour < endH;
}

function parseHour(hhmm: string): number | null {
  const m = /^(\d{2}):(\d{2})$/.exec(hhmm);
  if (!m) return null;
  return Math.min(23, Math.max(0, Number(m[1])));
}

function todayIsBlockedWeekday(child: Child): boolean {
  const allowed = child.allowedWeekdays ?? 'YYYYYYY';
  // JS getDay: 0=Sun..6=Sat. Backend usa Mon=0..Sun=6.
  const todayIdx = (new Date().getDay() + 6) % 7;
  return allowed[todayIdx] === 'N';
}

export function classifyDay(child: Child, data: UsageHourly): DayBlock[] {
  const allDayBlocked = todayIsBlockedWeekday(child);
  const kinds: HourKind[] = data.hours.map(({ hour, minutes }) => {
    if (allDayBlocked) return 'bedtime';
    if (bedtimeContainsHour(child, hour)) return 'bedtime';
    if (minutes > 0) return 'used';
    return 'free';
  });

  // Mescla horas contíguas com mesmo kind em blocos
  const blocks: DayBlock[] = [];
  let i = 0;
  while (i < 24) {
    const kind = kinds[i];
    let j = i + 1;
    while (j < 24 && kinds[j] === kind) j++;
    blocks.push({
      start: i,
      end: j,
      kind,
      label: BLOCK_COLORS[kind].legend,
    });
    i = j;
  }
  return blocks;
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
