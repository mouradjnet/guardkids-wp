import { Icon } from './Icon';

type EmptyStateProps = { icon: string; message: string };

export function EmptyState({ icon, message }: EmptyStateProps) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-outline-variant bg-surface-container-low p-8 text-center">
      <Icon name={icon} className="text-4xl text-primary" filled />
      <p className="text-label-md font-semibold text-on-surface-variant">{message}</p>
    </div>
  );
}
