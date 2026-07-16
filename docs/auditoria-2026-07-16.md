# Auditoria Técnica — GuardKids WP v1.36.0

**Data:** 2026-07-16 · **Versão auditada:** v1.36.0 / DB v24 (em produção) · **Sistema:** 2F Quality Audit v2.0

---

## 1. Executive Summary

O GuardKids WP é um plugin WordPress de controle parental **maduro e tecnicamente sólido**, rodando em produção com 1.011 testes automatizados, CI de 4 jobs e uma disciplina de migrações que já sobreviveu a um incidente real (a 003 com `dbDelta`) e aprendeu com ele.

**O veredito em uma frase: o app está pronto; o negócio em volta dele não está.**

O produto faz o que promete — prospecta o dia da criança, aplica regras, avisa o pai, gamifica, localiza. As oito dimensões técnicas pontuam entre 7 e 9. A nona, comercialização, pontua **3** — e é ela que separa "software que funciona" de "software que se vende". Não há license server, não há checkout, não há onboarding: cada cliente novo exige que o autor rode um CLI na própria máquina e mande a chave por e-mail.

O segundo achado mais relevante é um **padrão sistêmico de falha silenciosa** no frontend: 83 mutations contra 19 tratamentos de erro. Não é um bug — são 64 lugares onde o app pode falhar sem dizer nada. Já custou uma investigação em produção (o "delete que não excluía", que era dado duplicado com erro invisível).

---

## 2. Health Score

| Dimensão | Nota | Comentário |
|---|:---:|---|
| Arquitetura | **8.5** | Modular, coesa, DI por construtor nullable. Dois registries inchados. |
| Código | **8.0** | Consistente, tipado, `final` por padrão, doc em PT-BR que explica *porquê*. |
| Banco de Dados | **9.0** | Índices compostos certos, TTL, migrações disciplinadas, uninstall completo. |
| Performance | **7.5** | Sem N+1 em caminho quente relevante. Agregação no read é o teto futuro. |
| Segurança | **9.0** | Nonce + capability em 88 rotas, zero SQL concatenado, rate limit, 2FA, PIN, sessões. |
| UX | **7.0** | Painel completo e polido — mas 64 mutations mudas derrubam a nota. |
| Escalabilidade | **7.5** | Excelente pro modelo real (1 família/instalação). Não é multi-tenant. |
| **Comercialização** | **3.0** | **O gargalo.** Sem license server, checkout, onboarding ou atualização automática. |

**Health Score geral: 7.4/10** — puxado pra baixo por uma única dimensão.

Sem a lente comercial, o projeto é **8.3**. É importante ler os dois números: como *produto*, está entregue; como *negócio*, falta a metade que dá dinheiro.

---

## 3. Problemas priorizados

Não vou inventar 20. Foram encontrados **11 problemas reais** — o resto do código não tem defeito que justifique linha em relatório.

| # | Grav. | Arquivo | Problema | Esforço |
|:-:|:-:|---|---|:-:|
| 1 | 🔴 | — | **Não existe license server.** Licença é cunhada à mão via `scripts/issue-license.php`. Sem webhook, renovação ou revogação. | Alto |
| 2 | 🔴 | `public/app-parent/src/**` | **83 `useMutation` × 19 `onError`.** 64 pontos de falha silenciosa. A `ContentDashboard` (v1.34/35) tem approve/revoke/excluir mudos. | Médio |
| 3 | 🟠 | `pages/Upgrade.tsx` | Tabela de planos é `planFeatures` **hardcoded no mockData**; o botão só linka pro `upgrade_url`. Sem checkout. | Médio |
| 4 | 🟠 | `pages/ZonasSeguras.tsx:187` | **A UI promete o que não existe**: "Em breve, vai poder receber notificações". Não há geofencing no código — zona é cadastro decorativo. | Alto |
| 5 | 🟠 | — | **Sem onboarding.** Instalação limpa cai num painel vazio, sem wizard, sem demo, sem primeiro-uso guiado. | Médio |
| 6 | 🟡 | `Controllers/ContentController.php:287` | Validação de mime usa `$files['file']['type']` — **header do cliente, trivialmente forjável**. O `media_handle_upload` do WP é a proteção real; o check dá falsa confiança. | Baixo |
| 7 | 🟡 | `api/RestApi.php` (909 linhas) | Registry inchado: 88 rotas num arquivo. Já é dividido em métodos privados, mas cresce sem teto. | Médio |
| 8 | 🟡 | `pages/Settings.tsx` (911 linhas) | God-file: 6 seções + 4 componentes internos + `MutationError` duplicado do `SitesRules.tsx`. | Médio |
| 9 | 🟡 | `Settings.tsx` × `SitesRules.tsx` | `MutationError` **duplicado** em dois arquivos com assinaturas diferentes. | Baixo |
| 10 | 🟢 | `Controllers/CompanionController.php` | 5 rotas com `__return_true` + auth dentro do handler, enquanto as rotas da criança usam `permission_callback`. Ambas seguras, padrões divergentes. | Baixo |
| 11 | 🟢 | `Notifications/Notifier.php:85` | Chaves de dedupe da **criança** ainda em `gmdate` (UTC) — mesmo bug que a v1.36.0 corrigiu no guardião: em UTC-3 a janela vira às 21:00, em cima do bedtime. | Baixo |

