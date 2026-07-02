# Design — Web Push real no app-filho (fase 2 de notificações)

- **Data:** 2026-07-02
- **Status:** aprovado (aguardando review do spec)
- **Escopo:** app-filho (PWA infantil) do guardkids-wp
- **Depende de:** fase 1 (v1.25.0, em prod) — tabela `wp_guardkids_notifications`, `NotificationRepository`, serviço `Notifier` (funil único), Alertas real + badge. Spec fase 1: `docs/superpowers/specs/2026-07-02-child-notifications-design.md`.

## 1. Contexto e objetivo

A fase 1 entregou a fundação in-app: notificações reais criadas por 6 gatilhos via o `Notifier`, vistas na página Alertas quando o app está aberto. Esta fase adiciona **Web Push real**: o dispositivo da criança recebe uma **notificação do sistema operacional mesmo com o app fechado**.

O `PushSender` pluga no `Notifier` existente: sempre que uma notificação é criada, além de gravar a linha, envia o push às subscriptions do filho.

### Metas
- Envio web-push **em PHP puro** (RFC 8291 aes128gcm + VAPID ES256), sem dependência de runtime.
- Chaves VAPID geradas e persistidas; subscriptions por filho.
- Push **síncrono** no gatilho do `Notifier`, para os **6 tipos** de notificação.
- SW customizado (`injectManifest`) com handlers `push`/`notificationclick`.
- Opt-in via card soft-ask na tela Alertas.
- Limpeza automática de subscriptions mortas (404/410).

### Não-metas (YAGNI)
- Preferências de push por-tipo; push para o painel-pais.
- Agrupamento/threading, actions ricas, imagens grandes.
- Fila/retry/backoff, analytics de entrega.
- Suporte a `aesgcm` legado (só `aes128gcm`, suportado por Chrome/Edge/Firefox/Safari 16.4+).

### Decisões resolvidas (brainstorming)
1. **Envio:** PHP puro (openssl+sodium) — primitivos confirmados disponíveis. Mantém o plugin self-contained.
2. **Timing:** síncrono no gatilho (`wp_remote_post` timeout 5s, falhas isoladas).
3. **Tipos:** todos os 6 (o dedup do funil evita spam).
4. **Opt-in:** card "Ativar avisos" (soft-ask) na Alertas; degrada suave.
5. **SW:** `injectManifest` (SW customizado em TS).

## 2. Chaves VAPID + storage de subscriptions

**VAPID** — par EC P-256 gerado uma vez via `openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC])`, persistido em `wp_options`:
- `guardkids_vapid_public` — ponto público não-comprimido (65 bytes) em base64url.
- `guardkids_vapid_private` — escalar privado (32 bytes) em base64url.
- Classe `includes/Notifications/WebPush/VapidKeys.php`: `publicKey(): string`, `privateKey(): string`, geração lazy no primeiro acesso (idempotente).

**Migração 015** (DB v14→**v15**, `$wpdb->query` CREATE TABLE IF NOT EXISTS):
```
wp_guardkids_push_subscriptions (
  id         BIGINT UNSIGNED PK AI,
  child_id   BIGINT UNSIGNED NOT NULL,
  endpoint   VARCHAR(512) NOT NULL,
  p256dh     VARCHAR(255) NOT NULL,
  auth       VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY endpoint_unq (endpoint(191)),
  KEY child (child_id)
)
```
`PushSubscriptionRepository`:
- `upsertByEndpoint(int $childId, string $endpoint, string $p256dh, string $auth): void` — se o endpoint existe, atualiza child_id/keys; senão insere.
- `findByChild(int $childId): array`
- `deleteByEndpoint(string $endpoint): void`

`uninstall.php` passa a dropar `wp_guardkids_push_subscriptions`.

## 3. Crypto web-push (`includes/Notifications/WebPush/`)

### `Vapid.php` — cabeçalho de autorização
`header(string $endpoint): string` devolve `vapid t=<jwt>, k=<publicKey b64url>`:
- JWT: `base64url(header) . '.' . base64url(claims)`, header `{"typ":"JWT","alg":"ES256"}`, claims `{"aud": <scheme://host do endpoint>, "exp": time()+43200, "sub": "mailto:contato@guardiaokids.site"}`.
- Assinatura: `openssl_sign($signingInput, $der, $privateKeyPem, OPENSSL_ALGO_SHA256)`; converter DER (ECDSA-Sig-Value) → JOSE `r||s` (64 bytes) → base64url. Assinatura anexada ao JWT.
- O PEM da chave privada é remontado a partir do escalar (via `Vapid::privateKeyPem()` usando a `VapidKeys`).

