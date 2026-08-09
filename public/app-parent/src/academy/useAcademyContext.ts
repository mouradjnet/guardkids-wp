import { useQuery } from '@tanstack/react-query';

import { getAcademyProgress } from '../api/academy';
import { listChildren } from '../api/children';
import { listSites } from '../api/sites';
import type { PageId } from '../data/mockData';
import type { AcademyContext } from './engine';

// Monta o AcademyContext a partir das queries que o app JÁ carrega — reusa as
// mesmas queryKeys (`['children']`, `['sites','all']`) pra compartilhar o cache
// do TanStack e não disparar fetch extra. O engine é puro; este hook é a única
// ponte com o estado real.
//
// Sinais derivados sem tabela nova:
//   childrenCount   -> quantos filhos
//   hasPairedDevice -> algum filho com aparelho pareado  (Child.paired)
//   hasSiteRules    -> existe ao menos uma regra de site
//   hasTimeLimits   -> algum filho com limite diário ou hora de dormir ativos
export function useAcademyContext(screen: PageId): AcademyContext {
  const childrenQuery = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const sitesQuery = useQuery({ queryKey: ['sites', 'all'], queryFn: () => listSites('all') });
  const progressQuery = useQuery({ queryKey: ['academy', 'progress'], queryFn: getAcademyProgress });

  const children = childrenQuery.data ?? [];
  const sites = sitesQuery.data ?? [];
  const progress = progressQuery.data ?? { completed: [], dismissed: [] };

  return {
    screen,
    family: {
      childrenCount: children.length,
      hasPairedDevice: children.some((child) => child.paired),
      hasSiteRules: sites.length > 0,
      hasTimeLimits: children.some((child) => child.dailyLimitEnabled || child.bedtimeEnabled),
    },
    completedLessonIds: progress.completed,
    dismissed: progress.dismissed,
  };
}
