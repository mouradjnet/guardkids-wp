import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { listChildren } from '../api/children';
import { AddChildDialog } from './AddChildDialog';
import { PairDeviceDialog } from './PairDeviceDialog';
import { Icon } from './Icon';

type OpenDialog = 'none' | 'child' | 'pair';

export function OnboardingChecklist() {
  const { data } = useQuery({ queryKey: ['children'], queryFn: listChildren });
  const [dialog, setDialog] = useState<OpenDialog>('none');

  if (!data) return null;
  const hasChild = data.length > 0;
  const hasPaired = data.some((c) => c.paired);
  if (hasChild && hasPaired) return null;

  const firstUnpaired = data.find((c) => !c.paired) ?? null;
  const done = (hasChild ? 1 : 0) + (hasPaired ? 1 : 0);

  return (
    <section aria-label="Primeiros passos" className="glass-panel rounded-2xl p-6 shadow-ambient">
      <h2 className="font-display text-headline-md text-primary">Bem-vindo ao GuardKids 👋</h2>
      <p className="mt-1 text-label-md text-on-surface-variant">
        Configure sua família em 2 passos — {done} de 2 concluídos.
      </p>
      <ol className="mt-4 space-y-3">
        <StepRow
          done={hasChild}
          title="Adicione seu primeiro filho"
          ctaLabel="Adicionar filho"
          onCta={() => setDialog('child')}
        />
        <StepRow
          done={hasPaired}
          title="Pareie um dispositivo"
          ctaLabel="Parear dispositivo"
          locked={!hasChild}
          lockedHint="Adicione um filho primeiro"
          onCta={() => setDialog('pair')}
        />
      </ol>

      <AddChildDialog open={dialog === 'child'} onClose={() => setDialog('none')} />
      {firstUnpaired && (
        <PairDeviceDialog
          open={dialog === 'pair'}
          onClose={() => setDialog('none')}
          childId={firstUnpaired.id}
          childName={firstUnpaired.name}
        />
      )}
    </section>
  );
}

function StepRow({
  done,
  title,
  ctaLabel,
  onCta,
  locked = false,
  lockedHint,
}: {
  done: boolean;
  title: string;
  ctaLabel: string;
  onCta: () => void;
  locked?: boolean;
  lockedHint?: string;
}) {
  return (
    <li className="flex items-center gap-3">
      <Icon
        name={done ? 'check_circle' : 'radio_button_unchecked'}
        filled={done}
        className={done ? 'text-secondary' : 'text-on-surface-variant'}
      />
      <span
        className={`flex-1 text-label-md ${
          done ? 'text-on-surface-variant line-through' : 'text-on-surface'
        }`}
      >
        {title}
      </span>
      {!done &&
        (locked ? (
          <span className="text-label-sm text-on-surface-variant">{lockedHint}</span>
        ) : (
          <button
            type="button"
            onClick={onCta}
            className="rounded-lg bg-primary px-3 py-1.5 text-label-sm font-semibold text-white hover:bg-primary-container"
          >
            {ctaLabel}
          </button>
        ))}
    </li>
  );
}
