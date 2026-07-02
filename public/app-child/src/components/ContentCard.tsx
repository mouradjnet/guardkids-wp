import type { Content } from '../api/types';
import { Icon } from './Icon';

type ContentCardProps = { content: Content };

/** Placeholder de item de conteúdo (Sprint 1: sem dados). */
export function ContentCard({ content }: ContentCardProps) {
  return (
    <div className="glass-panel flex items-center gap-3 rounded-2xl p-3 shadow-ambient">
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary-container text-on-secondary-container">
        <Icon name="play_circle" className="text-xl" filled />
      </div>
      <div className="flex-1">
        <div className="text-label-md font-semibold text-on-surface">{content.title}</div>
        {content.description && (
          <div className="text-label-sm text-on-surface-variant">{content.description}</div>
        )}
      </div>
    </div>
  );
}
