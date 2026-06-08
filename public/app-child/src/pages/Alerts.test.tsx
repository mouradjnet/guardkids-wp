import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Alerts } from './Alerts';

describe('Alerts', () => {
  it('renderiza os três alertas mockados com título, body e tempo', () => {
    render(<Alerts />);

    expect(screen.getByText('Seu pedido foi aprovado!')).toBeInTheDocument();
    expect(screen.getByText('Você ganhou +30 min para Roblox.')).toBeInTheDocument();
    expect(screen.getByText('há 3 min')).toBeInTheDocument();

    expect(screen.getByText('Play Time começa em 1 hora')).toBeInTheDocument();
    expect(screen.getByText('Tentou abrir tiktok.com')).toBeInTheDocument();
    expect(screen.getByText('há 2h')).toBeInTheDocument();
  });

  it('renderiza a descrição introdutória da página', () => {
    render(<Alerts />);
    expect(
      screen.getByText('Avisos novinhos pra você. Toca pra ver mais.'),
    ).toBeInTheDocument();
  });
});
