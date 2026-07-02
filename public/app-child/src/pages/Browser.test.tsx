import { fireEvent, screen, waitFor } from '@testing-library/react';
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

  it('a barra de endereço começa vazia', () => {
    listAllowedSites.mockResolvedValueOnce([]);
    renderWithClient(<Browser onNavigate={() => {}} />);
    expect(screen.getByLabelText('Endereço')).toHaveValue('');
  });

  it('atualiza o texto quando o usuário digita', () => {
    listAllowedSites.mockResolvedValueOnce([]);
    renderWithClient(<Browser onNavigate={() => {}} />);
    const input = screen.getByLabelText('Endereço');
    fireEvent.change(input, { target: { value: 'youtube.com' } });
    expect(input).toHaveValue('youtube.com');
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

  it('digitar um site liberado e enviar abre o site (normaliza www/maiúsculas)', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    listAllowedSites.mockResolvedValueOnce(sampleSites);
    renderWithClient(<Browser onNavigate={() => {}} />);
    await screen.findByText('khanacademy.org'); // garante sites carregados
    fireEvent.change(screen.getByLabelText('Endereço'), { target: { value: 'www.KhanAcademy.org' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ir para o site' }));
    expect(trackSiteOpen).toHaveBeenCalledWith('khanacademy.org');
    expect(open).toHaveBeenCalledWith('https://khanacademy.org', '_blank', 'noopener,noreferrer');
    open.mockRestore();
  });

  it('digitar um site não liberado mostra aviso e o Pedir navega', async () => {
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    listAllowedSites.mockResolvedValueOnce(sampleSites);
    const onNavigate = vi.fn();
    renderWithClient(<Browser onNavigate={onNavigate} />);
    await screen.findByText('khanacademy.org');
    fireEvent.change(screen.getByLabelText('Endereço'), { target: { value: 'facebook.com' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ir para o site' }));
    expect(open).not.toHaveBeenCalled();
    expect(screen.getByText('Esse site ainda não está liberado.')).toBeInTheDocument();
    // 2 "Pedir" no DOM (o do aviso e o de "Site novo?"); o do aviso vem primeiro
    fireEvent.click(screen.getAllByRole('button', { name: 'Pedir' })[0]);
    expect(onNavigate).toHaveBeenCalledWith('requests');
    open.mockRestore();
  });

  it('o botão recarregar refaz a busca de sites', async () => {
    listAllowedSites.mockResolvedValue(sampleSites);
    renderWithClient(<Browser onNavigate={() => {}} />);
    await screen.findByText('khanacademy.org');
    expect(listAllowedSites).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'Recarregar' }));
    await waitFor(() => expect(listAllowedSites).toHaveBeenCalledTimes(2));
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
