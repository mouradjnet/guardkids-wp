import { Icon } from '../components/Icon';

const alerts = [
  {
    id: 'a1',
    icon: 'check_circle',
    tone: 'mint' as const,
    title: 'Seu pedido foi aprovado!',
    body: 'Você ganhou +30 min para Roblox.',
    whenLabel: 'há 3 min',
  },
  {
    id: 'a2',
    icon: 'schedule',
    tone: 'orange' as const,
    title: 'Play Time começa em 1 hora',
    body: 'Prepara o controle, vai começar logo!',
    whenLabel: 'há 25 min',
  },
  {
    id: 'a3',
    icon: 'block',
    tone: 'error' as const,
    title: 'Tentou abrir tiktok.com',
    body: 'Esse site não tá liberado. Pede pros seus pais se precisar muito.',
    whenLabel: 'há 2h',
  },
];

const toneMap = {
  mint: { bg: 'bg-secondary-container/40', text: 'text-secondary' },
  orange: { bg: 'bg-orange-warm/15', text: 'text-orange-warm' },
  error: { bg: 'bg-error-container/60', text: 'text-error' },
};

export function Alerts() {
  return (
    <main className="flex flex-1 flex-col gap-stack-md px-container-padding-mobile py-stack-md">
      <p className="px-1 text-label-md text-on-surface-variant">
        Avisos novinhos pra você. Toca pra ver mais.
      </p>
      <div className="glass-panel rounded-2xl shadow-ambient">
        <ul className="divide-y divide-outline-variant/50">
          {alerts.map((a) => {
            const tone = toneMap[a.tone];
            return (
              <li key={a.id} className="flex items-start gap-3 p-4">
                <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${tone.bg}`}>
                  <Icon name={a.icon} className={tone.text} filled />
                </div>
                <div className="flex-1">
                  <div className="text-label-md font-semibold text-on-surface">{a.title}</div>
                  <div className="text-label-sm text-on-surface-variant">{a.body}</div>
                </div>
                <span className="text-label-sm text-on-surface-variant">{a.whenLabel}</span>
              </li>
            );
          })}
        </ul>
      </div>
    </main>
  );
}
