import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

vi.mock('./pages/Dashboard', () => ({ Dashboard: () => <div data-testid="page-dashboard" /> }));
vi.mock('./pages/Children', () => ({ Children: () => <div data-testid="page-children" /> }));
vi.mock('./pages/Approvals', () => ({ Approvals: () => <div data-testid="page-approvals" /> }));
vi.mock('./pages/SitesRules', () => ({ SitesRules: () => <div data-testid="page-sites-rules" /> }));
vi.mock('./pages/TimeLimits', () => ({ TimeLimits: () => <div data-testid="page-time" /> }));
vi.mock('./pages/Reports', () => ({ Reports: () => <div data-testid="page-reports" /> }));
vi.mock('./pages/Settings', () => ({ Settings: () => <div data-testid="page-settings" /> }));
vi.mock('./pages/License', () => ({ License: () => <div data-testid="page-license" /> }));
vi.mock('./pages/Upgrade', () => ({ Upgrade: () => <div data-testid="page-upgrade" /> }));

import { vi } from 'vitest';
import App from './App';

describe('App', () => {
  it('renders Dashboard by default', () => {
    render(<App />);
    expect(screen.getByTestId('page-dashboard')).toBeInTheDocument();
  });

  it('renders TopNav, SideNav e BottomNav', () => {
    render(<App />);
    // TopNav heading
    expect(screen.getByRole('heading', { name: /guardkids wp/i })).toBeInTheDocument();
    // SideNav user label
    expect(screen.getByText(/parent admin/i)).toBeInTheDocument();
  });

  it.each([
    ['Filhos', 'page-children'],
    ['Aprovações', 'page-approvals'],
    ['Sites & Regras', 'page-sites-rules'],
    ['Limites de Tempo', 'page-time'],
    ['Relatórios', 'page-reports'],
    ['Configurações', 'page-settings'],
    ['Licença', 'page-license'],
    ['Upgrade Premium', 'page-upgrade'],
  ])('SideNav %s navega pra %s', async (label, testId) => {
    const user = userEvent.setup();
    render(<App />);

    // SideNav e BottomNav podem compartilhar labels ("Filhos", "Aprovações");
    // SideNav vem antes no DOM, então [0] é o botão da side.
    const buttons = screen.getAllByRole('button', { name: new RegExp(`^${label}$`, 'i') });
    await user.click(buttons[0]);

    expect(screen.getByTestId(testId)).toBeInTheDocument();
  });

  it('BottomNav Regras navega pra sites-rules', async () => {
    const user = userEvent.setup();
    render(<App />);

    await user.click(screen.getByRole('button', { name: /^regras$/i }));

    expect(screen.getByTestId('page-sites-rules')).toBeInTheDocument();
  });

  it('volta pra Dashboard ao clicar em Painel', async () => {
    const user = userEvent.setup();
    render(<App />);

    await user.click(screen.getByRole('button', { name: /^configurações$/i }));
    expect(screen.getByTestId('page-settings')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /^painel$/i }));
    expect(screen.getByTestId('page-dashboard')).toBeInTheDocument();
  });
});
