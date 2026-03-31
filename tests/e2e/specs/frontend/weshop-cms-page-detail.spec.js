// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
const { test, expect, gotoFrontend } = require('../../framework');

const RUNTIME_ERROR_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function ensureE2ERuntimeDirs(workspaceRoot) {
  const requiredDirs = [
    path.join(workspaceRoot, 'app', 'code', 'Weline', 'Admin', 'view', 'tpl'),
    path.join(workspaceRoot, 'tests', 'e2e', 'test-results'),
  ];

  for (const requiredDir of requiredDirs) {
    fs.mkdirSync(requiredDir, { recursive: true });
  }
}

async function expectNoRuntimeError(page) {
  await expect(page.locator('body')).not.toContainText(RUNTIME_ERROR_PATTERN, {
    timeout: 15000,
  });
}

test.describe('WeShop CMS frontend detail page', () => {
  test('create published cms page in backend then visit detail successfully', async ({ page }) => {
    const uniqueSuffix = `${Date.now()}`;
    const pageHandle = `e2e-cms-${uniqueSuffix}`;
    const pageName = `E2E CMS ${uniqueSuffix}`;
    const pageTitle = `E2E CMS Title ${uniqueSuffix}`;
    const pageContent = `E2E CMS content ${uniqueSuffix}`;

    const fixtureScript = path.resolve(__dirname, '../../framework/cms-page-fixture.php');
    const workspaceRoot = path.resolve(__dirname, '../../../..');
    ensureE2ERuntimeDirs(workspaceRoot);
    const fixtureOutput = execFileSync('php', [
      fixtureScript,
      `--handle=${pageHandle}`,
      `--name=${pageName}`,
      `--title=${pageTitle}`,
      `--content=${pageContent}`,
      '--status=1',
      '--type=custom_page',
    ], {
      cwd: workspaceRoot,
      encoding: 'utf8',
    });
    const fixture = JSON.parse(fixtureOutput);
    expect(Number(fixture.page_id)).toBeGreaterThan(0);

    const detailResponse = await gotoFrontend(page, `/cms/frontend/page/view?identifier=${fixture.handle}`, {
      waitUntil: 'domcontentloaded',
      timeout: 45000,
      settleMs: 1200,
    });
    expect(detailResponse && detailResponse.ok()).toBeTruthy();

    await expect(page).toHaveURL(/cms\/frontend\/page\/view\?identifier=/i, { timeout: 15000 });
    await expect(page.locator('body')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('body')).toContainText(fixture.title, { timeout: 15000 });
    await expect(page.locator('body')).toContainText(fixture.content, { timeout: 15000 });
    await expectNoRuntimeError(page);
  });
});
