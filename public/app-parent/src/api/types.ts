export type Child = {
  id: number;
  slug: string;
  name: string;
  age: number | null;
  avatarUrl: string | null;
  device: string | null;
  paired: boolean;
  status: 'online' | 'offline' | 'paused';
  usedMinutes: number;
  limitMinutes: number;
  dailyLimitEnabled: boolean;
  bedtimeEnabled: boolean;
  bedtimeStart: string | null;
  bedtimeEnd: string | null;
  allowedWeekdays: string;
  createdAt: string | null;
  updatedAt: string | null;
  // Último heartbeat do app-child (ISO UTC) ou null se nunca. Base do "online":
  // heartbeat recente = app aberto agora. Ver isChildOnline() em lib/online.ts.
  // Opcional no tipo só pra não forçar todos os fixtures de teste a declarar.
  lastSeenAt?: string | null;
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

export type LocationFix = {
  id: number;
  childId: number;
  latitude: number;
  longitude: number;
  accuracy: number | null;
  battery: number | null;
  recordedAt: string;
};

export type GuardianRole = 'admin' | 'collaborator';
export type GuardianStatus = 'active' | 'pending';

export type Guardian = {
  id: number;
  wpUserId: number | null;
  name: string;
  email: string;
  role: GuardianRole;
  status: GuardianStatus;
  invitePending: boolean;
  inviteExpiresAt: string | null;
  createdAt: string | null;
  updatedAt: string | null;
};

/** Response do create / resend, com link gerado pra admin copiar (one-time). */
export type GuardianWithInvite = Guardian & {
  inviteUrl: string;
  inviteToken: string;
};

export type SafeZone = {
  id: number;
  name: string;
  address: string | null;
  latitude: number;
  longitude: number;
  radiusMeters: number;
  createdAt: string | null;
  updatedAt: string | null;
};
