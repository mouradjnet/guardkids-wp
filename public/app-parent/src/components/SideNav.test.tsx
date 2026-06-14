import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

const useCurrentRoleMock = vi.hoisted(() => vi.fn());
vi.mock('../hooks/useCurrentRole', () => ({
  useCurrentRole: useCurrentRoleMock,
}));

import { SideNav } from './SideNav';

const adminResult = {
  role: 'admin' as const,
  email: 'admin@x.com',
  name: 'Parent Admin',
  isAdmin: true,
  isCollaborator: false,
  isLoading: false,
};
const collabResult = {
  role: 'collaborator' as const,
  email: 'marina@x.com',
  name: 'Marina',
  isAdmin: false,
  isCollaborator: true,
  isLoading: false,
};

describe('SideNav', () => {
  afterEach(() => useCurrentRoleMock.mockReset());

  it('renders all 11 nav items for admin', () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
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
    useCurrentRoleMock.mockReturnValue(adminResult);
    render(<SideNav activePage="approvals" onNavigate={() => {}} />);
    const approvals = screen.getByRole('button', { name: /aprovações/i });
    expect(approvals.className).toMatch(/font-bold/);
    expect(approvals.className).toMatch(/text-primary/);
  });

  it('calls onNavigate with item id when clicked', async () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
    const onNavigate = vi.fn();
    const user = userEvent.setup();
    render(<SideNav activePage="dashboard" onNavigate={onNavigate} />);

    await user.click(screen.getByRole('button', { name: /sites & regras/i }));

    expect(onNavigate).toHaveBeenCalledWith('sites-rules');
  });

  it('renders badge "2" on Aprovações item', () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
    render(<SideNav activePage="dashboard" onNavigate={() => {}} />);
    const approvals = screen.getByRole('button', { name: /aprovações/i });
    expect(approvals).toHaveTextContent('2');
  });

  it('CTA "Adicionar Novo Filho" navigates to children', async () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
    const onNavigate = vi.fn();
    const user = userEvent.setup();
    render(<SideNav activePage="dashboard" onNavigate={onNavigate} />);

    await user.click(screen.getByRole('button', { name: /conectar dispositivo infantil/i }));

    expect(onNavigate).toHaveBeenCalledWith('children');
  });

  it('renders Suporte and Sair footer links', () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
    render(<SideNav activePage="dashboard" onNavigate={() => {}} />);
    expect(screen.getByRole('link', { name: /suporte/i })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /sair/i })).toBeInTheDocument();
  });

  it('collaborator only sees Painel + Aprovações in nav', () => {
    useCurrentRoleMock.mockReturnValue(collabResult);
    render(<SideNav activePage="dashboard" onNavigate={() => {}} />);
    expect(screen.getByRole('button', { name: /painel/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /aprovações/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /filhos/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /configurações/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /licença/i })).not.toBeInTheDocument();
  });

  it('collaborator does not see "Adicionar Novo Filho" CTA', () => {
    useCurrentRoleMock.mockReturnValue(collabResult);
    render(<SideNav activePage="dashboard" onNavigate={() => {}} />);
    expect(
      screen.queryByRole('button', { name: /adicionar novo filho/i }),
    ).not.toBeInTheDocument();
  });

  it('shows guardian name + "Colaborador" label when role=collaborator', () => {
    useCurrentRoleMock.mockReturnValue(collabResult);
    render(<SideNav activePage="dashboard" onNavigate={() => {}} />);
    expect(screen.getByText('Marina')).toBeInTheDocument();
    expect(screen.getByText(/^colaborador$/i)).toBeInTheDocument();
  });
});
