import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getMyRedemptions, getStore, redeem } from '../api/rewards';
import type { PageId } from '../data/mockData';
import { Icon } from '../components/Icon';

const STATUS_LABEL: Record<string, string> = {
  pending: 'Pendente',
  approved: 'Aprovado',
  denied: 'Negado',
};

export function Loja({ onNavigate }: { onNavigate: (page: PageId) => void }) {
  const qc = useQueryClient();
  const storeQuery = useQuery({ queryKey: ['child', 'store'], queryFn: getStore });
  const mine = useQuery({ queryKey: ['child', 'redemptions'], queryFn: getMyRedemptions });

  const redeemMut = useMutation({
    mutationFn: (rewardId: number) => redeem(rewardId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['child', 'redemptions'] });
      qc.invalidateQueries({ queryKey: ['child', 'store'] });
    },
  });

  const balance = storeQuery.data?.balance ?? 0;
  const rewards = storeQuery.data?.rewards ?? [];
  const redemptions = mine.data ?? [];
  const pendingRewardIds = new Set(
    redemptions.filter((r) => r.status === 'pending').map((r) => r.rewardId),
  );

  return (
    <main className="flex flex-1 flex-col gap-stack-lg px-container-padding-mobile py-stack-md">
      <button
        type="button"
        onClick={() => onNavigate('home')}
        className="flex items-center gap-1 self-start text-label-sm text-on-surface-variant"
      >
        <Icon name="arrow_back" className="text-base" /> Voltar
      </button>

      <div className="flex items-center justify-between rounded-2xl bg-primary p-4 text-white shadow-sm">
        <span className="font-display text-title-md font-bold">Loja de Recompensas</span>
        <span className="flex items-center gap-1 text-title-md font-bold">
          <Icon name="paid" className="text-xl" filled /> {balance}
        </span>
      </div>

      <ul className="space-y-3">
        {rewards.map((r) => {
          const canAfford = balance >= r.costCoins;
          const alreadyPending = pendingRewardIds.has(r.id);
          const disabled = !canAfford || alreadyPending || redeemMut.isPending;
          return (
            <li
              key={r.id}
              className="flex items-center gap-3 rounded-2xl bg-surface-container p-4 shadow-sm"
            >
              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-surface-variant text-on-surface-variant">
                <Icon name={r.icon ?? 'card_giftcard'} className="text-xl" filled />
              </div>
              <div className="min-w-0 flex-1">
                <div className="text-label-md text-on-surface">{r.title}</div>
                <div className="flex items-center gap-1 text-label-sm text-orange-500">
                  <Icon name="paid" className="text-sm" filled /> {r.costCoins}
                </div>
              </div>
              <button
                type="button"
                disabled={disabled}
                onClick={() => redeemMut.mutate(r.id)}
                className="shrink-0 rounded-xl bg-primary px-4 py-2 text-label-md font-semibold text-white disabled:opacity-40"
              >
                {alreadyPending ? 'Pedido enviado' : 'Resgatar'}
              </button>
            </li>
          );
        })}
      </ul>

      <div>
        <h3 className="mb-2 font-display text-label-md font-bold text-on-surface">Meus resgates</h3>
        {redemptions.length === 0 ? (
          <p className="text-label-sm text-on-surface-variant">Você ainda não resgatou nada.</p>
        ) : (
          <ul className="space-y-2">
            {redemptions.map((r) => (
              <li
                key={r.id}
                className="flex items-center justify-between rounded-xl bg-surface-container-low p-3"
              >
                <span className="text-label-md text-on-surface">{r.title}</span>
                <span className="text-label-sm font-semibold text-on-surface-variant">
                  {STATUS_LABEL[r.status] ?? r.status}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </main>
  );
}
