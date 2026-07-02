export type Child = {
  id: string;
  name: string;
  avatar: string;
  age: number;
  status: 'online' | 'offline';
  device: string;
  activity: { icon: string; label: string; current: boolean };
  usedMinutes: number;
  limitMinutes: number;
  sitesVisitedToday: number;
};

export type PendingRequest = {
  id: string;
  childName: string;
  childAvatar: string;
  kind: 'extra-time' | 'site';
  description: string;
  highlight: string;
  accent: 'primary' | 'tertiary';
  requestedAtLabel: string;
};

export type ApprovalHistory = {
  id: string;
  childName: string;
  childAvatar: string;
  summary: string;
  detail: string;
  decision: 'approved' | 'denied';
  decidedAtLabel: string;
};

export type SiteRule = {
  id: string;
  domain: string;
  category: string;
  list: 'whitelist' | 'blacklist';
  appliesTo: string[];
};

export type Category = {
  id: string;
  name: string;
  description: string;
  icon: string;
  blocked: boolean;
};

export type PageId =
  | 'dashboard'
  | 'children'
  | 'location'
  | 'safe-zones'
  | 'approvals'
  | 'sites-rules'
  | 'content'
  | 'time'
  | 'reports'
  | 'settings'
  | 'protection'
  | 'license'
  | 'upgrade';

export const children: Child[] = [
  {
    id: 'lucas',
    name: 'Lucas',
    avatar:
      'https://images.unsplash.com/photo-1502323777036-f29e3972d82f?w=160&h=160&fit=crop&crop=faces',
    age: 9,
    status: 'online',
    device: 'Tablet do Lucas',
    activity: { icon: 'stadia_controller', label: 'Playing Roblox', current: true },
    usedMinutes: 135,
    limitMinutes: 180,
    sitesVisitedToday: 12,
  },
  {
    id: 'sofia',
    name: 'Sofia',
    avatar:
      'https://images.unsplash.com/photo-1535268647677-300dbf3d78d1?w=160&h=160&fit=crop&crop=faces',
    age: 11,
    status: 'offline',
    device: 'Notebook compartilhado',
    activity: { icon: 'school', label: 'Khan Academy', current: false },
    usedMinutes: 45,
    limitMinutes: 150,
    sitesVisitedToday: 8,
  },
  {
    id: 'theo',
    name: 'Théo',
    avatar:
      'https://images.unsplash.com/photo-1518806118471-f28b20a1d79d?w=160&h=160&fit=crop&crop=faces',
    age: 6,
    status: 'offline',
    device: 'Tablet do Théo',
    activity: { icon: 'palette', label: 'YouTube Kids — Arte', current: false },
    usedMinutes: 25,
    limitMinutes: 60,
    sitesVisitedToday: 3,
  },
];

export const pendingRequests: PendingRequest[] = [
  {
    id: 'r1',
    childName: 'Lucas',
    childAvatar: children[0].avatar,
    kind: 'extra-time',
    description: 'Solicita',
    highlight: '+30 min para Roblox',
    accent: 'tertiary',
    requestedAtLabel: 'há 3 min',
  },
  {
    id: 'r2',
    childName: 'Sofia',
    childAvatar: children[1].avatar,
    kind: 'site',
    description: 'Quer acessar',
    highlight: 'coolmathgames.com',
    accent: 'primary',
    requestedAtLabel: 'há 12 min',
  },
];

export const approvalHistory: ApprovalHistory[] = [
  {
    id: 'h1',
    childName: 'Lucas',
    childAvatar: children[0].avatar,
    summary: 'Pediu +15 min para YouTube',
    detail: 'Sexta às 19:42 • Limite diário ultrapassado',
    decision: 'denied',
    decidedAtLabel: 'há 2 dias',
  },
  {
    id: 'h2',
    childName: 'Sofia',
    childAvatar: children[1].avatar,
    summary: 'Quis acessar duolingo.com',
    detail: 'Pedido com motivo "tarefa de inglês"',
    decision: 'approved',
    decidedAtLabel: 'há 3 dias',
  },
  {
    id: 'h3',
    childName: 'Théo',
    childAvatar: children[2].avatar,
    summary: 'Solicitou +10 min Play Time',
    detail: 'Aprovado direto pelo painel infantil',
    decision: 'approved',
    decidedAtLabel: 'há 4 dias',
  },
];

export const siteRules: SiteRule[] = [
  {
    id: 's1',
    domain: 'khanacademy.org',
    category: 'Educacional',
    list: 'whitelist',
    appliesTo: ['Lucas', 'Sofia', 'Théo'],
  },
  {
    id: 's2',
    domain: 'duolingo.com',
    category: 'Educacional',
    list: 'whitelist',
    appliesTo: ['Sofia'],
  },
  {
    id: 's3',
    domain: 'youtubekids.com',
    category: 'Vídeos',
    list: 'whitelist',
    appliesTo: ['Théo'],
  },
  {
    id: 's4',
    domain: 'roblox.com',
    category: 'Jogos',
    list: 'whitelist',
    appliesTo: ['Lucas'],
  },
  {
    id: 's5',
    domain: 'tiktok.com',
    category: 'Redes sociais',
    list: 'blacklist',
    appliesTo: ['Lucas', 'Sofia', 'Théo'],
  },
  {
    id: 's6',
    domain: 'youtube.com',
    category: 'Vídeos',
    list: 'blacklist',
    appliesTo: ['Lucas', 'Sofia'],
  },
  {
    id: 's7',
    domain: 'discord.com',
    category: 'Mensagens',
    list: 'blacklist',
    appliesTo: ['Lucas', 'Sofia', 'Théo'],
  },
];

