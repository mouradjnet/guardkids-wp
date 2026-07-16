# Web Push pro Guardião (v1.36.0)

**Data:** 2026-07-16
**Módulo:** Notificações (app-parent)
**DB:** v23 → v24 (migração 024)

## Problema

O Web Push do GuardKids existe e roda em produção, mas é **exclusivo da criança**: as três
rotas são `/child/push/*`, o `PushSender` só expõe `sendToChild()`, e todo `Notifier::emit()`
recebe um `childId`. A criança é avisada quando o pedido dela é decidido — o guardião, que é
quem precisa saber que **há um pedido esperando decisão**, não recebe nada em tempo real.

Hoje o guardião só descobre abrindo o painel, ou pelo digest de email das 22h
(`DigestMailer`). Um pedido feito às 15h espera seis horas por um email, ou espera o pai
lembrar de abrir o painel. Isso é um buraco no laço central do produto
(pedido → aviso → decisão), não uma feature ausente: o toggle "Notificações push" já existe
em `Settings.tsx` e está `locked` justamente porque não há backend por trás dele.

Falta também o **gatilho**: `ChildSelfController::requestsCreate` não chama o `Notifier`.
Não é só o canal que não existe — o evento mais importante não é emitido por ninguém.

## Decisões (fechadas no brainstorming)

1. **Eventos:** **três** — pedido criado, tempo esgotado e tentativa bloqueada. Colapsam em
   **dois pontos de código** (tempo esgotado e tentativa bloqueada são o mesmo gatilho
   `schedule_block`, separados pelo `detail`).

   > **Revisão em 2026-07-16, durante a implementação.** O brainstorming fechou com um
   > quarto evento — "filho pareou um novo dispositivo" — que **saiu desta fatia**. O
   > motivo: o endpoint existente (`ChildController::issueDeviceToken`) é o **pai gerando
   > um token no painel**, não a criança conectando. Notificar ali avisaria o guardião de
   > um clique que ele mesmo acabou de dar. O evento de segurança real — alguém resgatou
   > o token — **não existe no código**: o token vive em `settings` como
   > `{childId, label, createdAt}` e o `ChildAuth::resolveChildId` é leitura pura, sem
   > registrar primeiro uso. Entregar isso exige gravar `firstUsedAt` no ciclo de vida do
   > token, que é outra fatia — não fiação de notificação. Ver "Fora de escopo".
2. **Destinatários:** **todos os guardiões ativos** (`status=active`), admin ou
   collaborator. Quem decidir primeiro resolve; os outros veem resolvido ao abrir.
3. **Sem feed in-app:** só push. O destino do toque é `/painel-pais`, onde os
   `PendingRequests` já mostram o que há pra decidir. Nenhuma página nova.
4. **Dedupe por evento**, não por guardião: o evento aconteceu uma vez, anuncia-se uma vez
   pra todo mundo. Chave única numa tabela própria e enxuta.
5. **Sem gating de licença:** livre, como o push da criança. Um controle parental que não
   avisa o pai está quebrado, não "limitado" — gatear isso faria o Free parecer defeituoso.
6. **Abordagem A — tabelas paralelas.** Puramente aditivo: nenhum `ALTER` em tabela viva,
   nenhuma linha tocada no caminho da criança que já roda em produção.
7. **Manifest mínimo no app-parent** pra destravar iOS (ver "A restrição do iOS").

### Por que A e não uma tabela polimórfica

`push_subscriptions.child_id` é `NOT NULL`. As alternativas eram tornar a tabela polimórfica
(`subscriber_type` + `subscriber_id`) ou adicionar `wp_user_id` nullable. Ambas exigem
`ALTER` numa tabela **com dados em produção** e reescrever o caminho de push da criança.
Este projeto já foi mordido duas vezes por migration em tabela existente (a 003 com
`dbDelta` no-op silencioso, e quase de novo na 007). A duplicação entre as duas tabelas é
pequena e honesta: elas servem modelos de autenticação genuinamente diferentes — token de
dispositivo versus usuário WP. Unificar economizaria ~30 linhas e custaria risco em prod.

## Descoberta que dimensiona a fatia: scope de service worker

**O push do guardião é mais barato que o da criança.** O `ChildApp` precisou virar static
server e emitir `Service-Worker-Allowed: /painel-filho/` porque o PWA da criança faz
**precache** — e precache exige que o SW *controle* as páginas.

Push não exige controle de página. `pushManager.subscribe()` funciona sobre qualquer
`ServiceWorkerRegistration`, o evento `push` chega ao SW independente de haver página aberta,
e `notificationclick` abre a janela por conta própria via `clients.openWindow()`. Como o
`ParentApp` já serve os assets por `plugins_url()`, o `sw.js` é registrado dali com o scope
natural dele (o diretório `dist/`) — que **não** cobre `/painel-pais/`, e não precisa cobrir.

