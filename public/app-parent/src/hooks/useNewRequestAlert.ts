import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { listRequests } from '../api/requests';
import type { ApprovalRequest } from '../api/types';

/** Quanto o card fica na tela antes de sumir sozinho. */
export const ALERT_TIMEOUT_MS = 15_000;

/**
 * Avisa na própria tela quando chega pedido novo.
 *
 * A notificação do sistema depende de push, que depende de permissão concedida
 * NAQUELE aparelho — e a maioria dos pais nunca concede. Este card não depende
 * de nada disso: observa a lista que a tela já busca (push invalida em ~2s
 * quando existe; senão o refetch de 60s traz).
 *
 * A primeira carga nunca alerta: pedidos que já estavam lá não são novidade,
 * senão todo F5 encheria a tela de cards de coisas velhas.
 */
export function useNewRequestAlert(): {
  alerta: ApprovalRequest | null;
  dispensar: () => void;
} {
  const { data } = useQuery({
    queryKey: ['requests', 'pending'],
    queryFn: () => listRequests('pending'),
    refetchInterval: 60_000,
  });

  const conhecidos = useRef<Set<number> | null>(null);
  const [alerta, setAlerta] = useState<ApprovalRequest | null>(null);

  useEffect(() => {
    if (!data) return;

    if (conhecidos.current === null) {
      conhecidos.current = new Set(data.map((r) => r.id));
      return;
    }

    const novos = data.filter((r) => !conhecidos.current!.has(r.id));
    for (const r of data) conhecidos.current.add(r.id);
    if (novos.length > 0) {
      // O mais recente ganha o card: dois pedidos na mesma janela é raro, e
      // empilhar cards atrapalharia mais do que ajuda.
      setAlerta(novos[novos.length - 1]);
    }
  }, [data]);

  useEffect(() => {
    if (!alerta) return;
    const id = window.setTimeout(() => setAlerta(null), ALERT_TIMEOUT_MS);
    return () => window.clearTimeout(id);
  }, [alerta]);

  return { alerta, dispensar: () => setAlerta(null) };
}