export const categories: Category[] = [
  {
    id: 'c1',
    name: 'Conteúdo adulto',
    description: 'Bloqueia todo conteúdo +18, sempre.',
    icon: 'no_adult_content',
    blocked: true,
  },
  {
    id: 'c2',
    name: 'Apostas e cassino',
    description: 'Sites de jogos de azar e cassinos online.',
    icon: 'casino',
    blocked: true,
  },
  {
    id: 'c3',
    name: 'Violência extrema',
    description: 'Conteúdo gráfico, gore e similares.',
    icon: 'gpp_bad',
    blocked: true,
  },
  {
    id: 'c4',
    name: 'Redes sociais',
    description: 'TikTok, Instagram, Twitter, Snapchat.',
    icon: 'group',
    blocked: true,
  },
  {
    id: 'c5',
    name: 'Vídeos (geral)',
    description: 'YouTube e plataformas similares sem filtro.',
    icon: 'smart_display',
    blocked: false,
  },
  {
    id: 'c6',
    name: 'Jogos online',
    description: 'Plataformas multiplayer com chat aberto.',
    icon: 'sports_esports',
    blocked: false,
  },
];

export const navItems = [
  { id: 'dashboard' as PageId, label: 'Painel', icon: 'dashboard' },
  { id: 'children' as PageId, label: 'Filhos', icon: 'family_restroom' },
  { id: 'location' as PageId, label: 'Localização', icon: 'location_on' },
  { id: 'safe-zones' as PageId, label: 'Zonas Seguras', icon: 'shield' },
  { id: 'approvals' as PageId, label: 'Aprovações', icon: 'task_alt', badge: 2 },
  { id: 'sites-rules' as PageId, label: 'Sites & Regras', icon: 'app_blocking' },
  { id: 'content' as PageId, label: 'Conteúdo Infantil', icon: 'auto_stories' },
  { id: 'time' as PageId, label: 'Limites de Tempo', icon: 'timer' },
  { id: 'reports' as PageId, label: 'Relatórios', icon: 'monitoring' },
  { id: 'settings' as PageId, label: 'Configurações', icon: 'settings' },
  { id: 'protection' as PageId, label: 'Modo de Proteção', icon: 'shield_lock' },
  { id: 'license' as PageId, label: 'Licença', icon: 'key' },
  { id: 'upgrade' as PageId, label: 'Upgrade Premium', icon: 'workspace_premium' },
] as const;

export type WeekDay = 'seg' | 'ter' | 'qua' | 'qui' | 'sex' | 'sab' | 'dom';

export const weekDays: { id: WeekDay; label: string }[] = [
  { id: 'seg', label: 'Seg' },
  { id: 'ter', label: 'Ter' },
  { id: 'qua', label: 'Qua' },
  { id: 'qui', label: 'Qui' },
  { id: 'sex', label: 'Sex' },
  { id: 'sab', label: 'Sáb' },
  { id: 'dom', label: 'Dom' },
];

export type ChildLimits = {
  childId: string;
  dailyMinutes: number;
  weekdayMinutes: number;
  weekendMinutes: number;
  enabledDays: WeekDay[];
  bedtimeStart: string;
  bedtimeEnd: string;
};

export const childLimits: ChildLimits[] = [
  {
    childId: 'lucas',
    dailyMinutes: 180,
    weekdayMinutes: 120,
    weekendMinutes: 240,
    enabledDays: ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'],
    bedtimeStart: '21:00',
    bedtimeEnd: '07:00',
  },
  {
    childId: 'sofia',
    dailyMinutes: 150,
    weekdayMinutes: 90,
    weekendMinutes: 210,
    enabledDays: ['seg', 'ter', 'qua', 'qui', 'sex'],
    bedtimeStart: '21:30',
    bedtimeEnd: '07:00',
  },
  {
    childId: 'theo',
    dailyMinutes: 60,
    weekdayMinutes: 60,
    weekendMinutes: 90,
    enabledDays: ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'],
    bedtimeStart: '19:30',
    bedtimeEnd: '07:00',
  },
];

export type DayBlock = {
  start: number;
  end: number;
  kind: 'sleep' | 'school' | 'free' | 'play';
  label: string;
};

export const sampleDayBlocks: DayBlock[] = [
  { start: 0, end: 7, kind: 'sleep', label: 'Dormindo' },
  { start: 7, end: 8, kind: 'free', label: 'Manhã livre' },
  { start: 8, end: 15, kind: 'school', label: 'School Time' },
  { start: 15, end: 16, kind: 'free', label: 'Lanche' },
  { start: 16, end: 17, kind: 'play', label: 'Play Time' },
  { start: 17, end: 21, kind: 'free', label: 'Livre' },
  { start: 21, end: 24, kind: 'sleep', label: 'Bedtime' },
];

export type Guardian = {
  id: string;
  name: string;
  email: string;
  role: 'admin' | 'collaborator';
  pendingInvite?: boolean;
};

export const guardians: Guardian[] = [
  {
    id: 'g1',
    name: 'Você (Djair)',
    email: 'djair@familia.com',
    role: 'admin',
  },
  {
    id: 'g2',
    name: 'Marina',
    email: 'marina@familia.com',
    role: 'collaborator',
  },
  {
    id: 'g3',
    name: 'Vovó Ana',
    email: 'ana@familia.com',
    role: 'collaborator',
    pendingInvite: true,
  },
];

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
