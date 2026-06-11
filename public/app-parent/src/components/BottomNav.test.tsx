import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

const useCurrentRoleMock = vi.hoisted(() => vi.fn());
vi.mock('../hooks/useCurrentRole', () => ({
  useCurrentRole: useCurrentRoleMock,
}));

import { BottomNav } from './BottomNav';

const adminResult = {
  role: 'admin' as const,
  email: 'a@x.com',
  name: 'Admin',
  isAdmin: true,
  isCollaborator: false,
  isLoading: false,
};
const collabResult = {
  role: 'collaborator' as const,
  email: 'c@x.com',
  name: 'Collab',
  isAdmin: false,
  isCollaborator: true,
  isLoading: false,
};

describe('BottomNav', () => {
  afterEach(() => useCurrentRoleMock.mockReset());

  it('renders 4 tabs (Início, Filhos, Aprovações, Regras) for admin', () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
    render(<BottomNav activePage="dashboard" onNavigate={() => {}} />);
    expect(screen.getByRole('button', { name: /início/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /filhos/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /aprovações/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /regras/i })).toBeInTheDocument();
  });

  it('highlights active tab with primary-container styling', () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
    render(<BottomNav activePage="children" onNavigate={() => {}} />);
    const filhos = screen.getByRole('button', { name: /filhos/i });
    expect(filhos.className).toMatch(/bg-primary-container/);
  });

  it('calls onNavigate with item id when tab clicked', async () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
    const onNavigate = vi.fn();
    const user = userEvent.setup();
    render(<BottomNav activePage="dashboard" onNavigate={onNavigate} />);

    await user.click(screen.getByRole('button', { name: /regras/i }));

    expect(onNavigate).toHaveBeenCalledWith('sites-rules');
  });

  it('shows red badge dot on Aprovações when not active', () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
    const { container } = render(<BottomNav activePage="dashboard" onNavigate={() => {}} />);
    const approvals = screen.getByRole('button', { name: /aprovações/i });
    expect(approvals.querySelector('span.bg-error')).not.toBeNull();
    expect(container).toBeTruthy();
  });

  it('hides badge dot on Aprovações when active', () => {
    useCurrentRoleMock.mockReturnValue(adminResult);
    render(<BottomNav activePage="approvals" onNavigate={() => {}} />);
    const approvals = screen.getByRole('button', { name: /aprovações/i });
    expect(approvals.querySelector('span.bg-error')).toBeNull();
  });

  it('collaborator only sees Início + Aprovações', () => {
    useCurrentRoleMock.mockReturnValue(collabResult);
    render(<BottomNav activePage="dashboard" onNavigate={() => {}} />);
    expect(screen.getByRole('button', { name: /início/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /aprovações/i })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /filhos/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /regras/i })).not.toBeInTheDocument();
  });
});
