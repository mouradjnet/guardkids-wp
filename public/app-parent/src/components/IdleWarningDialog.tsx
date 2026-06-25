type Props = {
  secondsLeft: number;
  onStay: () => void;
  onLogout: () => void;
};

export function IdleWarningDialog({ secondsLeft, onStay, onLogout }: Props) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      role="alertdialog"
      aria-modal="true"
      aria-label="Aviso de inatividade"
    >
      <div className="w-full max-w-sm rounded-xl bg-surface-container-low p-6 shadow-xl">
        <h2 className="font-display text-headline-sm text-on-surface">Você ainda está aí?</h2>
        <p className="mt-2 text-body-md text-on-surface-variant">
          Por segurança, vamos desconectar em <strong>{secondsLeft}s</strong> por inatividade.
        </p>
        <div className="mt-5 flex flex-wrap justify-end gap-2">
          <button
            type="button"
            onClick={onLogout}
            className="rounded-lg border border-outline-variant px-4 py-2 text-label-lg text-on-surface-variant hover:bg-surface-container"
          >
            Sair agora
          </button>
          <button
            type="button"
            onClick={onStay}
            className="rounded-lg bg-primary px-4 py-2 text-label-lg text-white hover:bg-primary-container"
          >
            Continuar logado
          </button>
        </div>
      </div>
    </div>
  );
}
