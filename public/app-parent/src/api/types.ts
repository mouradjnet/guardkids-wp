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
  createdAt: string | null;
  updatedAt: string | null;
};

export type ApprovalRequestStatus = 'pending' | 'approved' | 'denied';

export type ApprovalRequest = {
  id: number;
  childId: number;
  kind: string;
  description: string | null;
  highlight: string | null;
  reason: string | null;
  status: ApprovalRequestStatus;
  decidedAt: string | null;
  decidedBy: number | null;
  createdAt: string | null;
};
