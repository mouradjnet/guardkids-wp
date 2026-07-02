import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { EmptyState } from './EmptyState';

describe('EmptyState', () => {
  it('renderiza a mensagem', () => {
    render(<EmptyState icon="public" message="Seu mundo será preenchido pelo papai." />);
    expect(screen.getByText('Seu mundo será preenchido pelo papai.')).toBeInTheDocument();
  });
});
