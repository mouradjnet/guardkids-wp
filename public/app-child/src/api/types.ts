export type ScheduleReason = 'bedtime' | 'weekday';

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
  schedule?: ChildSchedule;
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

export type CreateRequestInput = {
  kind: 'extra_time' | 'unblock_site' | 'other';
  description?: string;
  highlight?: string;
  reason?: string;
};
