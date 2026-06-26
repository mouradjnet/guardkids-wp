import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import {
  getCompanionStatus,
  pairCompanion,
  type CompanionPairResponse,
  type CompanionStatus,
} from '../api/companion';
import { ApiError } from '../api/client';
import { Icon } from './Icon';
import { PairQrCode } from './PairQrCode';

type Props = {
  childId: number;
  childName: string;
  onClose: () => void;
};

type StepIdx = 0 | 1 | 2 | 3 | 4;
const STEPS = [
  'Companion instalado',
  'Gerar QR',
  'Aguardar pareamento',
  'Permissões',
  'Conclusão',
];

export function CompanionWizard({ childId, childName, onClose }: Props) {
  const queryClient = useQueryClient();
  const [step, setStep] = useState<StepIdx>(0);
  const [pair, setPair] = useState<CompanionPairResponse | null>(null);
  const [confirmRePair, setConfirmRePair] = useState(false);

  const pairMutation = useMutation({
    mutationFn: () => pairCompanion(childId),
    onSuccess: (data) => {
      setPair(data);
      setStep(2);
    },
  });

  const statusQuery = useQuery({
    queryKey: ['companion', 'status', childId],
    queryFn: () => getCompanionStatus(childId),
    refetchInterval: step === 2 || step === 3 ? 5_000 : false,
    enabled: step >= 1,
  });

  const alreadyConnected = statusQuery.data?.status === 'active';

  // Re-parear um aparelho já conectado revoga a sessão dele (server-side).
  // Exige confirmação pra não desconectar o aparelho atual sem querer.
  function handleGenerate() {
    if (alreadyConnected && !confirmRePair) {
      setConfirmRePair(true);
      return;
    }
    setConfirmRePair(false);
    pairMutation.mutate();
  }

  // Avança automaticamente quando Companion conecta
  useEffect(() => {
    if (step === 2 && statusQuery.data?.status === 'active') {
      setStep(3);
    }
  }, [step, statusQuery.data?.status]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  function finish() {
    queryClient.invalidateQueries({ queryKey: ['companion', 'status', childId] });
    onClose();
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="companion-wizard-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-on-surface/40 p-4"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="glass-panel w-full max-w-lg rounded-2xl bg-surface p-6 shadow-ambient">
        <div className="flex items-start justify-between">
          <div>
            <h2 id="companion-wizard-title" className="font-display text-headline-md text-on-surface">
              Conectar Companion — {childName}
            </h2>
            <p className="mt-0.5 text-label-sm text-on-surface-variant">
              Passo {step + 1} de 5 — {STEPS[step]}
            </p>
          </div>
          <button
            type="button"
            aria-label="Fechar"
            onClick={onClose}
            className="rounded-full p-1 text-on-surface-variant hover:bg-surface-variant/50 hover:text-primary"
          >
            <Icon name="close" />
          </button>
        </div>

        <ol aria-label="Etapas do wizard" className="mt-4 flex items-center gap-2">
          {STEPS.map((_, i) => (
            <li
              key={i}
              aria-current={i === step ? 'step' : undefined}
              className={`h-1.5 flex-1 rounded-full transition-colors ${
                i <= step ? 'bg-primary' : 'bg-outline-variant/50'
              }`}
            />
          ))}
        </ol>

        <div className="mt-5 min-h-[260px]">
          {step === 0 && <StepInstall onNext={() => setStep(1)} />}
          {step === 1 && (
            <StepGenerateQR
              pending={pairMutation.isPending}
              error={pairMutation.error}
              alreadyConnected={alreadyConnected}
              confirming={confirmRePair}
              onGenerate={handleGenerate}
              onCancel={() => setConfirmRePair(false)}
            />
          )}
          {step === 2 && pair && <StepWaiting pair={pair} status={statusQuery.data} />}
          {step === 3 && statusQuery.data && (
            <StepPermissions
              status={statusQuery.data}
              onNext={() => setStep(4)}
            />
          )}
          {step === 4 && statusQuery.data && (
            <StepFinish status={statusQuery.data} onFinish={finish} />
          )}
        </div>
      </div>
    </div>
  );
}

function StepInstall({ onNext }: { onNext: () => void }) {
  return (
    <div className="space-y-4">
      <p className="text-label-md text-on-surface-variant">
        Antes de continuar, instale o <strong>GuardKids Companion</strong> no aparelho
        Android do seu filho. Esse aplicativo nativo é o que aplica os bloqueios reais
        no sistema (Device Owner, lista de apps, tela de bloqueio).
      </p>
      <div className="rounded-xl border border-tertiary-container/60 bg-tertiary-container/20 p-3 text-label-sm text-on-tertiary-fixed-variant">
        <strong>Beta:</strong> o Companion Android ainda está em desenvolvimento.
        Quando disponível, vai aparecer link de download oficial aqui. Por ora você
        pode seguir os passos pra ver o fluxo completo.
      </div>
      <button
        type="button"
        onClick={onNext}
        className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-label-md font-semibold text-white shadow-ambient hover:bg-primary-container"
      >
        Tenho o Companion instalado
        <Icon name="arrow_forward" className="text-sm" />
      </button>
    </div>
  );
}

function StepGenerateQR({
  pending,
  error,
  alreadyConnected,
  confirming,
  onGenerate,
  onCancel,
}: {
  pending: boolean;
  error: unknown;
  alreadyConnected: boolean;
  confirming: boolean;
  onGenerate: () => void;
  onCancel: () => void;
}) {
  const message =
    error instanceof ApiError
      ? `${error.message} (${error.status})`
      : error instanceof Error
        ? error.message
        : null;

  return (
    <div className="space-y-4">
      <p className="text-label-md text-on-surface-variant">
        Vamos gerar um QR Code temporário (válido por 10 minutos) com o token
        seguro pro Companion. O dispositivo só precisa apontar a câmera.
      </p>
      {message && (
        <p role="alert" className="rounded-lg bg-error/10 p-3 text-label-sm text-error">
          {message}
        </p>
      )}
      {confirming && (
        <p role="alert" className="rounded-lg bg-error/10 p-3 text-label-sm text-error">
          Este aparelho já está conectado. Gerar um novo QR vai <strong>desconectar
          o aparelho atual</strong> — ele vai precisar ser pareado de novo. Continuar?
        </p>
      )}
      <button
        type="button"
        onClick={onGenerate}
        disabled={pending}
        className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-label-md font-semibold text-white shadow-ambient hover:bg-primary-container disabled:opacity-60"
      >
        {pending ? (
          <>
            <Icon name="progress_activity" className="animate-spin text-sm" />
            Gerando…
          </>
        ) : (
          <>
            <Icon name="qr_code" className="text-sm" filled />
            {confirming
              ? 'Desconectar e gerar novo QR'
              : alreadyConnected
                ? 'Gerar novo QR'
                : 'Gerar QR Code'}
          </>
        )}
      </button>
      {confirming && (
        <button
          type="button"
          onClick={onCancel}
          disabled={pending}
          className="inline-flex w-full items-center justify-center rounded-xl py-2 text-label-md font-semibold text-on-surface-variant hover:bg-surface-variant/50"
        >
          Cancelar
        </button>
      )}
    </div>
  );
}

function StepWaiting({ pair, status }: { pair: CompanionPairResponse; status?: CompanionStatus }) {
  return (
    <div className="flex flex-col items-center gap-3">
      <p className="text-center text-label-md text-on-surface-variant">
        Aponte a câmera do Companion para o QR Code abaixo.
      </p>
      <PairQrCode value={pair.qrPayload} size={220} />
      <p className="text-center text-label-sm text-on-surface-variant">
        {status?.status === 'pending'
          ? 'Aguardando o dispositivo responder…'
          : 'Aguardando pareamento…'}
      </p>
      <p className="text-center text-label-sm text-on-surface-variant">
        Expira em 10 min. Se travar, volte e gere outro.
      </p>
    </div>
  );
}

function StepPermissions({
  status,
  onNext,
}: {
  status: CompanionStatus;
  onNext: () => void;
}) {
  const items = [
    { id: 'accessibility', label: 'Accessibility Service', ok: status.accessibilityEnabled },
    { id: 'admin', label: 'Device Admin', ok: status.deviceAdminEnabled },
    { id: 'paired', label: 'Companion conectado', ok: status.status === 'active' },
  ];
  return (
    <div className="space-y-4">
      <p className="text-label-md text-on-surface-variant">
        Confira no aparelho infantil se as permissões abaixo estão ativadas. O
        Companion deve guiar o filho por elas — você pode usar este checklist
        pra acompanhar.
      </p>
      <ul className="space-y-2">
        {items.map((item) => (
          <li
            key={item.id}
            className="flex items-center gap-3 rounded-lg bg-surface-container-low p-3"
          >
            <span
              className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${
                item.ok ? 'bg-secondary-container text-secondary' : 'border-2 border-outline-variant'
              }`}
              aria-hidden
            >
              {item.ok ? <Icon name="check" className="text-base" filled /> : null}
            </span>
            <span className={`flex-1 text-label-md ${item.ok ? 'text-on-surface-variant line-through' : 'text-on-surface'}`}>
              {item.label}
            </span>
          </li>
        ))}
      </ul>
      <button
        type="button"
        onClick={onNext}
        className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-label-md font-semibold text-white shadow-ambient hover:bg-primary-container"
      >
        Continuar
        <Icon name="arrow_forward" className="text-sm" />
      </button>
    </div>
  );
}

function StepFinish({
  status,
  onFinish,
}: {
  status: CompanionStatus;
  onFinish: () => void;
}) {
  const isOwner = status.deviceOwnerEnabled;
  return (
    <div className="flex flex-col items-center gap-3 text-center">
      <Icon
        name={isOwner ? 'verified' : 'shield'}
        className={`text-5xl ${isOwner ? 'text-secondary' : 'text-tertiary-container'}`}
        filled
      />
      <h3 className="font-display text-headline-md text-on-surface">
        {isOwner ? 'Proteção Máxima ativada' : 'Modo Avançado ativado'}
      </h3>
      <p className="max-w-md text-label-md text-on-surface-variant">
        {isOwner
          ? 'Device Owner está ativo. O Companion tem controle total do aparelho — bloqueios são aplicados em nível de sistema.'
          : 'O Companion está conectado. Para a Proteção Máxima total, você precisa habilitar Device Owner via fábrica/ADB no aparelho.'}
      </p>
      <button
        type="button"
        onClick={onFinish}
        className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-label-md font-semibold text-white shadow-ambient hover:bg-primary-container"
      >
        Concluir
        <Icon name="check" className="text-sm" filled />
      </button>
    </div>
  );
}
