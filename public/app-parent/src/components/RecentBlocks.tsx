import { Icon } from './Icon';

export function RecentBlocks() {
  return (
    <div className="relative overflow-hidden rounded-xl border border-outline-variant/30 bg-surface-container-low/40 p-5">
      <h3 className="relative z-10 mb-4 flex items-center gap-2 text-label-md font-bold text-on-surface">
        <Icon name="security_update_warning" className="text-on-surface-variant" />
        Bloqueios Recentes
      </h3>
      <div className="relative z-10 flex flex-col items-center gap-2 py-6 text-center text-on-surface-variant">
        <Icon name="shield" className="text-3xl" />
        <p className="text-label-md font-semibold">Nenhum bloqueio recente</p>
        <p className="text-label-sm">
          Quando alguma criança tentar abrir um site bloqueado, o evento vai aparecer aqui.
        </p>
      </div>
    </div>
  );
}
