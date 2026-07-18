import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SafeBrowser } from './SafeBrowser';

describe('SafeBrowser', () => {
  it('o botão navega para o Navegador Seguro (browser)', () => {
    const onNavigate = vi.fn();
    render(<SafeBrowser onNavigate={onNavigate} />);
    fireEvent.click(screen.getByRole('button', { name: /começar a navegar/i }));
    expect(onNavigate).toHaveBeenCalledWith('browser');
  });
});
