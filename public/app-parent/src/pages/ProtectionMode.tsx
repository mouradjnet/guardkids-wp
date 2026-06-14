import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { listChildren } from '../api/children';
import {
  getProtectionMode,
  setProtectionMode,
  type ProtectionMode,
} from '../api/companion';
import { ApiError } from '../api/client';
import { CompanionStatusCard } from '../components/CompanionStatusCard';
import { CompanionWizard } from '../components/CompanionWizard';
import { Icon } from '../components/Icon';
import { PageHeader } from '../components/PageHeader';

export function ProtectionMode() {
  const queryClient = useQueryClient();
  const modeQ = useQuery({ queryKey: ['protection-mode'], queryFn: getProtectionMode });
  const childrenQ = useQuery({ queryKey: ['children'], queryFn: listChildren });

  const setMode = useMutation({
    mutationFn: (mode: ProtectionMode) => setProtectionMode(mode),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['protection-mode'] }),
  });

  const [wizard, setWizard] = useState<{ open: true; childId: number; childName: string } | { open: false }>({ open: false });

  const mode = modeQ.data?.mode ?? 'family';

  return (
    <main className="mx-auto flex w-full max-w-[1440px] flex-1 flex-col gap-stack-lg p-container-padding-mobile pb-24 md:ml-64 md:p-container-padding-desktop md:pb-container-padding-desktop">
      <PageHeader
        title="Modo de Proteção"
        subtitle="Escolha o nível de proteção da família. Modo Avançado depende do Companion Android."
      />

      <div className="grid grid-cols-1 gap-gutter md:grid-cols-2">
        <ModeCard
          mode="family"
          selected={mode === 'family'}
          icon="family_restroom"
          tone="primary"
          title="Modo Familiar"
          level="8,5/10"
          description="Controle de tempo, aprovação de pedidos, sites permitidos/bloqueados, relatórios, localização, zonas seguras e comunicação pais ↔ filhos."
          features={[
            'Controle de tempo de tela',
            'Aprovação de pedidos',
            'Sites permitidos e bloqueados',
            'Relatórios + Localização',
            'Zonas seguras',
          ]}
          disabled={setMode.isPending}
          onSelect={() => setMode.mutate('family')}
        />
        <ModeCard
          mode="maximum"
          selected={mode === 'maximum'}
          icon="shield"
          tone="secondary"
          title="Proteção Máxima"
          level="9,5–10/10"
          description="Controle avançado do aparelho Android utilizando o GuardKids Companion."
          features={[
            'Bloqueio de aplicativos e Play Store',
            'Lista de apps permitidos',
            'Tela de bloqueio personalizada',
            'Bloqueio após limites atingidos',
            'Aplicação automática das regras',
          ]}
          footnote="Requer instalação do Companion Android."
          disabled={setMode.isPending}
          onSelect={() => setMode.mutate('maximum')}
        />
      </div>

      {setMode.error && (
        <p role="alert" className="rounded-lg bg-error/10 p-3 text-label-sm text-error">
          {setMode.error instanceof ApiError
            ? `${setMode.error.message} (${setMode.error.status})`
            : setMode.error instanceof Error
              ? setMode.error.message
              : 'Erro desconhecido.'}
        </p>
      )}

      {mode === 'maximum' && childrenQ.data && childrenQ.data.length > 0 && (
        <section className="space-y-stack-md">
          <h3 className="font-display text-headline-md text-on-surface">
            Companion por filho
          </h3>
          <div className="grid grid-cols-1 gap-gutter lg:grid-cols-2">
            {childrenQ.data.map((c) => (
              <div key={c.id} className="space-y-3">
                <CompanionStatusCard childId={c.id} childName={c.name} />
                <button
                  type="button"
                  onClick={() => setWizard({ open: true, childId: c.id, childName: c.name })}
                  className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-outline-variant bg-surface-container-low py-3 text-label-md font-semibold text-on-surface hover:bg-surface-variant"
                >
                  <Icon name="qr_code_scanner" className="text-sm" />
                  Conectar Companion
                </button>
              </div>
            ))}
          </div>
        </section>
      )}

      {wizard.open && (
        <CompanionWizard
          childId={wizard.childId}
          childName={wizard.childName}
          onClose={() => setWizard({ open: false })}
        />
      )}
    </main>
  );
}

type ModeCardProps = {
  mode: ProtectionMode;
  selected: boolean;
  icon: string;
  tone: 'primary' | 'secondary';
  title: string;
  level: string;
  description: string;
  features: string[];
  footnote?: string;
  disabled: boolean;
  onSelect: () => void;
};

function ModeCard({
  selected,
  icon,
  tone,
  title,
  level,
  description,
  features,
  footnote,
  disabled,
  onSelect,
}: ModeCardProps) {
  const toneRing = tone === 'primary' ? 'border-primary' : 'border-secondary';
  const toneBg = tone === 'primary' ? 'bg-primary/10 text-primary' : 'bg-secondary-container/40 text-secondary';
  return (
    <article
      className={`glass-panel flex flex-col gap-3 rounded-2xl border-2 p-6 shadow-ambient transition-colors ${
        selected ? toneRing : 'border-transparent'
      }`}
    >
      <header className="flex items-center gap-3">
        <div className={`flex h-11 w-11 items-center justify-center rounded-xl ${toneBg}`}>
          <Icon name={icon} className="text-2xl" filled />
        </div>
        <div className="flex-1">
          <div className="flex items-center gap-2">
            <h3 className="font-display text-headline-md text-on-surface">{title}</h3>
            {selected && (
              <span className="rounded-full bg-secondary-container/40 px-2 py-0.5 text-label-sm font-semibold text-secondary">
                Ativo
              </span>
            )}
          </div>
          <p className="text-label-sm text-on-surface-variant">Nível {level}</p>
        </div>
      </header>
      <p className="text-label-md text-on-surface-variant">{description}</p>
      <ul className="space-y-1 text-label-sm text-on-surface-variant">
        {features.map((f) => (
          <li key={f} className="flex items-start gap-2">
            <Icon name="check_circle" className="mt-0.5 text-sm text-secondary" filled />
            <span>{f}</span>
          </li>
        ))}
      </ul>
      {footnote && (
        <p className="rounded-lg bg-tertiary-container/30 px-3 py-2 text-label-sm text-on-tertiary-fixed-variant">
          {footnote}
        </p>
      )}
      <button
        type="button"
        onClick={onSelect}
        disabled={disabled || selected}
        className={`mt-auto inline-flex items-center justify-center gap-2 rounded-xl py-3 text-label-md font-semibold shadow-ambient transition-colors disabled:opacity-60 ${
          selected
            ? 'bg-surface-container-high text-on-surface'
            : tone === 'primary'
              ? 'bg-primary text-white hover:bg-primary-container'
              : 'bg-secondary text-on-secondary hover:bg-secondary-container'
        }`}
      >
        {selected ? 'Modo ativo' : 'Ativar'}
      </button>
    </article>
  );
}
