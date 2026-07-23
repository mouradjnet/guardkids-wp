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
  acaoLabel,
  onAcao,
  onFechar,
}: {
  titulo: string;
  descricao: string;
  acaoLabel: string;
  onAcao: () => void;
  onFechar: () => void;
}) {
  return (
    <div
      role="alert"
      className="fixed inset-x-4 top-4 z-[100] mx-auto flex max-w-md items-start gap-3 rounded-2xl border border-outline-variant bg-surface p-4 shadow-lg md:left-auto md:right-6 md:mx-0"
    >
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary">
        <Icon name="notifications_active" />
      </span>

      <div className="min-w-0 flex-1">
        <p className="text-label-lg font-semibold text-on-surface">{titulo}</p>
        <p className="mt-0.5 truncate text-label-sm text-on-surface-variant">{descricao}</p>
        <button
          type="button"
          onClick={onAcao}
          className="mt-2 rounded-lg bg-primary px-3 py-1.5 text-label-sm font-medium text-on-primary"
        >
          {acaoLabel}
        </button>
      </div>

      <button
        type="button"
        onClick={onFechar}
        aria-label="Fechar aviso"
        className="shrink-0 rounded-full p-1 text-on-surface-variant hover:bg-surface-variant"
      >
        <Icon name="close" />
      </button>
    </div>
  );
}
