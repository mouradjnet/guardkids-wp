import { expect, test } from '@playwright/test';

const TOKEN_KEY = 'guardkids-child-token';
const FAKE_TOKEN = 'e2e-fake-token';

const ME_RESPONSE = {
  id: 1,
  slug: 'lucas',
  name: 'Lucas',
  age: 9,
  avatarUrl: null,
  device: null,
  status: 'online',
  usedMinutes: 0,
  limitMinutes: 60,
};

type EventBody = {
  type: 'heartbeat' | 'site_open';
  duration_seconds: number;
  domain?: string;
};

test.describe('Phase 5 wire-up — usageTracker no PWA real', () => {
  test.beforeEach(async ({ page }) => {
    // Pre-seed token antes do bundle subir, pra App.tsx pular PairScreen
    await page.addInitScript(({ key, value }) => {
      localStorage.setItem(key, value);
    }, { key: TOKEN_KEY, value: FAKE_TOKEN });

    // Stub /child/me pra Home conseguir renderizar nome do child
    await page.route('**/wp-json/guardkids/v1/child/me', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(ME_RESPONSE),
      }),
    );
  });

  test('heartbeat POST dispara apos 60s com aba visivel', async ({ page }) => {
    const hits: EventBody[] = [];
    await page.route('**/wp-json/guardkids/v1/child/events', async (route) => {
      hits.push(JSON.parse(route.request().postData() ?? '{}') as EventBody);
      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({ id: hits.length, createdAt: 'now' }),
      });
    });

    // Instala clock fake ANTES do goto pra setInterval ficar congelado em t=0
    await page.clock.install();
    await page.goto('/');

    // Espera Home montar (provar token foi aceito)
    await expect(page.getByText('Lucas').first()).toBeVisible({ timeout: 10_000 });

    // Avanca 61s — dispara o setInterval(60s) e o flush() emite o POST
    await page.clock.runFor(61_000);

    await expect.poll(() => hits.length, { timeout: 3_000 }).toBeGreaterThanOrEqual(1);
    expect(hits[0].type).toBe('heartbeat');
    expect(hits[0].duration_seconds).toBeGreaterThanOrEqual(55);
    expect(hits[0].duration_seconds).toBeLessThanOrEqual(61);
  });

  test('click em SiteShortcut emite site_open POST com domain certo', async ({ page }) => {
    const hits: EventBody[] = [];
    await page.route('**/wp-json/guardkids/v1/child/events', async (route) => {
      hits.push(JSON.parse(route.request().postData() ?? '{}') as EventBody);
      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({ id: hits.length, createdAt: 'now' }),
      });
    });

    await page.goto('/');
    await expect(page.getByText('Lucas').first()).toBeVisible({ timeout: 10_000 });

    // Tab Navegar no BottomNav (exact: 'Começar a Navegar' tambem existe na Home)
    await page.getByRole('button', { name: 'Navegar', exact: true }).click();

    // Primeiro shortcut: Khan Academy → khanacademy.org
    await page.locator('button:has-text("Khan Academy")').click();

    await expect.poll(() => hits.length, { timeout: 3_000 }).toBe(1);
    expect(hits[0]).toEqual({
      type: 'site_open',
      domain: 'khanacademy.org',
      duration_seconds: 0,
    });
  });
});
