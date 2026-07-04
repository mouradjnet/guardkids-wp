import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { equipAvatar, getAvatars } from '../api/avatars';
import type { PageId } from '../data/mockData';
import { Icon } from '../components/Icon';

export function Avatar({ onNavigate }: { onNavigate: (page: PageId) => void }) {
  const qc = useQueryClient();
  const query = useQuery({ queryKey: ['child', 'avatars'], queryFn: getAvatars });

  const equipMut = useMutation({
    mutationFn: (key: string) => equipAvatar(key),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['child', 'avatars'] });
      qc.invalidateQueries({ queryKey: ['child', 'me'] });
    },
  });

  const avatars = query.data?.avatars ?? [];

  return (
    <main className="flex flex-1 flex-col gap-stack-lg px-container-padding-mobile py-stack-md">
      <button
        type="button"
        onClick={() => onNavigate('home')}
        className="flex items-center gap-1 self-start text-label-sm text-on-surface-variant"
      >
        <Icon name="arrow_back" className="text-base" /> Voltar
      </button>

      <h2 className="font-display text-headline-md font-bold text-on-surface">Meu Avatar</h2>

      <ul className="grid grid-cols-3 gap-4">
        {avatars.map((a) => (
          <li key={a.key} className="flex flex-col items-center gap-1 text-center">
            <button
              type="button"
              data-testid={a.unlocked ? `avatar-option-${a.key}` : `avatar-locked-${a.key}`}
              disabled={!a.unlocked || equipMut.isPending}
              onClick={() => a.unlocked && equipMut.mutate(a.key)}
              className={`relative flex h-16 w-16 items-center justify-center rounded-full text-3xl ${
                a.isEquipped ? 'ring-4 ring-primary' : ''
              } ${a.unlocked ? 'bg-surface-container' : 'bg-surface-variant opacity-40'}`}
            >
              <span>{a.emoji}</span>
              {!a.unlocked && (
                <span className="absolute -bottom-1 -right-1 rounded-full bg-surface p-0.5">
                  <Icon name="lock" className="text-sm text-on-surface-variant" filled />
                </span>
              )}
            </button>
            <span className="text-label-sm text-on-surface">{a.label}</span>
            {!a.unlocked && (
              <span className="text-label-sm text-on-surface-variant">{a.requirementLabel}</span>
            )}
          </li>
        ))}
      </ul>
    </main>
  );
}
