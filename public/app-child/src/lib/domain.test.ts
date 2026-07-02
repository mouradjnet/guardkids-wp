import { describe, expect, it } from 'vitest';
import { normalizeHost } from './domain';

describe('normalizeHost', () => {
  it.each([
    ['youtube.com', 'youtube.com'],
    ['https://youtube.com', 'youtube.com'],
    ['http://exemplo.com', 'exemplo.com'],
    ['www.roblox.com', 'roblox.com'],
    ['https://www.canva.com', 'canva.com'],
    ['https://canva.com/design/play', 'canva.com'],
    ['YouTube.COM', 'youtube.com'],
    ['  youtube.com  ', 'youtube.com'],
    ['', ''],
  ])('normaliza %s → %s', (input, expected) => {
    expect(normalizeHost(input)).toBe(expected);
  });
});
