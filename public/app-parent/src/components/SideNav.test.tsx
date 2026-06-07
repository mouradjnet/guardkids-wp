import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { SideNav } from './SideNav';

describe('SideNav', () => {
  it('renders all 11 nav items', () => {
    render(<SideNav activePage="dashboard" onNavigate={() => {}} />);
    expect(screen.getByRole('button', { name: /painel/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /filhos/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /localização/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /zonas seguras/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /aprovações/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sites & regras/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /limites de tempo/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /relatórios/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /configurações/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /licença/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /upgrade premium/i })).toBeInTheDocument();
  });

  it('highlights active page with bold/primary styling', () => {
    render(<SideNav activePage="approvals" onNavigate={() => {}} />);
    const approvals = screen.getByRole('button', { name: /aprovações/i });
    expect(approvals.className).toMatch(/font-bold/);
    expect(approvals.className).toMatch(/text-primary/);
  });

  it('calls onNavigate with item id when clicked', async () => {
    const onNavigate = vi.fn();
    const user = userEvent.setup();
    render(<SideNav activePage="dashboard" onNavigate={onNavigate} />);

    await user.click(screen.getByRole('button', { name: /sites & regras/i }));

    expect(onNavigate).toHaveBeenCalledWith('sites-rules');
  });

  it('renders badge "2" on Aprovações item', () => {
    render(<SideNav activePage="dashboard" onNavigate={() => {}} />);
    const approvals = screen.getByRole('button', { name: /aprovações/i });
    expect(approvals).toHaveTextContent('2');
  });

  it('CTA "Adicionar Novo Filho" navigates to children', async () => {
    const onNavigate = vi.fn();
    const user = userEvent.setup();
    render(<SideNav activePage="dashboard" onNavigate={onNavigate} />);

    await user.click(screen.getByRole('button', { name: /adicionar novo filho/i }));

    expect(onNavigate).toHaveBeenCalledWith('children');
  });

  it('renders Suporte and Sair footer links', () => {
    render(<SideNav activePage="dashboard" onNavigate={() => {}} />);
    expect(screen.getByRole('link', { name: /suporte/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /sair/i })).toBeInTheDocument();
  });
});
