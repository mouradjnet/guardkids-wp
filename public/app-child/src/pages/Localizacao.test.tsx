import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Localizacao } from './Localizacao';

type GeoCallback = (pos: GeolocationPosition) => void;
type GeoErrorCallback = (err: GeolocationPositionError) => void;

function mockPermissions(state: PermissionState | 'reject') {
  const query = vi.fn().mockImplementation(() => {
    if (state === 'reject') return Promise.reject(new Error('not supported'));
    return Promise.resolve({ state } as PermissionStatus);
  });
  Object.defineProperty(navigator, 'permissions', {
    configurable: true,
    value: { query },
  });
  return query;
}

function mockGeolocation(handler: (
  success: GeoCallback,
  error: GeoErrorCallback,
) => void) {
  const getCurrentPosition = vi.fn().mockImplementation(handler);
  Object.defineProperty(navigator, 'geolocation', {
    configurable: true,
    value: { getCurrentPosition },
  });
  return getCurrentPosition;
}

describe('Localizacao', () => {
  beforeEach(() => {
    mockPermissions('prompt');
    mockGeolocation(() => {});
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('mostra o ShareCard como estado inicial quando permission é prompt', async () => {
    render(<Localizacao />);
    expect(
      await screen.findByRole('button', { name: /permitir localização/i }),
    ).toBeInTheDocument();
    expect(screen.getByText(/compartilhar localização/i)).toBeInTheDocument();
  });

  it('exibe ActiveCard quando a permission já está granted', async () => {
    mockPermissions('granted');
    render(<Localizacao />);
    expect(await screen.findByText('Localização ativa')).toBeInTheDocument();
  });

  it('exibe DeniedCard quando a permission já está denied', async () => {
    mockPermissions('denied');
    render(<Localizacao />);
    expect(await screen.findByText('Permissão negada')).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: /tentar novamente/i }),
    ).toBeInTheDocument();
  });

  it('ao clicar em permitir, chama geolocation e renderiza ActiveCard no sucesso', async () => {
    const getCurrentPosition = mockGeolocation((success) => {
      success({ coords: {} } as GeolocationPosition);
    });
    render(<Localizacao />);
    fireEvent.click(
      await screen.findByRole('button', { name: /permitir localização/i }),
    );
    expect(getCurrentPosition).toHaveBeenCalledTimes(1);
    await waitFor(() =>
      expect(screen.getByText('Localização ativa')).toBeInTheDocument(),
    );
  });

  it('ao clicar em permitir, exibe DeniedCard quando o navegador nega', async () => {
    mockGeolocation((_success, error) => {
      error({ code: 1 } as GeolocationPositionError);
    });
    render(<Localizacao />);
    fireEvent.click(
      await screen.findByRole('button', { name: /permitir localização/i }),
    );
    await waitFor(() =>
      expect(screen.getByText('Permissão negada')).toBeInTheDocument(),
    );
  });

  it('mantém ShareCard quando navigator.permissions.query rejeita (fallback unknown)', async () => {
    mockPermissions('reject');
    render(<Localizacao />);
    expect(
      await screen.findByRole('button', { name: /permitir localização/i }),
    ).toBeInTheDocument();
  });
});
