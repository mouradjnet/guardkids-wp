// Catálogo de planos exibido no comparativo Free × Premium da página de Upgrade.
// É cópia de marketing (labels e faixas), não dado mockado — as linhas de feature
// premium devem refletir o que o backend realmente trava em
// `GuardKids\License\Gate::PREMIUM_FEATURES` (espelhado no client em
// `hooks/useLicense.ts` PREMIUM_FEATURES).
export const planFeatures = [
  { id: 'kids', label: 'Filhos cadastrados', free: '1 filho', premium: 'Filhos ilimitados' },
  { id: 'blacklist', label: 'Blacklist manual', free: true, premium: true },
  { id: 'time', label: 'Limite diário básico', free: true, premium: true },
  { id: 'browser', label: 'Navegador infantil seguro', free: false, premium: true },
  { id: 'categories', label: 'Categorias inteligentes', free: false, premium: true },
  { id: 'schedule', label: 'Rotina escolar', free: false, premium: true },
  { id: 'location', label: 'Localização e Zonas Seguras', free: false, premium: true },
  { id: 'reports', label: 'Relatórios completos', free: false, premium: true },
  { id: 'notifications', label: 'Notificações push avançadas', free: false, premium: true },
  { id: 'guardians', label: 'Múltiplos responsáveis', free: false, premium: true },
  { id: 'history', label: 'Histórico completo', free: '7 dias', premium: 'Ilimitado' },
] as const;
