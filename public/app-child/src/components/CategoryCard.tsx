import { Icon } from './Icon';

type CategoryCardProps = {
  icon: string;
  name: string;
  description: string;
  count: number;
};

export function CategoryCard({ icon, name, description, count }: CategoryCardProps) {
  return (
    <div className="glass-panel flex flex-col gap-2 rounded-2xl p-4 shadow-ambient">
      <div className="flex items-center justify-between">
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
          <Icon name={icon} className="text-2xl" filled />
        </div>
        <span className="rounded-full bg-surface-variant px-2 py-0.5 text-label-sm font-bold text-on-surface-variant">
          {count}
        </span>
      </div>
      <div className="font-display text-label-md font-bold text-on-surface">{name}</div>
      <div className="text-label-sm text-on-surface-variant">{description}</div>
      {count === 0 && (
        <div className="mt-1 text-label-sm italic text-on-surface-variant/70">Em breve…</div>
      )}
    </div>
  );
}
