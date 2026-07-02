export type PageId = 'home' | 'browser' | 'requests' | 'blocked' | 'alerts' | 'location';

export const child = {
  name: 'Lucas',
  greeting: 'Boa tarde',
  avatar:
    'https://images.unsplash.com/photo-1502323777036-f29e3972d82f?w=240&h=240&fit=crop&crop=faces',
  remainingMinutes: 45,
  totalMinutes: 60,
};

export type ScheduleStatus = 'active' | 'upcoming' | 'later';

export type ScheduleItem = {
  id: string;
  title: string;
  icon: string;
  time: string;
  status: ScheduleStatus;
  note?: string;
};

export const schedule: ScheduleItem[] = [
  {
    id: 'school',
    title: 'School Time',
    icon: 'school',
    time: '8:00 - 15:00',
    status: 'active',
    note: 'ATIVO AGORA',
  },
  {
    id: 'play',
    title: 'Play Time',
    icon: 'sports_esports',
    time: '16:00 - 17:00',
    status: 'upcoming',
    note: 'Começa em 1h',
  },
  {
    id: 'bedtime',
    title: 'Bedtime',
    icon: 'bedtime',
    time: '21:00 - 7:00',
    status: 'later',
  },
];

export type MyRequestStatus = 'pending' | 'approved' | 'denied';

export type MyRequest = {
  id: string;
  title: string;
  detail: string;
  status: MyRequestStatus;
  whenLabel: string;
};

export const myRequests: MyRequest[] = [
  {
    id: 'm1',
    title: '+30 min para Roblox',
    detail: 'Hoje, 13:42',
    status: 'approved',
    whenLabel: 'há 3 min',
  },
  {
    id: 'm2',
    title: 'Liberar coolmathgames.com',
    detail: 'Para a tarefa de matemática',
    status: 'pending',
    whenLabel: 'há 12 min',
  },
  {
    id: 'm3',
    title: '+15 min para YouTube',
    detail: 'Sexta, 19:42',
    status: 'denied',
    whenLabel: 'há 2 dias',
  },
];

export const blockedInfo = {
  reason: 'Bedtime' as const,
  message: 'A hora de dormir começou. Descansa que amanhã tem mais!',
  unlockInSeconds: 36000,
  alternatives: [
    { id: 'a1', icon: 'menu_book', label: 'Ler um livro' },
    { id: 'a2', icon: 'extension', label: 'Montar quebra-cabeça' },
    { id: 'a3', icon: 'bedtime', label: 'Descansar os olhos' },
  ],
};
