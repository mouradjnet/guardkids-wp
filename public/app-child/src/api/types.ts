export type ScheduleReason = 'bedtime' | 'weekday' | 'limit';

export type ChildSchedule = {
  isBlocked: boolean;
  reason: ScheduleReason | null;
  unlockAt: string | null;
};

export type Child = {
  id: number;
  slug: string;
  name: string;
  age: number | null;
  avatarUrl: string | null;
  device: string | null;
  status: 'online' | 'offline';
  usedMinutes: number;
  limitMinutes: number;
  bedtimeEnabled?: boolean;
  bedtimeStart?: string | null;
  bedtimeEnd?: string | null;
  allowedWeekdays?: string;
  schedule?: ChildSchedule;
  /** PIN dos pais disponível pra destravar o ambiente seguro neste aparelho. */
  pinUnlockEnabled?: boolean;
  /** Quantidade de notificações não-lidas (alimenta o badge). */
  unreadNotifications?: number;
};

export type ContentCategory = {
  id: number;
  slug: string;
  name: string;
  icon: string | null;
  description: string | null;
};

export type Content = {
  id: number;
  categoryId: number | null;
  title: string;
  description: string | null;
  url: string | null;
  type: string;
  thumbnail: string | null;
};

export type Favorite = { id: number; childId: number; contentId: number; createdAt: string | null };

export type Recommendation = {
  id: number;
  childId: number;
  contentId: number;
  note: string | null;
  createdAt: string | null;
};

export type Notification = {
  id: number;
  type: string;
  title: string;
  body: string | null;
  read: boolean;
  createdAt: string | null;
};

export type MyRequestStatus = 'pending' | 'approved' | 'denied';

export type MyRequest = {
  id: number;
  childId: number;
  kind: string;
  description: string | null;
  highlight: string | null;
  reason: string | null;
  status: MyRequestStatus;
  decidedAt: string | null;
  createdAt: string | null;
};

export type AllowedSite = {
  domain: string;
  category: string | null;
};

export type CreateRequestInput = {
  kind: 'extra_time' | 'unblock_site' | 'other';
  description?: string;
  highlight?: string;
  reason?: string;
};
