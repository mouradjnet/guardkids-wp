import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Browser } from './Browser';

const trackSiteOpen = vi.fn();
vi.mock('../lib/usageTracker', () => ({
  getActiveTracker: () => ({ trackSiteOpen }),
}));

describe('Browser', () => {
  afterEach(() => {
    trackSiteOpen.mockClear();
  });

  it('renderiza a barra de endereço com a URL inicial', () => {
    render(<Browser />);
    expect(screen.getByLabelText('Endereço')).toHaveValue('guardkids://inicio');
  });

  it('atualiza a URL quando o usuário digita', () => {
    render(<Browser />);
    const input = screen.getByLabelText('Endereço');
    fireEvent.change(input, { target: { value: 'guardkids://khan' } });
    expect(input).toHaveValue('guardkids://khan');
  });

  it('lista os 4 sites favoritos do mock', () => {
    render(<Browser />);
    expect(screen.getByText('Khan Academy')).toBeInTheDocument();
    expect(screen.getByText('YouTube Kids')).toBeInTheDocument();
    expect(screen.getByText('Duolingo')).toBeInTheDocument();
    expect(screen.getByText('Roblox')).toBeInTheDocument();
  });

  it('rastreia abertura do site via usageTracker ao clicar no atalho', () => {
    render(<Browser />);
    fireEvent.click(screen.getByText('Khan Academy'));
    expect(trackSiteOpen).toHaveBeenCalledWith('khanacademy.org');
  });

  it('exibe o aviso de site seguro', () => {
    render(<Browser />);
    expect(screen.getByText('Site seguro')).toBeInTheDocument();
  });
});
