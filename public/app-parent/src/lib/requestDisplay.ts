import type { Child, ApprovalRequest } from '../api/types';

export type RequestAccent = 'primary' | 'tertiary';

export function accentFor(kind: string): RequestAccent {
  return kind === 'unblock_site' || kind === 'site' ? 'tertiary' : 'primary';
}

export function formatRelative(iso: string | null): string {
  if (!iso) return '';
  const date = new Date(iso);
  const diffMs = Date.now() - date.getTime();
  if (Number.isNaN(diffMs)) return '';
  const diffMin = Math.floor(diffMs / 60_000);
  if (diffMin < 1) return 'agora';
  if (diffMin < 60) return `há ${diffMin} min`;
  const diffH = Math.floor(diffMin / 60);
  if (diffH < 24) return `há ${diffH}h`;
  const diffD = Math.floor(diffH / 24);
  if (diffD < 7) return `há ${diffD}d`;
  return date.toLocaleDateString('pt-BR');
}

export type ChildBadge = { name: string; avatarUrl: string | null };

export function childBadge(req: ApprovalRequest, children: Child[] | undefined): ChildBadge {
  const c = children?.find((x) => x.id === req.childId);
  return c
    ? { name: c.name, avatarUrl: c.avatarUrl }
    : { name: `Filho #${req.childId}`, avatarUrl: null };
}
