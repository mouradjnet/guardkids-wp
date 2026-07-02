import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Mundo } from './Mundo';

describe('Mundo', () => {
  it('renderiza os 7 cards de seção', () => {
    render(<Mundo />);
    for (const name of ['Jogos', 'Aprender', 'Criar', 'Desafios', 'Favoritos', 'Indicados pelos Pais', 'Conquistas']) {
      expect(screen.getByText(name)).toBeInTheDocument();
    }
  });

  it('mostra a mensagem de mundo vazio', () => {
    render(<Mundo />);
    expect(screen.getByText('Seu mundo será preenchido pelo papai.')).toBeInTheDocument();
  });
});