Consequência: **sem `vite-plugin-pwa`, sem Workbox, sem header de scope, sem static server**,
e o `ParentApp` só ganha a injeção de uma URL.

## Modelo de dados

Migração `024_guardian_push.php`, duas tabelas. Bump de `GUARDKIDS_DB_VERSION` 23 → 24
**no mesmo commit** (sem isso `maybeRunMigrations` pula). Drop de ambas no `uninstall.php`.
`$wpdb->query` direto com `CREATE TABLE IF NOT EXISTS`, nunca `dbDelta`.

```sql
CREATE TABLE IF NOT EXISTS {prefix}guardkids_guardian_push_subscriptions (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    wp_user_id BIGINT UNSIGNED NOT NULL,
    endpoint   VARCHAR(512) NOT NULL,
    p256dh     VARCHAR(255) NOT NULL,
    auth       VARCHAR(255) NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY endpoint_unq (endpoint(191)),
    KEY wp_user (wp_user_id)
);

CREATE TABLE IF NOT EXISTS {prefix}guardkids_guardian_push_dedup (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dedup_key  VARCHAR(191) NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY dedup_unq (dedup_key)
);
```

A subscription é chaveada por `wp_user_id` (não por `guardian_id`) porque é o que
`get_current_user_id()` devolve no subscribe e o que `GuardianRepository::findByWpUserId()`
resolve na entrega. O filtro de `status=active` acontece na leitura, não no schema — assim
um guardião desativado e reativado não perde os aparelhos dele.

`guardian_push_dedup` cresce ~1 linha por evento por dia. O `Purger` já roda diariamente
(`guardkids_daily_purge`); adicionar o expurgo de linhas com mais de 30 dias é uma linha lá
e evita a tabela crescer pra sempre.

## Backend

### `database/GuardianPushSubscriptionRepository.php`

- `upsertByEndpoint(int $wpUserId, string $endpoint, string $p256dh, string $auth): void` —
  mesmo padrão do `PushSubscriptionRepository` (procura por endpoint, atualiza ou insere).
- `findByUser(int $wpUserId): array`
- `findAll(): array` — a tabela é pequena (uma linha por aparelho de guardião); o filtro de
  autorização acontece fora do repo (ver `GuardianAuth::isActiveGuardian`).
- `deleteByEndpoint(string $endpoint): void` — usado pela limpeza de 404/410.

O repo é burro de propósito: quem pode receber push é decisão de **autorização**, não de
acesso a dados, e já existe uma classe cuja função é exatamente essa.

### `GuardianAuth::isActiveGuardian(int $wpUserId): bool` (método novo)

