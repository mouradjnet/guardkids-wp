import { apiFetch } from '../api/client';

// `window.guardkidsApi` (incl. swUrl) é declarado em src/vite-env.d.ts.

export function isPushSupported(): boolean {
  return (
    typeof navigator !== 'undefined' &&
    'serviceWorker' in navigator &&
    typeof window !== 'undefined' &&
    'PushManager' in window &&
    typeof Notification !== 'undefined'
  );
}

export function getPermission(): NotificationPermission {
  return typeof Notification !== 'undefined' ? Notification.permission : 'denied';
}

function urlBase64ToUint8Array(base64: string): Uint8Array {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4);
  const b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(b64);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

/**
 * Registra o SW e espera ele ativar.
 *
 * NÃO usa `navigator.serviceWorker.ready`: ela resolve com o registro que
 * CONTROLA a página, e o nosso tem scope do dist/ (não de /painel-pais/).
 * Usar `ready` aqui travaria pra sempre, em silêncio — o app-child pode usá-la
 * porque o SW dele controla a página (precache do PWA).
 */
async function registerSw(): Promise<ServiceWorkerRegistration> {
  const swUrl = window.guardkidsApi?.swUrl;
  if (!swUrl) {
    throw new Error('Service worker não configurado.');
  }

  const reg = await navigator.serviceWorker.register(swUrl);
  if (reg.active) return reg;

  const worker = reg.installing ?? reg.waiting;
  if (worker) {
    await new Promise<void>((resolve, reject) => {
      worker.addEventListener('statechange', () => {
        if (worker.state === 'activated') resolve();
        // 'redundant' = instalacao falhou ou foi substituido. Sem este ramo a
        // promise nunca assenta e o subscribe pendura em silencio — a mesma
        // armadilha do serviceWorker.ready, um andar abaixo.
        if (worker.state === 'redundant') {
          reject(new Error('O service worker falhou ao instalar. Recarregue a página e tente de novo.'));
        }
      });
    });
  }
  return reg;
}

export async function subscribe(): Promise<void> {
  const { publicKey } = await apiFetch<{ publicKey: string }>('/guardian/push/key');

  if (Notification.permission !== 'granted') {
    const result = await Notification.requestPermission();
    if (result !== 'granted') {
      throw new Error('Permissão de notificação negada no navegador.');
    }
  }

  const registration = await registerSw();
  const sub = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(publicKey) as BufferSource,
  });

  const json = sub.toJSON() as { endpoint?: string; keys?: { p256dh?: string; auth?: string } };
  await apiFetch('/guardian/push/subscribe', {
    method: 'POST',
    body: JSON.stringify({
      endpoint: json.endpoint,
      keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
    }),
  });
}

export async function unsubscribe(): Promise<void> {
  const reg = await navigator.serviceWorker.getRegistration(window.guardkidsApi?.swUrl);
  const sub = await reg?.pushManager.getSubscription();
  if (!sub) return;

  const endpoint = sub.endpoint;
  await sub.unsubscribe();
  await apiFetch('/guardian/push/unsubscribe', {
    method: 'POST',
    body: JSON.stringify({ endpoint }),
  });
}
