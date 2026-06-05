import { useState } from 'react';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';
import {
  childLimits,
  children,
  sampleDayBlocks,
  weekDays,
  type ChildLimits,
  type DayBlock,
  type WeekDay,
} from '../data/mockData';

const blockColors: Record<DayBlock['kind'], { bg: string; text: string }> = {
  sleep: { bg: 'bg-primary', text: 'text-white' },
  school: { bg: 'bg-primary-container', text: 'text-on-primary-container' },
  free: { bg: 'bg-surface-container-high', text: 'text-on-surface' },
  play: { bg: 'bg-orange-warm', text: 'text-white' },
};

const PRESET_MINUTES = [60, 90, 120, 180, 240];

export function TimeLimits() {
  const [selectedChildId, setSelectedChildId] = useState<string>('lucas');
  const currentLimits = childLimits.find((l) => l.childId === selectedChildId)!;
  const currentChild = children.find((c) => c.id === selectedChildId)!;

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Limites de Tempo"
        subtitle="Defina rotina, tempo diário e horário de dormir pra cada filho."
        action={
          <button
            type="button"
            className="inline-flex items-center gap-2 rounded-full bg-primary px-5 py-3 text-label-md font-semibold text-white shadow-ambient transition-colors hover:bg-primary-container"
          >
            <Icon name="save" className="text-lg" filled />
            Salvar tudo
          </button>
        }
      />

      <section className="glass-panel flex flex-wrap gap-2 rounded-2xl p-3 shadow-ambient">
        {children.map((c) => {
          const active = c.id === selectedChildId;
          return (
            <button
              key={c.id}
              type="button"
              onClick={() => setSelectedChildId(c.id)}
              className={
                active
                  ? 'flex items-center gap-2 rounded-full bg-primary px-4 py-2 text-label-md font-semibold text-white shadow-sm'
                  : 'flex items-center gap-2 rounded-full px-4 py-2 text-label-md font-semibold text-on-surface-variant hover:bg-surface-container'
              }
            >
              <img
                src={c.avatar}
                alt=""
                className={`h-7 w-7 rounded-full object-cover ${active ? 'ring-2 ring-white' : ''}`}
              />
              {c.name}
            </button>
          );
        })}
      </section>

      <div className="grid grid-cols-1 gap-gutter lg:grid-cols-2">
        <DailyTimeCard limits={currentLimits} />
        <BedtimeCard limits={currentLimits} />
      </div>

      <WeeklyCard limits={currentLimits} />

      <TimelineCard childName={currentChild.name} />
    </main>
  );
}

function DailyTimeCard({ limits }: { limits: ChildLimits }) {
  const [selected, setSelected] = useState(limits.dailyMinutes);
  return (
    <article className="glass-panel rounded-2xl p-6 shadow-ambient">
      <header className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
          <Icon name="schedule" className="text-2xl" filled />
        </div>
        <div>
          <h3 className="font-display text-headline-md text-on-surface">Tempo diário</h3>
          <p className="text-label-sm text-on-surface-variant">
            Limite máximo de tela por dia
          </p>
        </div>
      </header>

      <div className="text-center">
        <span className="font-display text-display-lg leading-none text-primary">
          {Math.floor(selected / 60)}h{selected % 60 ? ` ${selected % 60}min` : ''}
        </span>
      </div>

      <div className="mt-4 grid grid-cols-5 gap-2">
        {PRESET_MINUTES.map((m) => (
          <button
            key={m}
            type="button"
            onClick={() => setSelected(m)}
            className={
              selected === m
                ? 'rounded-xl bg-primary py-2 text-label-md font-bold text-white shadow-sm'
                : 'rounded-xl border border-outline-variant bg-surface-container-low py-2 text-label-md font-semibold text-on-surface hover:bg-surface-variant'
            }
          >
            {Math.floor(m / 60)}h{m % 60 ? `${m % 60}` : ''}
          </button>
        ))}
      </div>

      <div className="mt-5 grid grid-cols-2 gap-3">
        <SubLimit label="Dias úteis" value={`${Math.floor(limits.weekdayMinutes / 60)}h ${limits.weekdayMinutes % 60}min`} icon="work" />
        <SubLimit label="Fim de semana" value={`${Math.floor(limits.weekendMinutes / 60)}h ${limits.weekendMinutes % 60}min`} icon="weekend" />
      </div>
    </article>
  );
}

