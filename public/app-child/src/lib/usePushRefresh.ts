import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';

/**
 * Ponte service worker → tela aberta.
 *
 * O SW posta `guardkids:push` quando chega um push (o pai decidiu um pedido).
 * Aqui a aba recarrega na hora as três coisas que a decisão muda: o status do
 * pedido, o feed de alertas e o contador de não-lidas do menu — em vez de a
 * criança ter que dar F5.
 *
 * invalidateQueries só refaz fetch do que está MONTADO; as chaves das páginas
 * fechadas apenas ficam velhas e recarregam quando ela abrir.
 */
export function usePushRefresh(): void {
  const queryClient = useQueryClient();

  useEffect(() => {
    // Guardar a referência: o cleanup não pode reler navigator.serviceWorker,
    // que pode não existir mais na hora de desmontar.
    const sw = navigator.serviceWorker;
    if (!sw) return;

    const onMessage = (event: MessageEvent) => {
      if (event.data?.type !== 'guardkids:push') return;
      void queryClient.invalidateQueries({ queryKey: ['child', 'requests'] });
      void queryClient.invalidateQueries({ queryKey: ['child', 'notifications'] });
      void queryClient.invalidateQueries({ queryKey: ['child', 'me'] });
    };

    sw.addEventListener('message', onMessage);
    return () => sw.removeEventListener('message', onMessage);
  }, [queryClient]);
}
