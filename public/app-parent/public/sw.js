/**
 * Service worker do painel dos pais — SÓ Web Push.
 *
 * Diferente do app-child, aqui não há precache/Workbox: o SW não precisa
 * controlar /painel-pais/ pra receber push. `pushManager.subscribe()` roda
 * sobre qualquer registro, o evento `push` chega sem página aberta, e
 * `notificationclick` abre a janela sozinho.
 *
 * Ele é registrado a partir do plugins_url e tem o scope do próprio diretório
 * dist/ — que NÃO cobre /painel-pais/, e não precisa cobrir.
 */

/**
 * Avisa as abas abertas do painel-pais que chegou push.
 *
 * A notificação entrega o AVISO ao guardião; isto entrega o DADO à tela que ele
 * já está olhando — sem F5 e sem pendurar um refetchInterval na página. Funciona
 * mesmo com o SW não controlando /painel-pais/ (scope do dist/): postMessage a
 * partir do SW não exige controle, só que o client seja da mesma origem — a
 * mesma razão pela qual o notificationclick abaixo já consegue focar a janela.
 */
async function notifyOpenClients() {
  const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  for (const client of clients) {
    if (client.url.includes('/painel-pais')) {
      client.postMessage({ type: 'guardkids:push' });
    }
  }
}

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { body: event.data ? event.data.text() : '' };
  }

  event.waitUntil(
    Promise.all([
      self.registration.showNotification(data.title || 'GuardKids', {
        body: data.body || '',
        // Relativo: resolve contra o scope do SW (o próprio dist/). Hardcodar
        // /wp-content/... quebraria se a pasta do plugin mudasse de nome.
        icon: 'pwa-192x192.png',
        badge: 'pwa-64x64.png',
        tag: data.tag,
        data: { url: data.url || '/painel-pais/' },
      }),
      notifyOpenClients(),
    ]),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/painel-pais/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if (client.url.includes('/painel-pais') && 'focus' in client) {
          return client.focus();
        }
      }
      return self.clients.openWindow(url);
    }),
  );
});
