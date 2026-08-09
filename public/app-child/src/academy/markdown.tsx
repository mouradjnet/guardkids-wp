import type { ReactNode } from 'react';

// Renderizador de markdown MÍNIMO e seguro para o subconjunto usado nas aulas:
// títulos (#, ##), listas (- e 1.), **negrito** e parágrafos. Constrói elementos
// React — nunca dangerouslySetInnerHTML — então não há como a fonte injetar HTML.

function renderInline(text: string, keyPrefix: string): ReactNode[] {
  return text
    .split(/(\*\*[^*]+\*\*)/g)
    .filter((part) => part !== '')
    .map((part, i) => {
      const bold = part.match(/^\*\*([^*]+)\*\*$/);
      return bold ? (
        <strong key={`${keyPrefix}-${i}`}>{bold[1]}</strong>
      ) : (
        <span key={`${keyPrefix}-${i}`}>{part}</span>
      );
    });
}

interface ListAcc {
  ordered: boolean;
  items: string[];
}

export function renderMarkdown(md: string): ReactNode[] {
  const blocks: ReactNode[] = [];
  let list: ListAcc | null = null;
  let key = 0;

  const flushList = (): void => {
    if (!list) {
      return;
    }
    const current = list;
    const items = current.items.map((item, i) => (
      <li key={`li-${key}-${i}`}>{renderInline(item, `li-${key}-${i}`)}</li>
    ));
    blocks.push(
      current.ordered ? (
        <ol key={`ol-${key++}`} className="mt-2 list-decimal space-y-1 pl-5 text-body-md text-on-surface-variant">
          {items}
        </ol>
      ) : (
        <ul key={`ul-${key++}`} className="mt-2 list-disc space-y-1 pl-5 text-body-md text-on-surface-variant">
          {items}
        </ul>
      ),
    );
    list = null;
  };

  for (const raw of md.split('\n')) {
    const line = raw.trimEnd();

    if (line.trim() === '') {
      flushList();
      continue;
    }
    if (line.startsWith('## ')) {
      flushList();
      blocks.push(
        <h3 key={`h3-${key++}`} className="mt-4 text-label-lg font-semibold text-on-surface">
          {renderInline(line.slice(3), `h3-${key}`)}
        </h3>,
      );
      continue;
    }
    if (line.startsWith('# ')) {
      flushList();
      blocks.push(
        <h2 key={`h2-${key++}`} className="font-display text-headline-sm text-on-surface">
          {renderInline(line.slice(2), `h2-${key}`)}
        </h2>,
      );
      continue;
    }

    const ordered = line.match(/^\d+\.\s+(.*)$/);
    if (ordered) {
      if (!list || !list.ordered) {
        flushList();
        list = { ordered: true, items: [] };
      }
      list.items.push(ordered[1]);
      continue;
    }
    if (line.startsWith('- ')) {
      if (!list || list.ordered) {
        flushList();
        list = { ordered: false, items: [] };
      }
      list.items.push(line.slice(2));
      continue;
    }

    flushList();
    blocks.push(
      <p key={`p-${key++}`} className="mt-2 text-body-md text-on-surface-variant">
        {renderInline(line, `p-${key}`)}
      </p>,
    );
  }

  flushList();
  return blocks;
}