**Esta é a peça que quase faltou, e vale registrar por quê.** `GuardianAuth::currentRole()`
devolve `admin` para **qualquer usuário com `manage_options`, tenha ou não linha em
`guardians`** — é o comportamento documentado na própria classe ("WP `manage_options` é
autoridade final"). Já `GuardianRepository::findActive()` só enxerga quem tem linha.

Se a entrega resolvesse os destinatários por `findActive()`, o **admin WP principal — o dono
da instalação, o usuário mais importante da feature — passaria no subscribe e nunca receberia
push**, porque não existe linha dele em `guardians`. O sintoma ("liguei e não chega nada")
seria indistinguível de um bug de infraestrutura.

O método novo espelha a lógica do `currentRole()`, mas para um usuário arbitrário em vez do
usuário corrente:

```php
public static function isActiveGuardian(int $wpUserId, ?GuardianRepository $repo = null): bool
{
    if (function_exists('user_can') && user_can($wpUserId, 'manage_options')) {
        return true;
    }
    $repo ??= new GuardianRepository();
    $row = $repo->findByWpUserId($wpUserId);
    return $row !== null && ($row['status'] ?? '') === 'active';
}
```

Usa `user_can($id, ...)` em vez de `current_user_can()` porque no momento do envio não há
usuário logado — o gatilho vem de uma request da **criança**. Não faz o fallback por email
que o `currentRole()` faz: a subscription sempre grava um `wp_user_id` real, então não há o
caso "guardião cadastrado por email antes de ter conta WP".

### `database/GuardianPushDedupRepository.php`

- `createIfAbsent(string $dedupKey): bool` — `true` se a linha é nova (logo, deve enviar);
  `false` se já existia. Espelha a semântica do `NotificationRepository::createIfAbsent`.

### `PushSender::sendToGuardians(string $title, string $body): void`

Método novo ao lado do `sendToChild`, **sem tocar nele**. Reusa `sendOne()` intacto. Payload
com `url => '/painel-pais/'`. Mesma disciplina de erro: cada envio em try/catch com
`error_log`, falha nunca propaga. A limpeza de endpoint morto (404/410) vem de graça do
`sendOne`.

O fan-out é `findAll()` filtrado por `GuardianAuth::isActiveGuardian($sub['wp_user_id'])` —
não um join contra `findActive()`, pelo motivo da seção anterior. Efeito colateral desejado:
um guardião **removido do time para de receber no envio seguinte**, sem precisar de limpeza
da tabela de subscriptions.

Ganha um segundo repo no construtor (`?GuardianPushSubscriptionRepository`), com o mesmo
default-null dos outros para manter os testes injetáveis.

### `includes/Notifications/GuardianNotifier.php`

Funil único, espelhando o `Notifier`:

```php
private function emit(string $dedupKey, string $title, string $body): void
{
    if ($this->dedup->createIfAbsent($dedupKey)) {
        $this->pushSender->sendToGuardians($title, $body);
    }
}
```

Métodos públicos e suas chaves:

| Método | Gatilho | `dedup_key` | Cópia |
|---|---|---|---|
| `notifyRequestCreated(array $request)` | `ChildSelfController::requestsCreate` | `req:{id}` | "{Nome} pediu acesso" / descrição do pedido |
| `notifyLimitReached(int $childId)` | `eventsCreate`, `detail=limit` | `lim:{childId}:{Y-m-d}` | "{Nome} esgotou o tempo de tela" |
| `notifyBlockedAttempt(int $childId, string $detail)` | `eventsCreate`, `detail=bedtime\|weekday` | `blk:{childId}:{detail}:{Y-m-d}` | "{Nome} tentou acessar fora do horário" |

O `{Nome}` é resolvido **dentro** do `GuardianNotifier`, que injeta `ChildRepository` no
construtor exatamente como o `Notifier` já faz. Os call sites passam só o `childId` que já
têm em mãos — nenhum deles precisa aprender a buscar nome de filho. Filho sem nome cai num
fallback ("Seu filho"), porque uma notificação sem nome ainda é útil e um push que explode
por causa de cópia seria absurdo.

O `{Y-m-d}` nas chaves é o que resolve o barulho: no máximo um aviso por filho, por tipo,
por dia. O `req:{id}` não precisa de data — um pedido é criado uma vez.

### `api/Controllers/GuardianPushController.php`

Três handlers, autenticados por nonce do WP + `GuardianAuth::role() !== null` (qualquer
guardião ativo, não só `manage_options` — o collaborator também decide pedidos):

- `pushKey` → `['publicKey' => $vapidKeys->publicKey()]`
- `pushSubscribe` → valida `endpoint`/`p256dh`/`auth`, `upsertByEndpoint(get_current_user_id(), ...)`
- `pushUnsubscribe` → `deleteByEndpoint($endpoint)`

Rotas em `RestApi.php`: `/guardian/push/key` (GET), `/guardian/push/subscribe` (POST),
`/guardian/push/unsubscribe` (POST). As chaves VAPID são **as mesmas** da criança —
`VapidKeys` vive em `wp_options` e não sabe de quem é o push. Nada a gerar.

## Frontend — app-parent

### `public/sw.js` (novo, JS puro)

~30 linhas, copiado verbatim pelo Vite (arquivos em `public/` não passam pelo bundler).
Só dois listeners, `push` e `notificationclick`, espelhando `app-child/src/sw.ts` **sem** as
partes de Workbox/precache. `notificationclick` foca uma janela de `/painel-pais` se houver,
senão abre uma.

### `includes/Ui/ParentApp.php` (cirúrgico)

Injeta `swUrl => $distUrl . 'sw.js'` no `window.guardkidsApi` já existente, e linka o
manifest. Nada mais muda — sem static server, sem header de scope.

### `public/app-parent/src/lib/push.ts` (novo)

Espelha `app-child/src/lib/push.ts`: `subscribe()` (busca chave pública → `requestPermission`
→ registra SW → `pushManager.subscribe` → POST) e `unsubscribe()`. Exporta um
`isPushSupported()` para o Settings decidir se mostra o toggle habilitado.

### `Settings.tsx` — destravar `notifications.push`

O toggle existe e está `locked`. Remove o `locked` e liga no `subscribe`/`unsubscribe`.
Erro é obrigatório, não opcional: **permissão negada**, **browser sem suporte** e **falha de
rede no subscribe** voltam o toggle pro estado desligado e mostram a mensagem — nunca mente
que está ligado. Usa o `<MutationError error={mutation.error} />` que a página já usa; não
inventa um terceiro padrão de erro.

### Manifest mínimo (`public/manifest.webmanifest`)

`name`/`short_name`/`start_url: /painel-pais/`/`display: standalone`/`theme_color`, com os
ícones 192/512 reaproveitados do app-child. É o que torna o painel instalável.

## A restrição do iOS

No iOS/iPadOS, **Web Push só funciona se o site estiver instalado na Tela de Início**
(Safari 16.4+). É restrição da Apple, não escolha nossa. O app-child já tem manifest e é
instalável, então o push da criança funciona no iPhone hoje. O app-parent não tinha manifest
— sem ele, um pai de iPhone ligaria o toggle e **não receberia nada**.

Por isso o manifest mínimo entra nesta fatia: sem ele a feature nasce cega numa parcela real
de usuários, e o sintoma ("liguei e não chega nada") é indistinguível de bug. Com ele, o
caminho do iPhone é o mesmo da criança: instalar na Tela de Início, ligar o toggle, receber.

O manifest **não** transforma o painel num PWA offline — não há service worker de precache
nem estratégia de cache. Ele só declara identidade e torna instalável. Essa distinção é
deliberada: queremos push e instalabilidade, não offline.

## Testes

### PHP (padrão `FakeWpdb`/reflection, sem MySQL)

- `GuardianPushSubscriptionRepositoryTest` — upsert insere quando endpoint é novo; atualiza
  quando o endpoint já existe.
- `GuardianPushDedupRepositoryTest` — `createIfAbsent` devolve `true` na primeira e `false`
  na segunda com a mesma chave.
- `GuardianAuthIsActiveGuardianTest` — **admin WP sem linha em `guardians` devolve `true`**
  (o caso que quase escapou do design); guardião `active` devolve `true`; guardião
  `status != active` devolve `false`; usuário desconhecido devolve `false`.
- `GuardianNotifierTest` — cada gatilho monta a cópia certa; **segunda chamada com a mesma
  chave não envia** (é o teste que prova o dedupe, o coração da fatia).
- `GuardianPushControllerTest` — 401 sem guardião; `key` devolve a publicKey; `subscribe`
  grava com o user corrente; `unsubscribe` apaga.
- `PushSenderTest` — `sendToGuardians` envia pras subscriptions de quem é guardião ativo e
  **pula as de quem não é mais**.

**Os testes mockam o `PushSender`/repos e nunca exercitam o crypto real.** O openssl local
(Windows) não gera chave EC — é o gotcha que já produz as 8 "falhas" locais em Web Push e
passa verde no CI Linux/PHP 8.3. Testes novos tocando `Vapid`/`Payload` cairiam no mesmo
balde e destruiriam o sinal da suíte local.

### Vitest (app-parent)

- `lib/push.test.ts` — fluxo feliz; permissão negada; browser sem suporte.
- `Settings.test.tsx` — toggle destravado; erro visível quando o subscribe falha.

### Critério de sucesso

Suíte verde **não** é o critério. O critério é: **um pedido criado no app-filho faz o celular
do guardião apitar**, e o toque abre `/painel-pais` nos `PendingRequests`. Só o smoke real
prova isso.

## Rollout / deploy

Bump 1.35.0 → **1.36.0** (minor: feature nova + tabelas novas). `GUARDKIDS_DB_VERSION`
23 → 24 no mesmo commit da migração.

Deploy idempotente: as duas tabelas são novas e criadas com `IF NOT EXISTS`; nenhum dado
existente é tocado; o caminho de push da criança não muda uma linha. Rollback é desativar o
plugin — sem migração destrutiva pra desfazer.

Ordem: migração + repos → `GuardianAuth::isActiveGuardian` → sender + notifier →
controller + rotas → sw.js + manifest + ParentApp → push.ts → Settings → gatilhos nos três
pontos → smoke real.

Os gatilhos entram **por último** de propósito: até eles existirem, nada dispara, e o canal
pode ser construído e testado sem risco de spammar ninguém.

## Fora de escopo (YAGNI)

- **Feed in-app de notificações do guardião** — decisão 3. O destino do push é o painel, que
  já mostra o que importa.
- **"Filho pareou um novo dispositivo"** — ver a revisão na seção "Decisões". Fazer certo
  exige gravar `firstUsedAt` no payload do token quando ele é resgatado pela primeira vez,
  o que muda o ciclo de vida do token e toca o `ChildAuth` (que roda em toda request da
  criança). É fatia própria, pequena, e o canal que esta entrega já a atende: bastará um
  `notifyDevicePaired()` no `GuardianNotifier`.
- **Alerta de zona segura (geofencing)** — a `ZonasSeguras` promete "em breve, notificações",
  mas não existe detecção de entrada/saída no código. É fatia própria, e o canal que esta
  entrega é justamente o que ela vai precisar depois.
- **`notifications.realtime`** — continua `locked`; é outro conceito (vibração por evento no
  aparelho da criança), sem backend.
- **Preferência por tipo de evento** — ligar/desligar cada um dos quatro separadamente.
  Um toggle só até alguém pedir granularidade.
- **PWA offline do painel** — manifest sim, precache não. Ver "A restrição do iOS".
- **Push pro Companion Android** — o app nativo tem canal próprio (FCM); fora desta fatia.