---

## 4. Top riscos

| # | Risco | Por quê |
|:-:|---|---|
| 1 | **Não dá pra vender sem trabalho manual do autor** | Cada venda exige rodar CLI + mandar chave. Não escala nem pra 10 clientes/mês. |
| 2 | **Falha silenciosa vira bug fantasma** | Já aconteceu (o "delete que não excluía"). Custa horas de investigação por sintoma invisível. |
| 3 | **Promessa não cumprida na tela** | "Em breve, notificações" em Zonas Seguras é dívida de credibilidade com quem paga. |
| 4 | **Bus factor = 1** | Projeto de dev solo, sem contribuidores. Todo o contexto (junctions, LocalWP, openssl, Hostinger) vive na cabeça do autor + memória. |
| 5 | **Dependência de FCM sem retry** | Falha transitória perde o aviso instantâneo (documentado e aceito no spec do push). |
| 6 | **Agregação no read** | `UsageEventRepository` agrega em SQL a cada request de relatório. Com TTL de 90d o teto é conhecido, mas é o próximo gargalo se o volume crescer. |
| 7 | **Ambiente de dev frágil** | Dois bloqueios ambientais (openssl.cnf, notificações do Chrome) mascararam problemas por meses. Ambos resolvidos hoje. |

---

## 5. Quick Wins

Alto impacto, baixo esforço — na ordem em que eu faria:

1. **`onError` na `ContentDashboard`** (~30 min) — fecha o risco da entrega mais recente, que hoje falha muda.
2. **`gmdate` → `current_time` no `Notifier` da criança** (~15 min) — o mesmo fix da v1.36.0, aplicado ao lado que ficou.
3. **Remover a promessa de notificação da `ZonasSeguras`** (~10 min) — ou entrega o geofencing, ou tira da tela. Prometer e não cumprir é pior que não ter.
4. **Dedupe do `MutationError`** (~20 min) — extrair pra `components/`.
5. **Tirar o check de mime forjável** ou trocar por `wp_check_filetype_and_ext` (~20 min) — o WP já protege; o código atual finge que protege.

---

## 6. Roadmap

### Curto prazo (o que finaliza o app como produto)
- Os 5 Quick Wins acima
- **Varredura de `onError`** nas 64 mutations restantes — priorizar as destrutivas (excluir/revogar) e as de dinheiro/licença
- Decidir o destino da `ZonasSeguras`: geofencing ou remoção da promessa

### Médio prazo (o que transforma em negócio)
- **License server** (fork do `planocerto-license-server`, que já existe e já foi rebrandado uma vez pro FluxoMestre — o caminho está trilhado)
- **Checkout + webhook** (Hotmart, seguindo o padrão do FluxoMestre)
- `Upgrade.tsx` consumindo planos reais em vez do `mockData`
- **Onboarding**: wizard de primeiro uso (criar filho → parear → definir limites)

### Longo prazo (só com demanda medida)
- Geofencing + alertas de zona
- Fila de retry pro push
- `firstUsedAt` no token → evento "novo dispositivo conectado"
- Agregação materializada, se o volume justificar

---

## 7. Conclusão Estratégica

**O sistema está pronto para produção?**
Sim, e não em tese: está em produção, com 1.011 testes verdes, CI de 4 jobs, migrações disciplinadas e segurança sólida. O laço central do produto foi fechado e validado na v1.36.0.

**O que impede a comercialização hoje?**
Exatamente uma coisa: **não existe caminho automático entre alguém querer pagar e alguém ter a licença**. Todo o resto do produto está pronto para o cliente. O license server é a peça que falta, e ela já foi construída duas vezes neste mesmo `C:/Users/mysho/` (planocerto, fluxomestre) — não é pesquisa, é replicação.

**O que deve ser corrigido imediatamente?**
Os `onError`. Não porque um usuário vá reclamar amanhã, mas porque cada mutation muda é uma armadilha esperando custar uma tarde de investigação — como já custou.

**Qual o maior risco?**
Não é técnico. É o app estar tecnicamente pronto e comercialmente parado: a distância entre 8.3 e 3.0 é onde o trabalho já feito deixa de virar retorno.

**Nível de maturidade**
**Produto: maduro.** Arquitetura clara, testes reais, segurança auditável, produção validada, disciplina de release.
**Negócio: embrionário.** Sem funil, sem cobrança, sem entrega automática.

---

*Auditoria conduzida sobre o código em disco (fonte da verdade), não sobre specs. Segurança verificada por leitura direta — a suspeita inicial de open redirect no 2FA foi levantada e **descartada**: o `wp_safe_redirect` da linha 106 neutraliza o `$_REQUEST['redirect_to']`.*
