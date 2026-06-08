import type { ReactNode } from 'react';
import { useLicense } from '../hooks/useLicense';
import { Icon } from './Icon';

type Props = {
  featureId: string;
  title?: string;
  description?: string;
  children?: ReactNode;
};

/**
 * Overlay que cobre o conteúdo quando a feature não está disponível pro plano
 * atual. Quando liberada, renderiza `children` direto (passthrough sem custo).
 *
 * Enquanto a query da licença carrega, devolve passthrough — assumir
 * indisponível faria a UI piscar quando o usuário tem premium. O Gate do PHP
 * é a barreira final; aqui é só UX.
 */
export function PremiumLock({ featureId, title, description, children }: Props) {
  const license = useLicense();

  if (license.isLoading || license.can(featureId)) {
    return <>{children}</>;
  }

  const headline = title ?? 'Disponível no Premium';
  const sub = description ?? 'Faça o upgrade pra liberar esta feature.';
  const upgradeUrl = license.upgradeUrl;

  return (
    <div className="relative">
      {children !== undefined && (
        <div
          aria-hidden="true"
          className="pointer-events-none select-none opacity-50 blur-sm"
        >
          {children}
        </div>
      )}
      <div
        role="region"
        aria-label="Feature premium"
        className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-surface/70 p-6 text-center backdrop-blur-md"
      >
        <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary text-white shadow-ambient">
          <Icon name="workspace_premium" className="text-2xl" filled />
        </div>
        <h3 className="font-display text-headline-md text-on-surface">
          {headline}
        </h3>
        <p className="max-w-sm text-label-md text-on-surface-variant">{sub}</p>
        {upgradeUrl !== null ? (
          <a
            href={upgradeUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 rounded-xl bg-primary px-5 py-3 text-label-md font-bold text-white shadow-ambient transition-colors hover:bg-primary-container"
          >
            <Icon name="bolt" className="text-sm" filled />
            Fazer upgrade
          </a>
        ) : (
          <p className="text-label-sm text-on-surface-variant">
            Configure o link de upgrade em Configurações para destravar este botão.
          </p>
        )}
      </div>
    </div>
  );
}