### `Payload.php` — corpo aes128gcm (RFC 8291)
`encrypt(string $plaintext, string $uaPublicB64url, string $authB64url): string`:
1. Efêmero EC P-256 (`openssl_pkey_new`), extrai `serverPublic` (65 bytes).
2. `sharedSecret = openssl_pkey_derive($uaPublicKeyResource, $serverPrivate)` (ECDH).
3. `salt = random_bytes(16)`.
4. PRK/keys via HKDF (`hash_hkdf('sha256', ...)`):
   - `prk_key = HKDF(ikm=sharedSecret, salt=auth, info="WebPush: info\x00"+uaPublic+serverPublic, len=32)`.
   - `cek = HKDF(ikm=prk_key, salt=salt, info="Content-Encoding: aes128gcm\x00", len=16)`.
   - `nonce = HKDF(ikm=prk_key, salt=salt, info="Content-Encoding: nonce\x00", len=12)`.
5. Padding (RFC 8188): anexa o delimitador `0x02` ao plaintext (último/único record, sem padding extra). Os bytes exatos são fixados pra bater com o test vector da RFC 8291 §5.
6. `ciphertext = openssl_encrypt(padded, 'aes-128-gcm', cek, OPENSSL_RAW_DATA, nonce, $tag)`; anexa `$tag` (16 bytes GCM).
7. Corpo: `salt(16) | rs=4096 as uint32be(4) | idlen=65 (1) | serverPublic(65) | ciphertext+tag`.

**Validação obrigatória:** teste com o **test vector da RFC 8291 §5** (ua public/private, auth, salt, server keypair e plaintext fixos → corpo cifrado esperado). O teste injeta o salt e o par efêmero fixos (via um seam de teste) e compara o corpo byte-a-byte; um segundo teste faz roundtrip decifrando com a chave da UA.

### `PushSender.php`
`sendToChild(int $childId, string $title, string $body): void`:
- Payload JSON: `{"title":..., "body":..., "url":"/painel-filho/"}` → `Payload::encrypt(...)` por subscription.
- Headers por request: `Authorization: <Vapid::header(endpoint)>`, `Content-Encoding: aes128gcm`, `Content-Type: application/octet-stream`, `TTL: 2419200`, `Urgency: normal`.
- `wp_remote_post($endpoint, ['headers'=>..., 'body'=>$cipher, 'timeout'=>5])`.
- Resposta 201/200/202 → ok. **404/410 → `deleteByEndpoint`**. Outros erros → `error_log`, segue.
- Toda a operação em try/catch — **nunca** propaga exceção pro gatilho.

## 4. Gancho no `Notifier`

Refatorar o `Notifier` para um helper interno único de criação+push:
```php
private function emit(int $childId, string $dedupKey, array $data): void
{
    if ($this->repo->createIfAbsent($childId, $dedupKey, $data)) {
        $this->pushSender->sendToChild($childId, (string) $data['title'], (string) ($data['body'] ?? ''));
    }
}
```
- Os 6 pontos (`notifyRequestDecided`, `notifySiteAllowed`, `notifyBlocked`, `persistWarnings`) passam a chamar `emit(...)` em vez de `createIfAbsent(...)` direto.
- Construtor ganha `?PushSender $pushSender = null` (default `new PushSender()`), injetável para testes sem rede.
- Resultado: os 6 tipos empurram; o dedup segue governando (push só quando a linha é realmente criada).

## 5. API REST (auth por token do filho, em `ChildSelfController`)

- **`GET /child/push/key`** → `{ publicKey: <VAPID pública b64url> }`.
- **`POST /child/push/subscribe`** → body `{ endpoint:string, keys:{p256dh:string, auth:string} }` → `PushSubscriptionRepository::upsertByEndpoint(childId, ...)` → `{ ok:true }`. Valida campos (422 se faltar).
- **`POST /child/push/unsubscribe`** → body `{ endpoint:string }` → `deleteByEndpoint` → `{ ok:true }`.

Rotas em `RestApi::registerChildSelfRoutes`, `permission_callback = ChildAuth::requireToken()`.

## 6. Frontend (app-child)

