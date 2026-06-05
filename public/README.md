# GuardKids WP — Apps PWA

Dois apps Vite + React + TypeScript + Tailwind compartilhando o design system **Guardian Harmony** (Deep Blue + Soft Mint Green + Warm Orange, Montserrat/Inter, glassmorphic).

## Estrutura

```
public/
├── app-parent/   # Painel dos Pais — responsivo (sidebar desktop, mobile bottom-nav)
└── app-child/    # Painel Infantil — PWA mobile-first instalável
```

## Como rodar (dev)

```powershell
# Painel dos Pais
cd public/app-parent
pnpm install
pnpm dev             # http://localhost:5173

# Painel Infantil (em outro terminal)
cd public/app-child
pnpm install
pnpm dev             # http://localhost:5173 (outra porta automática se 5173 estiver em uso)
```

## Build de produção

```powershell
pnpm build           # gera dist/ pronto pra servir
pnpm preview         # serve o dist/ localmente pra conferir
```

## Status

UI estática reproduzindo os mockups Stitch com **mock data**. Sem API, sem auth, sem banco — escopo dessa entrega é visual.

### O que já está pronto

**Painel dos Pais (`app-parent`):**
- SideNav desktop + TopNav mobile + BottomNav mobile
- Hero "Bem-vindo de volta" + status do sistema
- Grid de Crianças Ativas (Lucas online + Sofia offline) com circular chart de uso e quick actions
- Solicitações Pendentes com Aprovar/Negar
- Bloqueios Recentes

**Painel Infantil (`app-child`):**
- Header com logo + notificações
- Saudação personalizada + avatar
- Anel de Tempo de Tela (45 min restantes, laranja)
- Ações Rápidas (Pedir mais tempo, Pedir site) + último pedido aprovado
- Card "Abrir Navegador Seguro"
- Agenda do Dia (School Time ativo, Play Time em 1h, Bedtime)
- BottomNav com badge de alertas
- `manifest.webmanifest` placeholder (ícones precisam ser adicionados)

## Próximos passos sugeridos

1. **Ícones PWA** — adicionar `icon-192.png` e `icon-512.png` em `app-child/public/`
2. **Service Worker** — instalar `vite-plugin-pwa` no `app-child` quando for entregar PWA offline real
3. **Conectar ao plugin WP** — substituir mock data por chamadas REST `/wp-json/guardkids/v1/*` (endpoints ainda precisam ser implementados no backend PHP)
4. **Roteamento** — quando crescer além de 1 tela, adicionar `react-router-dom`
5. **Estado global** — quando precisar compartilhar dados entre rotas, adicionar `zustand` conforme briefing

## Design tokens

Cores, tipografia, spacing e raio estão em `tailwind.config.js` de cada app, espelhando o frontmatter de `guardian_harmony/DESIGN.md` do Stitch. Material Symbols Outlined via CDN no `index.html`.
