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

export type SiteListType = 'whitelist' | 'blacklist';

export type Site = {
  id: number;
  domain: string;
  category: string | null;
  listType: SiteListType;
  appliesTo: number[];
  createdAt: string | null;
};

export type Category = {
  id: number;
  slug: string;
  name: string;
  description: string | null;
  icon: string | null;
  blocked: boolean;
};
