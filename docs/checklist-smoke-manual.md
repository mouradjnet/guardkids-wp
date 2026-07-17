# Checklist de Smoke Manual — GuardKids WP

**Alvo:** v1.36.1 · **Ambiente:** LocalWP · **Última execução:** _(preencher)_

Checklist de validação manual dos dois apps. Ordenado por **risco**, não por tela: o que mudou recentemente vem primeiro, porque é onde regressão aparece.

---

## 0. Preparação

- [ ] LocalWP **Started**, site `guardkids-wp` no ar
- [ ] `siteurl`/`home` apontando pra `http://localhost:10034`
      *(obrigatório pro service worker: `localhost` é contexto seguro; `*.local` em HTTP não é, e o push não registra)*
- [ ] Logado no `/painel-pais` como `admin`
- [ ] **Browser limpo / janela anônima** — extensões já fingiram bug neste projeto antes
- [ ] Notificações do Chrome **ligadas no Windows** (Configurações → Sistema → Notificações → Chrome)

**Ao terminar tudo:** restaurar `siteurl`/`home` pra `http://guardkids-wp.local` e limpar dados de teste.

---

## 1. O que mudou nesta leva (maior risco)

### 1.1 Web Push do guardião — v1.36.0

- [ ] Configurações → **Notificações push** aparece **habilitado** (contraste: "Alertas em tempo real" segue cinza/travado)
- [ ] Toggle começa **desligado** (fallback=false — não pode nascer ligado sem subscription)
- [ ] Ligar → Chrome pede permissão → aceitar → toggle fica azul, **sem erro na tela**
- [ ] Recarregar a página → toggle **continua ligado** (persistiu no banco)
- [ ] Desligar → toggle apaga, sem erro
- [ ] **Negar a permissão** (num perfil limpo) → toggle **volta pro desligado** e mostra o motivo — não pode mentir que está ligado

### 1.2 Zonas no mapa — v1.36.1

- [ ] Zonas Seguras → criar zona ("Escola", raio 200m) escolhendo o ponto no mapa
- [ ] Localização → a zona aparece **desenhada como círculo**, com o nome, junto do marker do filho
- [ ] Criar uma 2ª zona → as duas aparecem
- [ ] Excluir uma zona → some do mapa
- [ ] **Sem zona nenhuma** → o mapa continua funcionando (não quebra)
- [ ] Nenhum texto promete alerta de chegada/saída *(as 3 cópias foram corrigidas)*

### 1.3 Erros visíveis na Biblioteca — v1.36.1

- [ ] Conteúdo Infantil → Aprovar / Revogar / Excluir funcionam
- [ ] Forçar falha (DevTools → Network → Offline) e clicar **Aprovar** → **mensagem de erro aparece**
      *(antes o botão não fazia nada — era o bug que a auditoria pegou)*
- [ ] Mesma coisa em **Excluir** e **Revogar**

### 1.4 Upload de miniatura — v1.35.0 + fix de mime

- [ ] Adicionar Conteúdo → **Enviar imagem** → escolher PNG → preview aparece e a URL preenche
- [ ] Tentar enviar um **PDF renomeado pra .png** → recusado
      *(o WP valida magic bytes agora, não só o que o cliente declara)*
- [ ] Salvar e conferir que a miniatura aparece no card

### 1.5 Moderação — v1.34.0

- [ ] Conteúdo novo entra como **Pendente** (badge âmbar)
- [ ] Badge "N pendente(s)" no topo bate com a lista
- [ ] Filtros **Todos / Pendentes / Aprovados** filtram de verdade
- [ ] Aprovar → vira **Aprovado** (badge verde)
- [ ] **No app-filho**: conteúdo pendente **NÃO** aparece; depois de aprovado, aparece

---

## 2. O laço central (pedido → aviso → decisão)

- [ ] App-filho → criar um pedido
- [ ] **Notificação chega no desktop** ("«Nome» pediu acesso")
- [ ] Clicar na notificação → abre/foca `/painel-pais`
- [ ] Painel → Aprovações → o pedido está lá
- [ ] Aprovar → app-filho reflete
- [ ] Negar → app-filho reflete
- [ ] Criar 2 pedidos → chegam 2 notificações (ids diferentes)
- [ ] Forçar o **mesmo bloqueio 2x no mesmo dia** → chega **uma só** *(dedupe)*

---

## 3. App dos pais — por tela

### Painel
- [ ] KPIs batem com a realidade (nº de filhos, pedidos pendentes)
- [ ] Cards de filho: badge de pendências, última sync
- [ ] Próximas ações e Eventos recentes populam
- [ ] Solicitações Pendentes → Aprovar/Negar direto do painel

