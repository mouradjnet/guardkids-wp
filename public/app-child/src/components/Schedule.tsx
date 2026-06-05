import { schedule, type ScheduleItem } from '../data/mockData';
import { Icon } from './Icon';

export function Schedule() {
  return (
    <section className="mb-4 flex flex-col gap-stack-sm">
      <h3 className="px-1 font-display text-headline-md text-primary">Agenda de Hoje</h3>
      <div className="glass-panel flex flex-col gap-4 rounded-2xl p-4 shadow-ambient">
        {schedule.map((item, idx) => (
          <Row key={item.id} item={item} isLast={idx === schedule.length - 1} />
        ))}
      </div>
    </section>
  );
}

type RowProps = { item: ScheduleItem; isLast: boolean };

function Row({ item, isLast }: RowProps) {
  const isActive = item.status === 'active';
  const isLater = item.status === 'later';
  const noteClass =
    item.status === 'active'
      ? 'bg-primary/10 text-primary'
      : item.status === 'upcoming'
        ? 'text-orange-warm'
        : '';
  const contentOpacity =
    item.status === 'upcoming' ? 'opacity-80' : isLater ? 'opacity-60' : '';

  return (
    <div className="relative flex items-start gap-4">
      {!isLast && (
        <div className="absolute left-[15px] top-8 bottom-[-1rem] w-[2px] bg-outline-variant/30" />
      )}
      <div className="relative z-10 flex flex-col items-center bg-surface">
        <div
          className={
            isActive
              ? 'flex h-8 w-8 items-center justify-center rounded-full bg-primary text-white shadow-sm ring-4 ring-primary/20'
              : 'flex h-8 w-8 items-center justify-center rounded-full border border-outline-variant bg-surface-container-high text-on-surface-variant'
          }
        >
          <Icon name={item.icon} className="text-sm" filled={isActive} />
        </div>
      </div>
      <div className={`flex-1 pb-2 ${contentOpacity}`}>
        <h4
          className={`text-label-md font-bold ${isActive ? 'text-primary' : 'text-on-surface'}`}
        >
          {item.title}
        </h4>
        <p className="text-label-sm text-on-surface-variant">{item.time}</p>
        {item.note && item.status === 'active' && (
          <span
            className={`mt-1 inline-block rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wide ${noteClass}`}
          >
            {item.note}
          </span>
        )}
        {item.note && item.status === 'upcoming' && (
          <p className={`mt-1 text-xs font-semibold ${noteClass}`}>{item.note}</p>
        )}
      </div>
    </div>
  );
}
