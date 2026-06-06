import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TopNav } from './TopNav';

describe('TopNav', () => {
  it('renders brand title', () => {
    render(<TopNav />);
    expect(screen.getByRole('heading', { name: /guardkids wp/i })).toBeInTheDocument();
  });

  it('renders Notificações and Perfil buttons', () => {
    render(<TopNav />);
    expect(screen.getByRole('button', { name: /notificações/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /perfil/i })).toBeInTheDocument();
  });
});
