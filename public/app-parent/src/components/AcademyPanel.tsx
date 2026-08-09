import { renderMarkdown } from '../academy/markdown';
import { Icon } from './Icon';

/**
 * Painel (modal) do Academy: mostra POR QUE a aula foi sugerida, o conteúdo em
 * markdown, e as duas ações — "Concluí" e "Agora não".
 *
 * Apresentacional puro: não sabe de rede. Quem dispara as mutations é o
 * AcademyButton. `role="dialog"` + `aria-modal` para o leitor de tela.
 */
export function AcademyPanel({
  title,
  reason,
  body,
  busy = false,
  onClose,
  onComplete,
  onDismiss,
}: {
  title: string;
  reason: string;
  body: string;
  busy?: boolean;
  onClose: () => void;
  onComplete: () => void;
  /** opcional: na Academia (trilhas) não há "dispensar", só "Concluir" */
  onDismiss?: () => void;
}) {
  return (
    <div
      className="fixed inset-0 z-[110] flex items-end justify-center bg-black/50 p-0 md:items-center md:p-4"
      role="dialog"
      aria-modal="true"
      aria-label={title}
    >
      <div className="flex max-h-[85vh] w-full max-w-lg flex-col rounded-t-2xl bg-surface-container-low shadow-xl md:rounded-2xl">
        <div className="flex items-start gap-3 border-b border-outline-variant p-4">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary">
            <Icon name="school" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="text-label-sm uppercase tracking-wide text-primary">Academia</p>
            <h2 className="font-display text-headline-sm text-on-surface">{title}</h2>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Fechar"
            className="shrink-0 rounded-full p-1 text-on-surface-variant hover:bg-surface-variant"
          >
            <Icon name="close" />
          </button>
        </div>

        <div className="overflow-y-auto p-4">
          <p className="rounded-lg bg-primary/10 p-3 text-body-md text-on-surface">{reason}</p>
          <div className="mt-2">{renderMarkdown(body)}</div>
        </div>

        <div className="flex flex-wrap justify-end gap-2 border-t border-outline-variant p-4">
          {onDismiss ? (
            <button
              type="button"
              onClick={onDismiss}
              disabled={busy}
              className="rounded-lg border border-outline-variant px-4 py-2 text-label-lg text-on-surface-variant hover:bg-surface-container disabled:opacity-50"
            >
              Agora não
            </button>
          ) : null}
          <button
            type="button"
            onClick={onComplete}
            disabled={busy}
            className="rounded-lg bg-primary px-4 py-2 text-label-lg text-white hover:bg-primary-container disabled:opacity-50"
          >
            Concluí
          </button>
        </div>
      </div>
    </div>
  );
}
