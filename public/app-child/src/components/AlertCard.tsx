import { Icon } from './Icon';

/**
 * Card de aviso que aparece sobre a tela — a "notificação" que não depende de
 * push nem de permissão do navegador.
 *
 * `role="alert"` não é decoração: é o que faz o leitor de tela anunciar, e é
 * por onde o teste prova que o aviso não é silencioso.
 */
export function AlertCard({
  titulo,
  descricao,
  aprovado,
  acaoLabel,
  onAcao,
  onFechar,
}: {
  titulo: string;
  descricao: string;
  aprovado: boolean;
  acaoLabel: string;
  onAcao: () => void;
  onFechar: () => void;
}) {
  const tom = aprovado ? 'text-green-600' : 'text-on-surface-variant';

  return (
    <div
      role="alert"
      className="glass-panel fixed inset-x-4 top-4 z-[100] mx-auto flex max-w-sm items-start gap-3 rounded-2xl border border-primary/20 bg-surface p-4 shadow-ambient"
    >
      <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 ${tom}`}>
        <Icon name={aprovado ? 'celebration' : 'notifications_active'} className="text-2xl" filled />
      </div>

      <div className="min-w-0 flex-1">
        <h3 className="font-display text-label-md font-bold text-on-surface">{titulo}</h3>
        <p className="mt-0.5 truncate text-label-sm text-on-surface-variant">{descricao}</p>
        <button
          type="button"
          onClick={onAcao}
          className="mt-2 rounded-xl bg-primary px-3 py-1.5 text-label-sm font-semibold text-white"
        >
          {acaoLabel}
        </button>
      </div>

      <button
        type="button"
        onClick={onFechar}
        aria-label="Fechar aviso"
        className="shrink-0 rounded-full p-1 text-on-surface-variant"
      >
        <Icon name="close" />
      </button>
    </div>
  );
}
