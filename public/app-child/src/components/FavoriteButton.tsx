import { Icon } from './Icon';

type FavoriteButtonProps = { active?: boolean; onToggle?: () => void };

/** Coração toggle (Sprint 1: visual, sem wire aos dados). */
export function FavoriteButton({ active = false, onToggle }: FavoriteButtonProps) {
  return (
    <button
      type="button"
      onClick={onToggle}
      aria-label={active ? 'Desfavoritar' : 'Favoritar'}
      className="rounded-full p-2 text-on-surface-variant transition-colors hover:bg-surface-variant/50"
    >
      <Icon name="favorite" className={active ? 'text-error' : ''} filled={active} />
    </button>
  );
}
