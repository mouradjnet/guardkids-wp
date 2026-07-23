import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { listMyRequests } from '../api/child';
import type { MyRequest } from '../api/types';

/** Quanto o card fica na tela antes de sumir sozinho. */
export const ALERT_TIMEOUT_MS = 15_000;

/**
 * Avisa na própria tela quando o pai responde um pedido.
 *
 * Espelha o useNewRequestAlert do painel: a notificação do sistema depende de
 * push, que depende de permissão concedida NAQUELE aparelho — no aparelho da
 * criança quase nunca está. Este card observa a lista que a tela já busca
 * (push invalida em ~2s quando existe; senão o refetch de 60s traz).
 *
 * Alerta na TRANSIÇÃO pendente → decidido. A primeira carga só memoriza: um
 * pedido que já estava aprovado quando o app abriu não é novidade.
 */
export function useDecisionAlert(enabled: boolean): {
  alerta: MyRequest | null;
  dispensar: () => void;
} {
  const { data } = useQuery({
    queryKey: ['child', 'requests'],
    queryFn: listMyRequests,
    enabled,
    refetchInterval: 60_000,
  });

  const statusAnterior = useRef<Map<number, MyRequest['status']> | null>(null);
  const [alerta, setAlerta] = useState<MyRequest | null>(null);

  useEffect(() => {
    if (!data) return;

    if (statusAnterior.current === null) {
      statusAnterior.current = new Map(data.map((r) => [r.id, r.status]));
      return;
    }

    const decididos = data.filter(
      (r) => r.status !== 'pending' && statusAnterior.current!.get(r.id) === 'pending',
    );
    for (const r of data) statusAnterior.current.set(r.id, r.status);
    if (decididos.length > 0) {
      setAlerta(decididos[decididos.length - 1]);
    }
  }, [data]);

  useEffect(() => {
    if (!alerta) return;
    const id = window.setTimeout(() => setAlerta(null), ALERT_TIMEOUT_MS);
    return () => window.clearTimeout(id);
  }, [alerta]);

  return { alerta, dispensar: () => setAlerta(null) };
}
