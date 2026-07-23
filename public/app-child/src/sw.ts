/// <reference lib="webworker" />
import { precacheAndRoute } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst } from 'workbox-strategies';

declare const self: ServiceWorkerGlobalScope;

precacheAndRoute(self.__WB_MANIFEST);

// Google Fonts em cache-first (espelha o comportamento anterior).
registerRoute(
  ({ url }) =>
    url.origin === 'https://fonts.googleapis.com' || url.origin === 'https://fonts.gstatic.com',
  new CacheFirst({ cacheName: 'gfonts' }),
);

/**
 * Avisa as abas abertas do painel-filho que chegou push.
 *
 * A notificação entrega o AVISO ao usuário; isto entrega o DADO à tela que ele
 * já está olhando — sem F5 e sem pendurar um refetchInterval em cada página.
 * Filtra pela URL igual ao notificationclick: matchAll devolve todo client da
 * origem, inclusive abas do painel-pais, que não têm nada a ver com este SW.
 */
async function notifyOpenClients(): Promise<void> {
  const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  for (const client of clients) {
    if (client.url.includes('/painel-filho')) {
      client.postMessage({ type: 'guardkids:push' });
    }
  }
}

self.addEventListener('push', (event: PushEvent) => {
  let data: { title?: string; body?: string; url?: string; tag?: string } = {};
  try {
    data = event.data?.json() ?? {};
  } catch {
    data = { body: event.data?.text() };
  }
  event.waitUntil(
    Promise.all([
      self.registration.showNotification(data.title ?? 'GuardKids', {
        body: data.body ?? '',
        icon: '/painel-filho/pwa-192x192.png',
        badge: '/painel-filho/pwa-64x64.png',
        tag: data.tag,
        data: { url: data.url ?? '/painel-filho/' },
      }),
      notifyOpenClients(),
    ]),
  );
});

self.addEventListener('notificationclick', (event: NotificationEvent) => {
  event.notification.close();
  const url = (event.notification.data?.url as string) ?? '/painel-filho/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if (client.url.includes('/painel-filho') && 'focus' in client) {
          return client.focus();
        }
      }
      return self.clients.openWindow(url);
    }),
  );
});
