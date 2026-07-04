import { apiFetch } from './client';

export type Avatar = {
  key: string;
  emoji: string;
  label: string;
  requirementLabel: string;
  unlocked: boolean;
  isEquipped: boolean;
};

export function getAvatars(): Promise<{ equipped: string; avatars: Avatar[] }> {
  return apiFetch<{ equipped: string; avatars: Avatar[] }>('/child/avatars');
}

export function equipAvatar(avatarKey: string): Promise<{ equipped: string }> {
  return apiFetch<{ equipped: string }>('/child/avatar', {
    method: 'POST',
    body: JSON.stringify({ avatarKey }),
  });
}
