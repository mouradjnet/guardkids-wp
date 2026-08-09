import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderMarkdown } from './markdown';

function renderMd(md: string) {
  return render(<div>{renderMarkdown(md)}</div>);
}

describe('renderMarkdown', () => {
  it('renderiza # como heading nível 2 e ## como nível 3', () => {
    renderMd('# Título\n\n## Subtítulo');
    expect(screen.getByRole('heading', { level: 2, name: 'Título' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 3, name: 'Subtítulo' })).toBeInTheDocument();
  });

  it('agrupa itens "-" em uma lista não ordenada', () => {
    const { container } = renderMd('- um\n- dois');
    const ul = container.querySelector('ul');
    expect(ul).not.toBeNull();
    expect(ul?.querySelectorAll('li')).toHaveLength(2);
  });

  it('agrupa itens "1." em uma lista ordenada', () => {
    const { container } = renderMd('1. primeiro\n2. segundo');
    const ol = container.querySelector('ol');
    expect(ol).not.toBeNull();
    expect(ol?.querySelectorAll('li')).toHaveLength(2);
  });

  it('renderiza **negrito** inline como <strong>', () => {
    const { container } = renderMd('Isto é **importante** mesmo.');
    const strong = container.querySelector('strong');
    expect(strong?.textContent).toBe('importante');
  });

  it('linha comum vira parágrafo', () => {
    renderMd('Um parágrafo simples.');
    expect(screen.getByText('Um parágrafo simples.')).toBeInTheDocument();
  });

  it('linha em branco fecha a lista (dois blocos separados)', () => {
    const { container } = renderMd('- a\n\n- b');
    expect(container.querySelectorAll('ul')).toHaveLength(2);
  });
});
