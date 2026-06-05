import { Icon } from './Icon';

export function HeroWelcome() {
  return (
    <section className="glass-panel relative flex flex-col items-start justify-between gap-6 overflow-hidden rounded-2xl bg-gradient-to-br from-surface to-surface-container-low p-6 md:flex-row md:items-center md:p-8">
      <div className="relative z-10 max-w-xl">
        <h2 className="mb-2 font-display text-headline-lg-mobile font-bold text-primary md:text-headline-lg">
          Bem-vindo de volta!
        </h2>
        <p className="text-body-md text-on-surface-variant">
          Tudo seguro. Seus filhos passaram em média 1h30 online hoje. Nenhum alerta
          crítico foi detectado.
        </p>
      </div>
      <div className="relative z-10 flex w-full gap-4 md:w-auto">
        <div className="flex flex-1 items-center gap-4 rounded-xl border border-outline-variant bg-white p-4 shadow-sm md:flex-none">
          <div className="flex items-center justify-center rounded-full bg-secondary-container p-3 text-on-secondary-container">
            <Icon name="verified_user" />
          </div>
          <div>
            <div className="text-label-sm text-on-surface-variant">Status do Sistema</div>
            <div className="text-label-md font-bold text-on-surface">Seguro e Ativo</div>
          </div>
        </div>
      </div>
      <div className="pointer-events-none absolute -right-20 -top-20 h-64 w-64 rounded-full bg-primary-container opacity-5 blur-3xl" />
    </section>
  );
}