### Service Worker (`injectManifest`)
- `vite.config.ts`: `VitePWA({ strategies:'injectManifest', srcDir:'src', filename:'sw.ts', registerType:'autoUpdate', manifest:{...igual...}, injectManifest:{ globPatterns:['**/*.{js,css,html,ico,png,svg,webmanifest}'] } })`.
- `src/sw.ts`:
  - `precacheAndRoute(self.__WB_MANIFEST)`.
  - Runtime cache das Google Fonts (mantém, via `registerRoute`).
  - `push`: `const d = event.data?.json() ?? {}` → `self.registration.showNotification(d.title ?? 'GuardKids', { body:d.body, icon:'/painel-filho/pwa-192x192.png', badge:'/painel-filho/pwa-64x64.png', tag:d.tag, data:{ url:d.url ?? '/painel-filho/' } })`.
  - `notificationclick`: fecha a notificação e foca uma aba de `/painel-filho/` existente ou abre uma nova (`clients.matchAll` + `openWindow`).

### Cliente (`src/lib/push.ts`)
- `isPushSupported(): boolean` — `'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window`.
- `getPermission(): NotificationPermission`.
- `subscribe(): Promise<void>` — pega `/child/push/key`, `reg = await navigator.serviceWorker.ready`, `sub = await reg.pushManager.subscribe({ userVisibleOnly:true, applicationServerKey: urlBase64ToUint8Array(key) })`, extrai `endpoint` + `keys.p256dh`/`keys.auth` (do `sub.toJSON()`), `POST /child/push/subscribe`.
- `unsubscribe(): Promise<void>` — `reg.pushManager.getSubscription()` → `sub.unsubscribe()` + `POST /child/push/unsubscribe`.
- helper `urlBase64ToUint8Array`.

### Opt-in — `EnableAlertsCard`
- Renderizado no topo da tela **Alertas**. Visível só se `isPushSupported()` && `getPermission() === 'default'` (esconde em `granted`/`denied` e em navegadores sem suporte — degrada suave, cobre iOS não-instalado).
- Toca "Ativar avisos" → `subscribe()`; em sucesso, some (permissão vira `granted`). Erro → mensagem curta.

## 7. Testes

**PHP (unit, sem rede):**
- `VapidKeys`: gera 1x e persiste; `publicKey`/`privateKey` estáveis; formato b64url.
- `Vapid`: JWT tem 3 partes; header/claims decodificam; `aud` = origin do endpoint; assinatura confere com a pública (via `openssl_verify` convertendo JOSE→DER).
- `Payload`: **RFC 8291 §5 test vector** byte-a-byte (salt+efêmero injetados) + roundtrip decifra.
- `PushSubscriptionRepository`: upsert (novo/atualiza), findByChild, deleteByEndpoint.
- `PushSender`: mock `wp_remote_post` (stub no bootstrap) → headers corretos (Authorization vapid, Content-Encoding aes128gcm, TTL); resposta 410 → subscription removida; 201 → mantida; nunca lança.
- `Notifier`: com PushSender fake, cria+empurra 1x; em dedup-hit, **não** empurra.
- `ChildSelfController`: `/child/push/key` (publicKey presente), `subscribe` (persiste; 422 sem campos; 401 sem token), `unsubscribe`.
- Migração 015 idempotente (Unit + Integration real).

**vitest (app-child):**
- `push.ts`: `isPushSupported` (com/sem APIs), `subscribe` (mocka `serviceWorker.ready`+`pushManager.subscribe`+`fetch`; POST com endpoint/keys certos), `urlBase64ToUint8Array`.
- `EnableAlertsCard`: aparece com suporte+permissão `default`; some em `granted`/sem suporte; clique chama `subscribe`.
- SW (`sw.ts`) handlers não são unit-testados (limitação jsdom) — **smoke manual no device** (push com app fechado + clique abre o painel).

## 8. Fluxo ponta-a-ponta
1. Criança abre `/painel-filho` (PWA instalada) → card "Ativar avisos" → concede permissão → subscription salva no servidor.
2. Responsável aprova um pedido → `RequestController::decide` → `Notifier::emit` → cria a notificação **e** `PushSender::sendToChild` → `wp_remote_post` cifrado ao endpoint.
3. O push chega ao SW (app fechado) → `showNotification` → criança toca → abre `/painel-filho/` na tela Alertas.

## 9. Riscos e mitigações
- **Crypto incorreta:** mitigada pelo test vector oficial da RFC 8291 (correção verificável) + roundtrip.
- **Troca pra `injectManifest`:** valida build + precache no CI (`pnpm build`); e2e existente continua verde.
- **iOS:** só com PWA instalada (16.4+); o card degrada suave onde não há suporte.
- **Latência do envio síncrono:** timeout 5s + volume baixo (poucos aparelhos); falhas isoladas não afetam o gatilho.
