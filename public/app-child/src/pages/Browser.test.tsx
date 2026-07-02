import { fireEvent, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AllowedSite } from '../api/types';
import { renderWithClient } from '../test/queryClient';
import { Browser } from './Browser';

const listAllowedSites = vi.fn();
vi.mock('../api/child', () => ({
  listAllowedSites: () => listAllowedSites(),
}));

const trackSiteOpen = vi.fn();
vi.mock('../lib/usageTracker', () => ({
  getActiveTracker: () => ({ trackSiteOpen }),
}));

const sampleSites: AllowedSite[] = [
  { domain: 'khanacademy.org', category: 'educação' },
  { domain: 'duolingo.com', category: null },
];

describe('Browser', () => {
  afterEach(() => {
    listAllowedSites.mockReset();
    trackSiteOpen.mockClear();
  });

  it('renderiza a barra de endereço com a URL inicial', () => {
    listAllowedSites.mockResolvedValueOnce([]);
    renderWithClient(<Browser onNavigate={() => {}} />);
    expect(screen.getByLabelText('Endereço')).toHaveValue('guardkids://inicio');
  });

  it('atualiza a URL quando o usuário digita', () => {
    listAllowedSites.mockResolvedValueOnce([]);
    renderWithClient(<Browser onNavigate={() => {}} />);
    const input = screen.getByLabelText('Endereço');
    fireEvent.change(input, { target: { value: 'guardkids://khan' } });
    expect(input).toHaveValue('guardkids://khan');
  });

  it('lista os sites liberados vindos da API', async () => {
    listAllowedSites.mockResolvedValueOnce(sampleSites);
    renderWithClient(<Browser onNavigate={() => {}} />);
    expect(await screen.findByText('khanacademy.org')).toBeInTheDocument();
    expect(screen.getByText('duolingo.com')).toBeInTheDocument();
    expect(screen.getByText('educação')).toBeInTheDocument();
    // categoria nula cai no rótulo padrão
    expect(screen.getByText('Site liberado')).toBeInTheDocument();
  });

  it('mostra estado vazio quando não há sites liberados', async () => {
    listAllowedSites.mockResolvedValueOnce([]);
    renderWithClient(<Browser onNavigate={() => {}} />);
    expect(await screen.findByText('Nenhum site liberado ainda')).toBeInTheDocument();
  });

  it('ao clicar no atalho: rastreia e abre o site em nova aba', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    listAllowedSites.mockResolvedValueOnce(sampleSites);
    renderWithClient(<Browser onNavigate={() => {}} />);
    fireEvent.click(await screen.findByText('khanacademy.org'));
    expect(trackSiteOpen).toHaveBeenCalledWith('khanacademy.org');
    expect(open).toHaveBeenCalledWith('https://khanacademy.org', '_blank', 'noopener,noreferrer');
    open.mockRestore();
  });

  it('domínio com protocolo é aberto sem prefixar https', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    listAllowedSites.mockResolvedValueOnce([{ domain: 'https://canva.com', category: null }]);
    renderWithClient(<Browser onNavigate={() => {}} />);
    fireEvent.click(await screen.findByText('https://canva.com'));
    expect(open).toHaveBeenCalledWith('https://canva.com', '_blank', 'noopener,noreferrer');
    open.mockRestore();
  });

  it('botão "Pedir" navega para a tela de pedidos', async () => {
    listAllowedSites.mockResolvedValueOnce([]);
    const onNavigate = vi.fn();
    renderWithClient(<Browser onNavigate={onNavigate} />);
    fireEvent.click(screen.getByRole('button', { name: 'Pedir' }));
    expect(onNavigate).toHaveBeenCalledWith('requests');
  });

  it('exibe o aviso de site seguro', () => {
    listAllowedSites.mockResolvedValueOnce([]);
    renderWithClient(<Browser onNavigate={() => {}} />);
    expect(screen.getByText('Site seguro')).toBeInTheDocument();
  });
});
