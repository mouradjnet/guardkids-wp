import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import QRCode from 'qrcode';
import {
  getTwoFactorStatus,
  setupTwoFactor,
  activateTwoFactor,
  regenerateRecoveryCodes,
  disableTwoFactor,
  type TwoFactorSetup,
} from '../api/twofactor';
import { Icon } from './Icon';

type Phase = 'idle' | 'setup' | 'codes';

const inputClass =
  'mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 py-2 font-mono tracking-widest text-on-surface focus:outline-none focus:ring-2 focus:ring-primary';
const primaryBtn =
  'rounded-lg bg-primary px-4 py-2 text-label-md font-semibold text-white hover:bg-primary-container disabled:opacity-50';
const neutralBtn =
  'rounded-lg border border-outline-variant bg-surface-container px-4 py-2 text-label-md font-semibold text-on-surface hover:bg-surface-variant disabled:opacity-60';
const destructiveBtn =
  'rounded-lg border border-error/40 bg-error/10 px-4 py-2 text-label-md font-semibold text-error hover:bg-error/20 disabled:opacity-60';

export function TwoFactorSection() {
  const qc = useQueryClient();
  const status = useQuery({ queryKey: ['2fa'], queryFn: getTwoFactorStatus });

  const [phase, setPhase] = useState<Phase>('idle');
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null);
  const [qrDataUrl, setQrDataUrl] = useState<string>('');
  const [code, setCode] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [error, setError] = useState('');
  const [copied, setCopied] = useState(false);

  async function copyCodes() {
    const text = recoveryCodes.join('\n');
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
      }
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  }

  const begin = useMutation({
    mutationFn: setupTwoFactor,
    onSuccess: async (data) => {
      setSetup(data);
      setQrDataUrl(await QRCode.toDataURL(data.otpauthUri));
      setPhase('setup');
      setError('');
    },
    onError: () => setError('Não foi possível iniciar a configuração. Tente de novo.'),
  });

  const confirm = useMutation({
    mutationFn: () => activateTwoFactor(code),
    onSuccess: (data) => {
      setRecoveryCodes(data.recoveryCodes);
      setPhase('codes');
      setCode('');
      setError('');
      void qc.invalidateQueries({ queryKey: ['2fa'] });
    },
    onError: () => setError('Código inválido. Confira o app autenticador.'),
  });

  const turnOff = useMutation({
    mutationFn: () => disableTwoFactor(code),
    onSuccess: () => {
      setCode('');
      setError('');
      void qc.invalidateQueries({ queryKey: ['2fa'] });
    },
    onError: () => setError('Código inválido.'),
  });

  const regen = useMutation({
    mutationFn: () => regenerateRecoveryCodes(code),
    onSuccess: (data) => {
      setRecoveryCodes(data.recoveryCodes);
      setPhase('codes');
      setCode('');
      setError('');
      void qc.invalidateQueries({ queryKey: ['2fa'] });
    },
    onError: () => setError('Código inválido.'),
  });

  let body: ReactNode;

  if (status.isLoading) {
    body = <p className="text-label-sm text-on-surface-variant">Carregando…</p>;
  } else if (status.data?.enabled && phase !== 'codes') {
    body = (
      <>
        <p className="flex items-center gap-1.5 text-label-md font-semibold text-secondary">
          <Icon name="check_circle" className="text-base" filled />
          2FA ativada
        </p>
        <p className="text-label-sm text-on-surface-variant">
          {status.data.recoveryRemaining} códigos de recuperação restantes.
        </p>
        <input
          className={inputClass}
          aria-label="Código atual para desativar"
          placeholder="Código atual pra desativar"
          value={code}
          onChange={(e) => setCode(e.target.value)}
        />
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            className={neutralBtn}
            disabled={regen.isPending || !code}
            onClick={() => regen.mutate()}
          >
            {regen.isPending ? 'Gerando…' : 'Gerar novos códigos'}
          </button>
          <button
            type="button"
            className={destructiveBtn}
            disabled={turnOff.isPending || !code}
            onClick={() => turnOff.mutate()}
          >
            {turnOff.isPending ? 'Desativando…' : 'Desativar'}
          </button>
        </div>
        {error ? (
          <p role="alert" className="text-label-sm text-error">
            {error}
          </p>
        ) : null}
      </>
    );
  } else if (phase === 'codes') {
    body = (
      <>
        <p className="text-label-md font-bold text-on-surface">
          Guarde seus códigos de recuperação
        </p>
        <p className="text-label-sm text-on-surface-variant">
          Cada código funciona uma vez se você perder o celular. Eles não serão mostrados
          de novo.
        </p>
        <ul className="grid grid-cols-2 gap-1 rounded-lg border border-outline-variant bg-surface-container-low p-3 font-mono text-label-sm text-on-surface">
          {recoveryCodes.map((c) => (
            <li key={c}>{c}</li>
          ))}
        </ul>
        <div className="flex gap-2">
          <button type="button" className={neutralBtn} onClick={copyCodes}>
            {copied ? 'Copiado!' : 'Copiar'}
          </button>
          <button type="button" className={primaryBtn} onClick={() => setPhase('idle')}>
            Concluir
          </button>
        </div>
      </>
    );
  } else if (phase === 'setup' && setup) {
    body = (
      <>
        <p className="text-label-sm text-on-surface-variant">
          Escaneie o QR no seu app autenticador (Google Authenticator, Authy…):
        </p>
        {qrDataUrl ? (
          <img
            src={qrDataUrl}
            alt="QR code para 2FA"
            width={180}
            height={180}
            className="rounded-lg border border-outline-variant bg-white p-2"
          />
        ) : null}
        <p className="text-label-sm text-on-surface-variant">
          Ou digite a chave manual:{' '}
          <code className="font-mono text-on-surface">{setup.secret}</code>
        </p>
        <input
          className={inputClass}
          inputMode="numeric"
          autoComplete="one-time-code"
          maxLength={6}
          aria-label="Código de 6 dígitos"
          placeholder="Código de 6 dígitos"
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
        />
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            className={neutralBtn}
            onClick={() => {
              setPhase('idle');
              setSetup(null);
              setCode('');
              setError('');
            }}
          >
            Cancelar
          </button>
          <button
            type="button"
            className={primaryBtn}
            disabled={confirm.isPending || !code}
            onClick={() => confirm.mutate()}
          >
            {confirm.isPending ? 'Ativando…' : 'Confirmar e ativar'}
          </button>
        </div>
        {error ? (
          <p role="alert" className="text-label-sm text-error">
            {error}
          </p>
        ) : null}
      </>
    );
  } else {
    body = (
      <>
        <p className="text-label-sm text-on-surface-variant">
          Adicione uma segunda etapa no login com um app autenticador.
        </p>
        <button
          type="button"
          className={primaryBtn}
          disabled={begin.isPending}
          onClick={() => begin.mutate()}
        >
          {begin.isPending ? 'Preparando…' : 'Ativar 2FA'}
        </button>
        {error ? (
          <p role="alert" className="text-label-sm text-error">
            {error}
          </p>
        ) : null}
      </>
    );
  }

  return (
    <div className="rounded-xl border border-outline-variant bg-surface-container-low p-4">
      <div className="mb-3 flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-surface-container-high text-primary">
          <Icon name="verified_user" className="text-xl" filled />
        </div>
        <h4 className="text-label-md font-bold text-on-surface">
          Autenticação em duas etapas (2FA)
        </h4>
      </div>
      <div className="space-y-3">{body}</div>
    </div>
  );
}
