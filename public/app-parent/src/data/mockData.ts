export type PageId =
  | 'dashboard'
  | 'children'
  | 'location'
  | 'safe-zones'
  | 'approvals'
  | 'sites-rules'
  | 'content'
  | 'gamification'
  | 'rewards'
  | 'time'
  | 'reports'
  | 'settings'
  | 'protection'
  | 'license'
  | 'upgrade';

export const navItems = [
  { id: 'dashboard' as PageId, label: 'Painel', icon: 'dashboard' },
  { id: 'children' as PageId, label: 'Filhos', icon: 'family_restroom' },
  { id: 'location' as PageId, label: 'Localização', icon: 'location_on' },
  { id: 'safe-zones' as PageId, label: 'Zonas Seguras', icon: 'shield' },
  // sem `badge` aqui: a contagem vem do servidor, na SideNav
  { id: 'approvals' as PageId, label: 'Aprovações', icon: 'task_alt' },
  { id: 'sites-rules' as PageId, label: 'Sites & Regras', icon: 'app_blocking' },
  { id: 'content' as PageId, label: 'Conteúdo Infantil', icon: 'auto_stories' },
  { id: 'gamification' as PageId, label: 'Gamificação', icon: 'stadia_controller' },
  { id: 'rewards' as PageId, label: 'Recompensas', icon: 'card_giftcard' },
  { id: 'time' as PageId, label: 'Limites de Tempo', icon: 'timer' },
  { id: 'reports' as PageId, label: 'Relatórios', icon: 'monitoring' },
  { id: 'settings' as PageId, label: 'Configurações', icon: 'settings' },
  { id: 'protection' as PageId, label: 'Modo de Proteção', icon: 'shield_lock' },
  { id: 'license' as PageId, label: 'Licença', icon: 'key' },
  { id: 'upgrade' as PageId, label: 'Upgrade Premium', icon: 'workspace_premium' },
] as const;

export const planFeatures = [
  { id: 'kids', label: 'Filhos cadastrados', free: '1 filho', premium: 'Filhos ilimitados' },
  { id: 'blacklist', label: 'Blacklist manual', free: true, premium: true },
  { id: 'time', label: 'Limite diário básico', free: true, premium: true },
  { id: 'browser', label: 'Navegador infantil seguro', free: false, premium: true },
  { id: 'categories', label: 'Categorias inteligentes', free: false, premium: true },
  { id: 'schedule', label: 'Rotina escolar', free: false, premium: true },
  { id: 'reports', label: 'Relatórios completos', free: false, premium: true },
  { id: 'notifications', label: 'Notificações push avançadas', free: false, premium: true },
  { id: 'guardians', label: 'Múltiplos responsáveis', free: false, premium: true },
  { id: 'history', label: 'Histórico completo', free: '7 dias', premium: 'Ilimitado' },
] as const;
