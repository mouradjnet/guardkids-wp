import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ContentForm } from './ContentForm';

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
});
