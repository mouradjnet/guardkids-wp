import type { Recommendation } from '../api/types';
import { Icon } from './Icon';

type RecommendationCardProps = { recommendation: Recommendation };

/** Placeholder de recomendação dos pais (Sprint 1: sem dados). */
export function RecommendationCard({ recommendation }: RecommendationCardProps) {
  return (
    <div className="glass-panel flex items-center gap-3 rounded-2xl border border-primary/20 bg-primary/5 p-3 shadow-ambient">
      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
        <Icon name="recommend" className="text-xl" filled />
      </div>
      <div className="flex-1">
        <div className="text-label-md font-semibold text-on-surface">Indicado pelos pais</div>
        {recommendation.note && (
          <div className="text-label-sm text-on-surface-variant">{recommendation.note}</div>
        )}
      </div>
    </div>
  );
}
