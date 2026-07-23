import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { usePushRefresh } from './usePushRefresh';

/**
 * jsdom não tem navigator.serviceWorker. Um EventTarget cru basta: o hook só
 * precisa de addEventListener/removeEventListener pra escutar o SW.
 */
function stubServiceWorker(): EventTarget {
  const target = new EventTarget();
  vi.stubGlobal('navigator', {
    serviceWorker: {
      addEventListener: target.addEventListener.bind(target),
      removeEventListener: target.removeEventListener.bind(target),
    },
  });
  return target;
}

function setup() {
  const target = stubServiceWorker();
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  const invalidate = vi.spyOn(client, 'invalidateQueries').mockResolvedValue(undefined);
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  const view = renderHook(() => usePushRefresh(), { wrapper });
  return { target, invalidate, view };
}

describe('usePushRefresh (painel dos pais)', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('recarrega os pedidos quando o SW avisa que chegou push', () => {
    const { target, invalidate } = setup();

    target.dispatchEvent(new MessageEvent('message', { data: { type: 'guardkids:push' } }));

    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['requests'] });
  });

  it('ignora mensagem de outro remetente', () => {
    const { target, invalidate } = setup();

    target.dispatchEvent(new MessageEvent('message', { data: { type: 'workbox-waiting' } }));

    expect(invalidate).not.toHaveBeenCalled();
  });

  it('para de escutar ao desmontar', () => {
    const { target, invalidate, view } = setup();

    view.unmount();
    target.dispatchEvent(new MessageEvent('message', { data: { type: 'guardkids:push' } }));

    expect(invalidate).not.toHaveBeenCalled();
  });
});
