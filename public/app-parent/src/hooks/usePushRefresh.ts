import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';

/**
 * Ponte service worker → tela aberta.
 *
 * O SW posta `guardkids:push` quando chega um push (filho criou um pedido).
 * Aqui a aba recarrega os pedidos na hora, em vez de o guardião ter que dar F5
 * — e sem refetchInterval, que consultaria o servidor o dia todo pra nada.
 *
 * invalidateQueries só refaz fetch do que está MONTADO: se o guardião está em
 * outra página, a chave só fica marcada como velha e recarrega quando ele
 * abrir Aprovações.
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
      void queryClient.invalidateQueries({ queryKey: ['requests'] });
    };

    sw.addEventListener('message', onMessage);
    return () => sw.removeEventListener('message', onMessage);
  }, [queryClient]);
}
