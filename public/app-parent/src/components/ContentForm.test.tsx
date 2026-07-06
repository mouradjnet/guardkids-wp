import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ContentForm } from './ContentForm';

const { uploadThumbnailMock } = vi.hoisted(() => ({ uploadThumbnailMock: vi.fn() }));
vi.mock('../api/content', () => ({ uploadThumbnail: (file: File) => uploadThumbnailMock(file) }));

describe('ContentForm', () => {
  it('envia o conteúdo com faixa etária mapeada', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(
      <ContentForm
        categories={[{ id: 1, slug: 'games', name: 'Jogos', icon: null, description: null }]}
        onSubmit={onSubmit}
        onClose={() => {}}
      />,
    );
    fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Roblox' } });
    fireEvent.change(screen.getByLabelText('Faixa etária'), { target: { value: '7-9' } });
    fireEvent.click(screen.getByRole('button', { name: /salvar/i }));
    await waitFor(() =>
      expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ title: 'Roblox', ageMin: 7, ageMax: 9 })),
    );
  });

  it('mostra erro e libera o botão quando o salvamento falha', async () => {
    const onSubmit = vi.fn().mockRejectedValue(new Error('Falhou no servidor'));
    render(
      <ContentForm
        categories={[{ id: 1, slug: 'games', name: 'Jogos', icon: null, description: null }]}
        onSubmit={onSubmit}
        onClose={() => {}}
      />,
    );
    fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Roblox' } });
    fireEvent.click(screen.getByRole('button', { name: /salvar/i }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Falhou no servidor'));
    // botão não fica preso em "Salvando…"
    expect(screen.getByRole('button', { name: /^salvar$/i })).toBeEnabled();
  });

  it('faz upload da imagem e mostra o preview', async () => {
    uploadThumbnailMock.mockResolvedValue({ id: 7, url: 'https://cdn.test/foto.png' });
    const { container } = render(
      <ContentForm
        categories={[{ id: 1, slug: 'games', name: 'Jogos', icon: null, description: null }]}
        onSubmit={vi.fn().mockResolvedValue(undefined)}
        onClose={() => {}}
      />,
    );
    const file = new File(['x'], 'foto.png', { type: 'image/png' });
    const input = container.querySelector('input[type="file"]') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [file] } });

    await waitFor(() => expect(uploadThumbnailMock).toHaveBeenCalledWith(file));
    await waitFor(() => expect(screen.getByAltText('Miniatura')).toHaveAttribute('src', 'https://cdn.test/foto.png'));
  });
});
