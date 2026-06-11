import type { PageId } from '../data/mockData';
import type { CurrentRole } from '../api/me';

/**
 * Páginas que collaborator pode acessar. Bate com as rotas REST que viraram
 * `requireCollaboratorOrAbove` no backend (GET /children, GET/approve/deny
 * de /requests). Tudo que não está aqui exige role=admin.
 */
export const COLLAB_ALLOWED_PAGES: readonly PageId[] = ['dashboard', 'approvals'];

export function canAccessPage(role: CurrentRole, page: PageId): boolean {
  if (role === 'collaborator') {
    return COLLAB_ALLOWED_PAGES.includes(page);
  }
  // admin OU null (loading/anônimo): rota REST corta acesso real, UI fica permissiva
  return true;
}
