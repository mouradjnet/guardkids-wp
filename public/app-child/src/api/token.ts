const KEY = 'guardkids-child-token';

export function getStoredToken(): string | null {
  try {
    return localStorage.getItem(KEY);
  } catch {
    return null;
  }
}

export function setStoredToken(token: string): void {
  try {
    localStorage.setItem(KEY, token);
  } catch {
    // localStorage indisponível (Safari private mode etc.) — ignora silenciosamente
  }
}

export function clearStoredToken(): void {
  try {
    localStorage.removeItem(KEY);
  } catch {
    // idem
  }
}
