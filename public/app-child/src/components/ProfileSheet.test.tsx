import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Child } from '../api/types';
import { ProfileSheet } from './ProfileSheet';

const child: Child = {
  id: 1,
  slug: 'lucas',
  name: 'Lucas',
  age: 9,
  avatarUrl: null,
  device: 'Tablet',
  status: 'online',
  usedMinutes: 32,
  limitMinutes: 60,
};

describe('ProfileSheet', () => {
  it('mostra nome, aparelho, tempo e selo de proteção', () => {
    render(<ProfileSheet child={child} onClose={() => {}} onNavigate={() => {}} />);
    expect(screen.getByText('Lucas')).toBeInTheDocument();
    expect(screen.getByText('Tablet')).toBeInTheDocument();
    expect(screen.getByText('32/60 min')).toBeInTheDocument();
    expect(screen.getByText('Aparelho protegido')).toBeInTheDocument();
  });

  it('usa "Meu aparelho" quando device é nulo', () => {
    render(<ProfileSheet child={{ ...child, device: null }} onClose={() => {}} onNavigate={() => {}} />);
    expect(screen.getByText('Meu aparelho')).toBeInTheDocument();
  });

  it('fecha ao clicar no X', () => {
    const onClose = vi.fn();
    render(<ProfileSheet child={child} onClose={onClose} onNavigate={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: 'Fechar' }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('fecha ao clicar no backdrop', () => {
    const onClose = vi.fn();
    render(<ProfileSheet child={child} onClose={onClose} onNavigate={() => {}} />);
    fireEvent.click(screen.getByRole('dialog'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
