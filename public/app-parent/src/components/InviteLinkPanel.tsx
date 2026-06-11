import { useState } from 'react';
import { Icon } from './Icon';

type Props = {
  url: string;
  message?: string;
};

/**
 * Mostra o link de convite gerado com botão "Copiar".
 * Usa navigator.clipboard quando disponível; fallback pra textarea oculta
 * (cobre LocalWP em HTTP, onde a Clipboard API não existe).
 */
export function InviteLinkPanel({ url, message }: Props) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(url);
      } else {
        const ta = document.createElement('textarea');
        ta.value = url;
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

  return (
    <div className="rounded-xl border border-secondary-container/40 bg-secondary-container/10 p-3">
      <p className="mb-2 flex items-start gap-2 text-label-sm text-on-surface">
        <Icon name="link" className="mt-0.5 text-base text-secondary" />
        <span>{message ?? 'Link de convite (vale por 7 dias). Compartilhe com o guardião:'}</span>
      </p>
      <div className="flex items-center gap-2 rounded-lg border border-outline-variant bg-surface px-2 py-1.5">
        <code className="flex-1 truncate text-label-sm text-on-surface" title={url}>
          {url}
        </code>
        <button
          type="button"
          onClick={copy}
          aria-label="Copiar link"
          className="inline-flex items-center gap-1 rounded-md bg-primary px-3 py-1 text-label-sm font-semibold text-white transition-colors hover:bg-primary-container"
        >
          <Icon name={copied ? 'check' : 'content_copy'} className="text-sm" />
          {copied ? 'Copiado!' : 'Copiar'}
        </button>
      </div>
    </div>
  );
}