### Filhos
- [ ] Criar filho → aparece na lista
- [ ] Editar nome/idade → persiste
- [ ] Upload de avatar → aparece
- [ ] **Excluir filho** → some *(o bug histórico era dado duplicado; conferir que a lista reflete)*
- [ ] Conectar Dispositivo → token + **QR Code** aparecem

### Aprovações
- [ ] Lista os pendentes
- [ ] Aprovar / Negar → some da lista
- [ ] Estado vazio quando não há nada

### Sites & Regras
- [ ] Adicionar site à lista → persiste
- [ ] Remover → some
- [ ] Categorias (premium) respondem

### Limites de Tempo
- [ ] Limite diário → salvar → persiste
- [ ] Hora de dormir → salvar → persiste
- [ ] Dias da semana → salvar → persiste
- [ ] **TimelineCard** mostra as 24h (bedtime/usado/livre)

### Localização
- [ ] Dropdown de filho troca o mapa
- [ ] Marker na posição, popup com bateria e "atualizado há X"
- [ ] DeviceStatus + checklist de ativação coerentes
- [ ] Banner de "última posição conhecida" quando offline
- [ ] **+ as zonas desenhadas (ver 1.2)**

### Zonas Seguras
- [ ] Criar / editar / excluir zona
- [ ] Map picker funciona
- [ ] Validação de raio (10–5000m)

### Conteúdo Infantil
- [ ] Criar / editar / excluir conteúdo
- [ ] Analytics (mais acessados, categorias, tempo)
- [ ] Recomendações por filho → escolher filho → gerenciar

### Gamificação / Recompensas
- [ ] Progressão e nível do filho aparecem
- [ ] Criar recompensa → aparece no app-filho (Loja)
- [ ] Resgate pendente → aprovar → saldo do filho debita
- [ ] Aprovar resgate **sem saldo** → erro visível

### Relatórios
- [ ] Semana / Mês trocam os dados
- [ ] KPIs, gráfico, top sites, resumo por filho
- [ ] Estado vazio sem dados

### Configurações
- [ ] Notificações: **push (1.1)**, email, relatório semanal salvam
- [ ] Segurança: PIN, 2FA, sessões, auto-logout
- [ ] Família: convidar guardião → link; ativar; remover
- [ ] Localização: toggle liga/desliga
- [ ] Premium: link de upgrade salva
- [ ] Privacidade: **Exportar** baixa JSON; **Limpar histórico** funciona
- [ ] **Excluir tudo** exige digitar EXCLUIR *(cuidado: destrutivo)*

### Licença / Upgrade / Modo de Proteção
- [ ] Licença: colar chave → ativa → estado premium
- [ ] Sem licença → **PremiumLock** cobre Localização/Zonas/Relatórios
- [ ] Upgrade: tabela de planos e botão apontando pro `upgrade_url`

---

## 4. App do filho — por tela

### Pareamento
- [ ] Colar token → conecta
- [ ] **Escanear QR Code** → pré-preenche e conecta
- [ ] Token inválido → erro claro

### Home / Mundo / Loja / Avatar
- [ ] Home: saudação, regras do dia (limite/bedtime/dias reais)
- [ ] Mundo: biblioteca só com conteúdo **aprovado** e da faixa etária
- [ ] Loja: recompensas + saldo; resgatar → vira pendente no painel
- [ ] Avatar: catálogo com travas por nível/medalha; equipar → reflete

### Navegador / Pedidos / Alertas
- [ ] Browser: atalhos abrem; site fora da regra bloqueia
- [ ] Pedidos: chips rápidos criam pedido; lista mostra status
- [ ] Alertas: notificações do filho aparecem; marcar como lida

### Bloqueio
- [ ] Forçar bedtime → tela **Blocked** com contagem e motivo certo
- [ ] Limite estourado → Blocked por "limite"
- [ ] Dia bloqueado → Blocked por "dia"
- [ ] Botão "Solicitar acesso" cria pedido

### Localização (filho)
- [ ] Autorizar geolocalização → posição sobe
- [ ] Negar → estado "negado" claro
- [ ] Painel reflete a posição

---

## 5. Limpeza pós-teste

- [ ] `siteurl`/`home` restaurados pra `http://guardkids-wp.local`
- [ ] Filhos/pedidos/conteúdos de teste removidos
- [ ] Tokens de smoke apagados (`child_token:*` em `wp_guardkids_settings`)
- [ ] Chaves de dedupe limpas *(senão suprimem avisos reais no mesmo dia)*
- [ ] Zonas de teste removidas
