import { screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Notification } from '../api/types';
import { renderWithClient } from '../test/queryClient';
import { Alerts } from './Alerts';

const listNotifications = vi.fn();
const markNotificationsRead = vi.fn();
vi.mock('../api/child', () => ({
  listNotifications: () => listNotifications(),
  markNotificationsRead: () => markNotificationsRead(),
}));

const sample: Notification[] = [
  {
    id: 1,
    type: 'request_approved',
    title: 'Seu pedido foi aprovado! 🎉',
    body: 'canva.com',
    read: false,
    createdAt: new Date().toISOString(),
  },
];

describe('Alerts', () => {
  afterEach(() => {
    listNotifications.mockReset();
    markNotificationsRead.mockReset();
  });

  it('lista as notificações reais da API', async () => {
    listNotifications.mockResolvedValueOnce(sample);
    markNotificationsRead.mockResolvedValueOnce({ updated: 1 });
    renderWithClient(<Alerts />);
    expect(await screen.findByText('Seu pedido foi aprovado! 🎉')).toBeInTheDocument();
    expect(screen.getByText('canva.com')).toBeInTheDocument();
  });

  it('mostra empty state quando não há notificações', async () => {
    listNotifications.mockResolvedValueOnce([]);
    markNotificationsRead.mockResolvedValueOnce({ updated: 0 });
    renderWithClient(<Alerts />);
    expect(await screen.findByText(/nenhum aviso/i)).toBeInTheDocument();
  });

  it('marca como lidas ao abrir', async () => {
    listNotifications.mockResolvedValueOnce(sample);
    markNotificationsRead.mockResolvedValueOnce({ updated: 1 });
    renderWithClient(<Alerts />);
    await waitFor(() => expect(markNotificationsRead).toHaveBeenCalledTimes(1));
  });
});
