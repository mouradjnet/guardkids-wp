import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { BottomNav } from './BottomNav';

describe('BottomNav badge', () => {
  it('mostra o ponto de alerta quando há não-lidas', () => {
    const { container } = render(
      <BottomNav activePage="home" onNavigate={() => {}} alertsUnread={2} />,
    );
    expect(container.querySelector('.bg-error')).toBeTruthy();
  });

  it('esconde o ponto quando não há não-lidas', () => {
    const { container } = render(
      <BottomNav activePage="home" onNavigate={() => {}} alertsUnread={0} />,
    );
    expect(container.querySelector('.bg-error')).toBeFalsy();
  });
});
