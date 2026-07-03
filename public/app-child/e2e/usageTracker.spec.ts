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

    // Neutraliza window.open: o clique no atalho abre o site em nova aba, mas
    // no e2e não queremos navegar pra internet real (foco é o tracker).
    await page.addInitScript(() => {
      window.open = () => null;
    });

    // Stub /child/me pra Home conseguir renderizar nome do child.
    // `**` no fim casa o cache-buster (?_=<ts>) que o client anexa em GET.
    await page.route('**/wp-json/guardkids/v1/child/me**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(ME_RESPONSE),
      }),
    );

    // QuickActions (na Home) consome /child/requests; Browser consome /child/sites.
    await page.route('**/wp-json/guardkids/v1/child/requests**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
      }),
    );
    await page.route('**/wp-json/guardkids/v1/child/sites**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([{ domain: 'khanacademy.org', category: 'Educação' }]),
      }),
    );

    // Cards de gamificação na Home (ProgressCard/MissionsCard/MedalsCard).
    // Sem stub, a rota fica pendente no harness e trava a renderização da Home.
    await page.route('**/wp-json/guardkids/v1/child/progression**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          xp: 0,
          coins: 0,
          level: 1,
          xpIntoLevel: 0,
          xpForNextLevel: 100,
          streakDays: 0,
        }),
      }),
    );
    await page.route('**/wp-json/guardkids/v1/child/missions**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
      }),
    );
    await page.route('**/wp-json/guardkids/v1/child/medals**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([]),
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

  test('heartbeat pausa em hidden e retoma em visible', async ({ page }) => {
    const hits: EventBody[] = [];
    await page.route('**/wp-json/guardkids/v1/child/events', async (route) => {
      hits.push(JSON.parse(route.request().postData() ?? '{}') as EventBody);
      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({ id: hits.length, createdAt: 'now' }),
      });
    });

    await page.clock.install();
    await page.goto('/');
    await expect(page.getByText('Lucas').first()).toBeVisible({ timeout: 10_000 });

    // Simula aba escondendo (tracker pausa)
    await page.evaluate((state) => {
      Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
      document.dispatchEvent(new Event('visibilitychange'));
    }, 'hidden');

    // Avanca 120s (2 intervalos de 60s) com aba hidden — nenhum POST deve sair
    await page.clock.runFor(120_000);
    expect(hits).toHaveLength(0);

    // Aba volta visivel — visibleSince reinicia
    await page.evaluate((state) => {
      Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
      document.dispatchEvent(new Event('visibilitychange'));
    }, 'visible');

    // Avanca ate o proximo tick do interval (>=60s desde reset) — agora deve sair 1 POST
    await page.clock.runFor(65_000);

    await expect.poll(() => hits.length, { timeout: 3_000 }).toBe(1);
    expect(hits[0].type).toBe('heartbeat');
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

    // Primeiro shortcut: card mostra o domínio da whitelist real (stub /child/sites)
    await page.locator('button:has-text("khanacademy.org")').click();

    await expect.poll(() => hits.length, { timeout: 3_000 }).toBe(1);
    expect(hits[0]).toEqual({
      type: 'site_open',
      domain: 'khanacademy.org',
      duration_seconds: 0,
    });
  });
});