function SubLimit({ label, value, icon }: { label: string; value: string; icon: string }) {
  return (
    <div className="rounded-xl border border-outline-variant bg-surface-container-low px-4 py-3">
      <div className="flex items-center gap-2 text-on-surface-variant">
        <Icon name={icon} className="text-sm" />
        <span className="text-label-sm">{label}</span>
      </div>
      <div className="mt-1 font-display text-headline-md text-primary">{value}</div>
    </div>
  );
}

function BedtimeCard({ limits }: { limits: ChildLimits }) {
  const [start, setStart] = useState(limits.bedtimeStart);
  const [end, setEnd] = useState(limits.bedtimeEnd);
  const [enabled, setEnabled] = useState(true);
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
        <Toggle on={enabled} onToggle={() => setEnabled((v) => !v)} />
      </header>

      <div className={`grid grid-cols-2 gap-3 ${enabled ? '' : 'opacity-40'}`}>
        <TimeInput label="Começa às" value={start} onChange={setStart} icon="dark_mode" />
        <TimeInput label="Termina às" value={end} onChange={setEnd} icon="wb_sunny" />
      </div>

      <div className="mt-4 flex items-center gap-2 rounded-xl border border-primary/20 bg-primary/5 p-3 text-label-sm text-on-surface-variant">
        <Icon name="info" className="text-base text-primary" />
        Durante esse período a tela infantil mostra o Modo Bloqueado.
      </div>
    </article>
  );
}

function TimeInput({
  label,
  value,
  onChange,
  icon,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  icon: string;
}) {
  return (
    <label className="block">
      <span className="text-label-sm text-on-surface-variant">{label}</span>
      <div className="mt-1 flex items-center gap-2 rounded-xl border border-outline-variant bg-surface-container-low p-3">
        <Icon name={icon} className="text-base text-on-surface-variant" />
        <input
          type="time"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="flex-1 bg-transparent font-display text-headline-md font-bold text-primary outline-none"
        />
      </div>
    </label>
  );
}

function Toggle({ on, onToggle }: { on: boolean; onToggle: () => void }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      onClick={onToggle}
      className={`relative inline-flex h-7 w-12 items-center rounded-full transition-colors ${
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

function WeeklyCard({ limits }: { limits: ChildLimits }) {
  const [enabled, setEnabled] = useState<Set<WeekDay>>(new Set(limits.enabledDays));

  const toggle = (d: WeekDay) => {
    const next = new Set(enabled);
    if (next.has(d)) next.delete(d);
    else next.add(d);
    setEnabled(next);
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
            Toque pra ligar/desligar cada dia da semana
          </p>
        </div>
      </header>

      <div className="grid grid-cols-7 gap-2">
        {weekDays.map((d) => {
          const active = enabled.has(d.id);
          return (
            <button
              key={d.id}
              type="button"
              onClick={() => toggle(d.id)}
              className={
                active
                  ? 'flex flex-col items-center justify-center gap-1 rounded-xl bg-primary py-3 text-white shadow-sm'
                  : 'flex flex-col items-center justify-center gap-1 rounded-xl border border-outline-variant bg-surface-container-low py-3 text-on-surface-variant hover:bg-surface-variant'
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
          <h3 className="font-display text-headline-md text-on-surface">
            Linha do dia — {childName}
          </h3>
          <p className="text-label-sm text-on-surface-variant">
            Visualização de 24h com os blocos atuais
          </p>
        </div>
      </header>

      <div className="flex h-12 w-full overflow-hidden rounded-xl border border-outline-variant">
        {sampleDayBlocks.map((b, idx) => {
          const c = blockColors[b.kind];
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
