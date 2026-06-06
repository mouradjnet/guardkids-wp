import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { BottomNav } from './BottomNav';

describe('BottomNav', () => {
  it('renders 4 tabs (Início, Filhos, Aprovações, Regras)', () => {
    render(<BottomNav activePage="dashboard" onNavigate={() => {}} />);
    expect(screen.getByRole('button', { name: /início/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /filhos/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /aprovações/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /regras/i })).toBeInTheDocument();
  });

  it('highlights active tab with primary-container styling', () => {
    render(<BottomNav activePage="children" onNavigate={() => {}} />);
    const filhos = screen.getByRole('button', { name: /filhos/i });
    expect(filhos.className).toMatch(/bg-primary-container/);
  });

  it('calls onNavigate with item id when tab clicked', async () => {
    const onNavigate = vi.fn();
    const user = userEvent.setup();
    render(<BottomNav activePage="dashboard" onNavigate={onNavigate} />);

    await user.click(screen.getByRole('button', { name: /regras/i }));

    expect(onNavigate).toHaveBeenCalledWith('sites-rules');
  });

  it('shows red badge dot on Aprovações when not active', () => {
    const { container } = render(<BottomNav activePage="dashboard" onNavigate={() => {}} />);
    const approvals = screen.getByRole('button', { name: /aprovações/i });
    expect(approvals.querySelector('span.bg-error')).not.toBeNull();
    expect(container).toBeTruthy();
  });

  it('hides badge dot on Aprovações when active', () => {
    render(<BottomNav activePage="approvals" onNavigate={() => {}} />);
    const approvals = screen.getByRole('button', { name: /aprovações/i });
    expect(approvals.querySelector('span.bg-error')).toBeNull();
  });
});
