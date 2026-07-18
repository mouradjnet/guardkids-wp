import { describe, expect, it } from 'vitest';
import { canAccessPage, COLLAB_ALLOWED_PAGES } from './roleAccess';

describe('roleAccess.canAccessPage', () => {
  it('collaborator só acessa dashboard e approvals', () => {
    expect(canAccessPage('collaborator', 'dashboard')).toBe(true);
    expect(canAccessPage('collaborator', 'approvals')).toBe(true);
    expect(canAccessPage('collaborator', 'children')).toBe(false);
    expect(canAccessPage('collaborator', 'settings')).toBe(false);
    expect(canAccessPage('collaborator', 'upgrade')).toBe(false);
    expect(canAccessPage('collaborator', 'license')).toBe(false);
  });

  it('admin acessa qualquer página', () => {
    const pages = ['dashboard', 'children', 'settings', 'upgrade', 'license', 'protection'] as const;
    for (const p of pages) {
      expect(canAccessPage('admin', p)).toBe(true);
    }
  });

  it('null (loading/anônimo) fica permissivo — o backend é quem corta o acesso real', () => {
    expect(canAccessPage(null, 'settings')).toBe(true);
    expect(canAccessPage(null, 'dashboard')).toBe(true);
  });

  it('COLLAB_ALLOWED_PAGES é exatamente dashboard + approvals', () => {
    expect([...COLLAB_ALLOWED_PAGES]).toEqual(['dashboard', 'approvals']);
  });
});
