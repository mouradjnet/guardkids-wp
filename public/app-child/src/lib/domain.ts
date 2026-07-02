/**
 * Reduz o que a criança digita (ou um domínio salvo) a um hostname limpo:
 * sem protocolo, sem www, sem caminho, minúsculo. Espelha
 * SiteRepository::normalizeDomain no backend PHP — os dois lados precisam
 * concordar pra o matching de site liberado funcionar.
 */
export function normalizeHost(input: string): string {
  return input
    .trim()
    .toLowerCase()
    .replace(/^https?:\/\//, '')
    .replace(/^www\./, '')
    .replace(/\/.*$/, '');
}
