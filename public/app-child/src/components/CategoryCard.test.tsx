import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CategoryCard } from './CategoryCard';

describe('CategoryCard', () => {
  it('renderiza ícone, nome, descrição e contador', () => {
    render(<CategoryCard icon="school" name="Aprender" description="Conteúdos educativos" count={0} />);
    expect(screen.getByText('Aprender')).toBeInTheDocument();
    expect(screen.getByText('Conteúdos educativos')).toBeInTheDocument();
    expect(screen.getByText('0')).toBeInTheDocument();
  });

  it('mostra estado vazio quando count é 0', () => {
    render(<CategoryCard icon="school" name="Aprender" description="x" count={0} />);
    expect(screen.getByText(/em breve/i)).toBeInTheDocument();
  });
});
