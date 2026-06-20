const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
} = require('../../framework');

const MODULE = 'Weline_Server';
const FILE_MANAGER_MODULE = 'Weline_FileManager';
const DB_MANAGER_MODULE = 'Weline_DbManager';
const PHP_MANAGER_MODULE = 'Weline_PhpManager';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ARTIFACT_DIR = path.resolve(__dirname, '../../artifacts/backend/Weline_Server');
const REPO_ROOT = path.resolve(__dirname, '../../../..');
const VAR_ROOT = path.join(REPO_ROOT, 'var');
const BUNDLED_PHP_INI_PATH = path.join(REPO_ROOT, 'extend', 'server', 'php', 'php.ini');
const SECURITY_RULES_PATH = path.join(REPO_ROOT, 'var', 'server', 'security-rules.json');
const ENV_PATH = path.join(REPO_ROOT, 'app', 'etc', 'env.php');

function artifactPath(name) {
  fs.mkdirSync(ARTIFACT_DIR, { recursive: true });
  return path.join(ARTIFACT_DIR, name);
}

function removeVarTestDirectory(name) {
  const target = path.resolve(VAR_ROOT, name);
  if (target === VAR_ROOT || !target.startsWith(VAR_ROOT + path.sep)) {
    throw new Error(`Refusing to clean unsafe FileManager E2E path: ${target}`);
  }
  fs.rmSync(target, { recursive: true, force: true });
}

async function withBundledPhpIniSnapshot(callback) {
  const original = fs.existsSync(BUNDLED_PHP_INI_PATH)
    ? fs.readFileSync(BUNDLED_PHP_INI_PATH, 'utf8')
    : null;
  try {
    await callback();
  } finally {
    if (original !== null) {
      fs.writeFileSync(BUNDLED_PHP_INI_PATH, original);
    }
  }
}

async function withSecurityRulesSnapshot(callback) {
  const original = fs.existsSync(SECURITY_RULES_PATH)
    ? fs.readFileSync(SECURITY_RULES_PATH, 'utf8')
    : null;
  try {
    await callback();
  } finally {
    if (original !== null) {
      fs.writeFileSync(SECURITY_RULES_PATH, original);
    } else {
      fs.rmSync(SECURITY_RULES_PATH, { force: true });
    }
  }
}

async function withEnvSnapshot(callback) {
  const original = fs.existsSync(ENV_PATH)
    ? fs.readFileSync(ENV_PATH, 'utf8')
    : null;
  try {
    await callback();
  } finally {
    if (original !== null) {
      fs.writeFileSync(ENV_PATH, original);
    }
  }
}

async function expectNoHorizontalOverflow(page) {
  const metrics = await page.evaluate(() => ({
    htmlScrollWidth: document.documentElement.scrollWidth,
    htmlClientWidth: document.documentElement.clientWidth,
    bodyScrollWidth: document.body.scrollWidth,
    bodyClientWidth: document.body.clientWidth,
  }));
  expect(metrics.htmlScrollWidth).toBeLessThanOrEqual(metrics.htmlClientWidth + 2);
  expect(metrics.bodyScrollWidth).toBeLessThanOrEqual(metrics.bodyClientWidth + 2);
}

moduleDescribe(test, MODULE, 'WLS Panel shell', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'WLS-PANEL-SHELL-RESPONSIVE-001' },
    'independent shell supports responsive layout and theme switching',
    async ({ page }) => {
      await loginAsAdmin(page);
      await page.evaluate(() => window.localStorage.removeItem('wls_panel_theme'));

      await page.setViewportSize({ width: 1440, height: 900 });
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'wls-panel'), {
        timeout: 30000,
        settleMs: 500,
      });

      const body = page.locator('body');
      await expect(body).not.toContainText(FATAL_PATTERN);
      expect(page.url()).not.toContain('/admin/login');

      const shell = page.locator('.wls-standalone-shell[data-wls-shell="standalone"]');
      await expect(shell).toBeVisible();
      await expect(page.locator('.wls-shell-sidebar')).toBeVisible();
      await expect(page.locator('[data-wls-main]')).toBeVisible();
      await expect(page.locator('.wls-gateway-config-form')).toBeVisible();
      await expect(page.locator('input[name="gateway_listen"]')).toHaveValue(/.+:\d+/);
      const trafficModeSelect = page.locator('select[name="gateway_traffic_mode"]');
      await expect(trafficModeSelect).toBeVisible();
      await expect(trafficModeSelect).toHaveValue(/^(auto|direct_listen|passthrough)$/);
      const trafficModeValues = await trafficModeSelect.locator('option').evaluateAll((options) => options.map((option) => option.value));
      expect(trafficModeValues).toEqual(['auto', 'direct_listen', 'passthrough']);
      await expect(page.locator('select[name="runtime_action"]')).toHaveValue('reload');
      await expect(page.locator('.wls-gateway-config-form button[type="submit"]')).toBeVisible();
      await expect(page.locator('[data-wls-operation-capabilities]')).toBeVisible();
      await expect(page.locator('[data-wls-operation-card]')).toHaveCount(4);
      const installedOperationCount = Number(await page.locator('[data-wls-operation-capabilities]').getAttribute('data-wls-operation-installed-count'));
      expect(installedOperationCount).toBeGreaterThanOrEqual(2);
      await expect(page.locator('[data-operation-key="php-profile"]')).toContainText('custom:wls-php-manager');
      await expect(page.locator('[data-operation-key="database-profile"]')).toContainText('custom:wls-database-manager');
      await expect(page.locator('[data-operation-key="file-manager"]')).toContainText('custom:wls-file-manager');
      await expect(page.locator('[data-operation-key="deploy"]')).toContainText('custom:wls-deploy');
      await expect(page.locator('[data-operation-key="database-profile"]')).toHaveClass(/is-installed/);
      await expect(page.locator('[data-operation-key="php-profile"]')).toHaveClass(/is-installed/);
      await expect(page.locator('[data-operation-key="deploy"]')).toHaveClass(/is-installed/);
      await expect(page.locator('[data-wls-plugin-contributions]')).toBeVisible();
      await expect(page.locator('[data-wls-plugin-contributions]')).toHaveAttribute(
        'data-wls-plugin-contribution-count',
        /\d+/,
      );
      await expect(page.locator('[data-wls-plugin-nav][data-plugin-module="Weline_Deploy"]')).toBeVisible();
      await expect(page.locator('[data-wls-plugin-nav][data-plugin-module="Weline_PhpManager"]')).toBeVisible();
      const configCenter = page.locator('[data-wls-project-config-center]');
      await expect(configCenter).toBeVisible();
      await expect(configCenter).toHaveAttribute('data-wls-project-config-count', /\d+/);
      const firstConfigCard = configCenter.locator('[data-wls-project-config-card]').first();
      await expect(firstConfigCard).toBeVisible();
      await expect(firstConfigCard.locator('[data-wls-config-action="admin"]')).toBeVisible();
      await expect(firstConfigCard.locator('[data-wls-config-action="panel"]')).toBeVisible();
      await expect(firstConfigCard.locator('[data-wls-config-action="security"]')).toBeVisible();
      await expect(firstConfigCard.locator('[data-wls-config-action="gateway"]')).toBeVisible();
      await expect(firstConfigCard.locator('[data-wls-config-operation]')).toHaveCount(4);
      await expect(firstConfigCard.locator('[data-wls-config-operation="php-profile"]')).toBeVisible();
      await expect(firstConfigCard.locator('[data-wls-config-operation="database-profile"]')).toBeVisible();
      await expect(firstConfigCard.locator('[data-wls-config-operation="file-manager"]')).toBeVisible();
      await expect(firstConfigCard.locator('[data-wls-config-operation="deploy"]')).toBeVisible();
      const configActionHrefs = await firstConfigCard.locator('a').evaluateAll((links) =>
        links.map((link) => link.getAttribute('href') || ''),
      );
      expect(configActionHrefs.every((href) => href.length > 0)).toBeTruthy();
      expect(configActionHrefs.every((href) => !href.includes('project_path'))).toBeTruthy();
      const firstProjectCard = page.locator('.wls-project-card').first();
      await expect(firstProjectCard).toBeVisible();
      await expect(firstProjectCard.locator('[data-wls-project-operation]')).toHaveCount(4);
      await expect(firstProjectCard.locator('[data-wls-project-operation="php-profile"]')).toBeVisible();
      await expect(firstProjectCard.locator('[data-wls-project-operation="database-profile"]')).toBeVisible();
      await expect(firstProjectCard.locator('[data-wls-project-operation="file-manager"]')).toBeVisible();
      await expect(firstProjectCard.locator('[data-wls-project-operation="deploy"]')).toBeVisible();
      const projectOperationHrefs = await firstProjectCard.locator('[data-wls-project-operation]').evaluateAll((links) =>
        links.map((link) => link.getAttribute('href') || ''),
      );
      expect(projectOperationHrefs.every((href) => href.length > 0 && href !== '#database-profile')).toBeTruthy();
      expect(projectOperationHrefs.some((href) => href.includes('tag=module%3Awls') || href.includes('operation='))).toBeTruthy();
      await expect(page.locator('.footer-wapper')).toBeHidden();
      await expectNoHorizontalOverflow(page);

      await page.screenshot({
        path: artifactPath('wls-panel-desktop.png'),
        fullPage: true,
      });

      await page.locator('[data-wls-theme-toggle]').click();
      await expect(shell).toHaveAttribute('data-wls-theme', 'dark');
      await expect(page.locator('[data-wls-theme-toggle]')).toHaveAttribute('aria-pressed', 'true');

      await withBundledPhpIniSnapshot(async () => {
        const phpManagerMemoryLimit = `${620 + Math.floor(Date.now() % 80)}M`;

        await gotoBackend(
          page,
          `${buildModuleBackendRoute(PHP_MANAGER_MODULE, 'wls-php-manager')}?operation=php-profile&project_id=e2e-php-manager&domain=php.e2e.wls.test&project_type=wls`,
          {
            timeout: 30000,
            settleMs: 500,
          },
        );
        const phpManagerShell = page.locator('[data-wls-php-manager-shell]');
        await expect(phpManagerShell).toBeVisible();
        await expect(phpManagerShell).toHaveAttribute('data-theme', 'dark');
        await expect(page.locator('.wpm-title')).toContainText('WLS PHP Manager');
        await expect(page.locator('[data-wpm-context]')).toContainText('e2e-php-manager');
        await expect(page.locator('[data-wpm-context]')).toContainText('php.e2e.wls.test');
        await expect(page.locator('[data-wpm-runtime]')).toBeVisible();
        await expect(page.locator('[data-wpm-runtime]')).toContainText('PHP');
        await expect(page.locator('[data-wpm-extensions]')).toBeVisible();
        await expect(page.locator('[data-wpm-project-profile]')).toBeVisible();
        const phpProfileForm = page.locator('[data-wpm-project-profile-form]');
        await expect(phpProfileForm).toBeVisible();
        await phpProfileForm.locator('input[type="checkbox"][name="enabled"]').check();
        await phpProfileForm.locator('input[name="php_ini_path"]').fill(BUNDLED_PHP_INI_PATH);
        await phpProfileForm.locator('input[name="memory_limit"]').fill(phpManagerMemoryLimit);
        await phpProfileForm.locator('input[name="max_execution_time"]').fill('120');
        await phpProfileForm.locator('input[name="upload_max_filesize"]').fill('64M');
        await phpProfileForm.locator('input[name="post_max_size"]').fill('64M');
        await phpProfileForm.locator('input[name="timezone"]').fill('Asia/Shanghai');
        await phpProfileForm.locator('textarea[name="required_extensions"]').fill('curl, mbstring, openssl');
        await phpProfileForm.locator('textarea[name="disabled_functions"]').fill('exec, shell_exec');
        await phpProfileForm.locator('select[name="runtime_action"]').selectOption('none');
        await phpProfileForm.locator('textarea[name="description"]').fill('E2E guarded PhpManager profile save.');
        await phpProfileForm.locator('input[type="checkbox"][name="confirm_profile_save"]').check();
        await phpProfileForm.locator('button[type="submit"]').click();
        await expect(page.locator('[data-wls-php-manager-shell]')).toBeVisible();
        await expect(page.locator('.wpm-alert.is-success')).toContainText('Project PHP profile saved');
        await expect(page.locator('[data-wpm-project-profile]')).toHaveAttribute('data-wpm-project-profile-state', 'saved');
        await expect(page.locator('[data-wpm-project-profile]')).toContainText('Current source: Panel Profile');

        const iniPlan = page.locator('[data-wpm-ini-plan]');
        await expect(iniPlan).toBeVisible();
        await expect(iniPlan).toHaveAttribute('data-wpm-ini-can-apply', '1');
        await expect(iniPlan).toHaveAttribute('data-wpm-ini-change-count', /[1-9]\d*/);
        await expect(page.locator('[data-wpm-ini-diff]')).toContainText('memory_limit');
        const iniApplyForm = page.locator('[data-wpm-ini-apply-form]');
        await expect(iniApplyForm).toBeVisible();
        await iniApplyForm.locator('select[name="runtime_action"]').selectOption('none');
        await iniApplyForm.locator('input[name="confirm_phrase"]').fill('APPLY_PHP_INI');
        await iniApplyForm.locator('input[type="checkbox"][name="confirm_ini_apply"]').check();
        await iniApplyForm.locator('button[type="submit"]').click();
        await expect(page.locator('[data-wls-php-manager-shell]')).toBeVisible();
        await expect(page.locator('.wpm-alert.is-success')).toContainText('php.ini applied and backup created');
        await expect(page.locator('[data-wpm-ini-rollback-form]')).toBeVisible();
        await page.screenshot({
          path: artifactPath('wls-php-manager-ini-apply-desktop.png'),
          fullPage: true,
        });

        const iniRollbackForm = page.locator('[data-wpm-ini-rollback-form]');
        await iniRollbackForm.locator('select[name="runtime_action"]').selectOption('none');
        await iniRollbackForm.locator('input[name="confirm_phrase"]').fill('ROLLBACK_PHP_INI');
        await iniRollbackForm.locator('input[type="checkbox"][name="confirm_ini_rollback"]').check();
        await iniRollbackForm.locator('button[type="submit"]').click();
        await expect(page.locator('[data-wls-php-manager-shell]')).toBeVisible();
        await expect(page.locator('.wpm-alert.is-success')).toContainText('php.ini restored from backup');

        await expect(page.locator('[data-wpm-audit-log]')).toBeVisible();
        expect(page.url()).not.toContain('project_path');
        await expectNoHorizontalOverflow(page);
        await page.screenshot({
          path: artifactPath('wls-php-manager-shell-desktop.png'),
          fullPage: true,
        });
        await page.locator('[data-wpm-theme-toggle]').click();
        await expect(phpManagerShell).toHaveAttribute('data-theme', 'light');
        await page.locator('[data-wpm-theme-toggle]').click();
        await expect(phpManagerShell).toHaveAttribute('data-theme', 'dark');
      });

      await withEnvSnapshot(async () => {
        await gotoBackend(
          page,
          `${buildModuleBackendRoute(DB_MANAGER_MODULE, 'wls-db-manager')}?operation=database-profile&project_id=e2e-db-manager&domain=db.e2e.wls.test&project_type=wls`,
          {
            timeout: 30000,
            settleMs: 500,
          },
        );
        const dbManagerShell = page.locator('[data-wls-db-manager-shell]');
        await expect(dbManagerShell).toBeVisible();
        await expect(dbManagerShell).toHaveAttribute('data-theme', 'dark');
        await expect(page.locator('.wdb-title')).toContainText('WLS Database Manager');
        await expect(page.locator('[data-wdb-context]')).toContainText('e2e-db-manager');
        await expect(page.locator('[data-wdb-context]')).toContainText('db.e2e.wls.test');
        await expect(page.locator('[data-wdb-profiles]')).toBeVisible();
        await expect(page.locator('[data-wdb-profiles]')).toHaveAttribute('data-wdb-profile-count', /[1-9]\d*/);
        await expect(page.locator('[data-wdb-selected-profile]')).toBeVisible();
        const dbProfileForm = page.locator('[data-wdb-project-profile-form]');
        await expect(dbProfileForm).toBeVisible();
        await expect(page.locator('[data-wdb-project-profile]')).toHaveAttribute('data-wdb-project-profile-state', /saved|inherited/);
        await dbProfileForm.locator('input[type="checkbox"][name="enabled"]').check();
        await dbProfileForm.locator('input[type="checkbox"][name="persistent"]').uncheck();
        await dbProfileForm.locator('select[name="type"]').selectOption('pgsql');
        await dbProfileForm.locator('input[name="hostname"]').fill('127.0.0.1');
        await dbProfileForm.locator('input[name="hostport"]').fill('5432');
        await dbProfileForm.locator('input[name="database"]').fill('weline_dev');
        await dbProfileForm.locator('input[name="username"]').fill('weline_dev');
        await dbProfileForm.locator('input[name="password"]').fill('weline_dev');
        await dbProfileForm.locator('input[name="path"]').fill(path.join(REPO_ROOT, 'app', 'etc', 'db.sqlite'));
        await dbProfileForm.locator('input[name="prefix"]').fill('m_');
        await dbProfileForm.locator('input[name="charset"]').fill('UTF8');
        await dbProfileForm.locator('input[name="collate"]').fill('');
        await dbProfileForm.locator('select[name="runtime_action"]').selectOption('none');
        await dbProfileForm.locator('textarea[name="description"]').fill('E2E guarded DbManager profile save with env apply plan.');
        await dbProfileForm.locator('input[type="checkbox"][name="confirm_profile_save"]').check();
        await dbProfileForm.locator('button[type="submit"]').click();
        await expect(page.locator('[data-wls-db-manager-shell]')).toBeVisible();
        await expect(page.locator('.wdb-alert.is-success').filter({ hasText: 'Project database profile saved' })).toBeVisible();
        await expect(page.locator('[data-wdb-project-profile]')).toHaveAttribute('data-wdb-project-profile-state', 'saved');
        await expect(page.locator('[data-wdb-project-profile]')).toContainText(/Stored in Profile|已存入 Profile/);
        await expect(page.locator('[data-wdb-audit-log]')).toBeVisible();
        await expect(dbProfileForm.locator('input[name="password"]')).toHaveValue('');
        await expect(dbProfileForm.locator('[data-wdb-env-password-import]')).toBeVisible();
        await expect(dbProfileForm.locator('input[name="import_env_password_phrase"]')).toBeVisible();

        const envPlan = page.locator('[data-wdb-env-plan]');
        await expect(envPlan).toBeVisible();
        await expect(envPlan).toHaveAttribute('data-wdb-env-can-apply', '1');
        await expect(envPlan).toHaveAttribute('data-wdb-env-change-count', /[1-9]\d*/);
        await expect(page.locator('[data-wdb-env-diff]')).toContainText('Persistent connection');
        const envApplyForm = page.locator('[data-wdb-env-apply-form]');
        await expect(envApplyForm).toBeVisible();
        await envApplyForm.locator('select[name="runtime_action"]').selectOption('none');
        await envApplyForm.locator('input[name="confirm_phrase"]').fill('APPLY_DB_ENV');
        await envApplyForm.locator('input[type="checkbox"][name="confirm_env_apply"]').check();
        await envApplyForm.locator('button[type="submit"]').click();
        await expect(page.locator('[data-wls-db-manager-shell]')).toBeVisible();
        await expect(page.getByText(/Database env applied and backup created|数据库 env 已应用并创建备份/)).toBeVisible();
        await expect(page.locator('[data-wdb-env-rollback-form]')).toBeVisible();

        await dbProfileForm.locator('input[type="checkbox"][name="clear_password"]').check();
        await dbProfileForm.locator('input[type="checkbox"][name="confirm_profile_save"]').check();
        await dbProfileForm.locator('button[type="submit"]').click();
        await expect(page.locator('[data-wls-db-manager-shell]')).toBeVisible();
        await expect(page.locator('.wdb-alert.is-success').filter({ hasText: 'Project database profile saved' })).toBeVisible();
        await expect(page.locator('[data-wdb-project-profile]')).toContainText(/Only in source env profile|浠呭瓨鍦ㄤ簬婧?env 閰嶇疆/);

        await dbProfileForm.locator('input[type="checkbox"][name="import_env_password"]').check();
        await dbProfileForm.locator('input[name="import_env_password_phrase"]').fill('COPY_ENV_PASSWORD');
        await dbProfileForm.locator('input[type="checkbox"][name="confirm_profile_save"]').check();
        await dbProfileForm.locator('button[type="submit"]').click();
        await expect(page.locator('[data-wls-db-manager-shell]')).toBeVisible();
        await expect(page.locator('.wdb-alert.is-success').filter({ hasText: 'Project database profile saved' })).toBeVisible();
        await expect(page.locator('[data-wdb-project-profile]')).toContainText(/Stored in Profile|宸插瓨鍏?Profile/);

        const envRollbackForm = page.locator('[data-wdb-env-rollback-form]');
        await envRollbackForm.locator('select[name="runtime_action"]').selectOption('none');
        await envRollbackForm.locator('input[name="confirm_phrase"]').fill('ROLLBACK_DB_ENV');
        await envRollbackForm.locator('input[type="checkbox"][name="confirm_env_rollback"]').check();
        await envRollbackForm.locator('button[type="submit"]').click();
        await expect(page.locator('[data-wls-db-manager-shell]')).toBeVisible();
        await expect(page.getByText(/Database env restored from backup|数据库 env 已从备份恢复/)).toBeVisible();

        await expect(page.locator('[data-wdb-connection-test]')).toBeVisible();
        await expect(page.locator('[data-wdb-connection-select]')).toBeVisible();
        await expect(page.locator('[data-wdb-connection-select]')).toContainText('Project Profile');
        await expect(page.locator('button[type="submit"]').filter({ hasText: 'Test Connection' })).toBeVisible();
        expect(page.url()).not.toContain('project_path');
        await expectNoHorizontalOverflow(page);
        await page.screenshot({
          path: artifactPath('wls-db-manager-shell-desktop.png'),
          fullPage: true,
        });
        await page.locator('[data-wdb-theme-toggle]').click();
        await expect(dbManagerShell).toHaveAttribute('data-theme', 'light');
        await page.locator('[data-wdb-theme-toggle]').click();
        await expect(dbManagerShell).toHaveAttribute('data-theme', 'dark');
      });

      await gotoBackend(
        page,
        `${buildModuleBackendRoute(FILE_MANAGER_MODULE, 'wls-file-manager')}?operation=files.read&project_id=e2e-file-manager&domain=e2e.wls.test&project_type=wls`,
        {
          timeout: 30000,
          settleMs: 500,
        },
      );
      const fileManagerShell = page.locator('[data-wls-file-manager-shell]');
      await expect(fileManagerShell).toBeVisible();
      await expect(fileManagerShell).toHaveAttribute('data-theme', 'dark');
      await expect(page.locator('.wfm-title')).toContainText(/WLS File Manager|WLS 文件管理器/);
      await expect(page.locator('.wfm-context')).toContainText('e2e-file-manager');
      await expect(page.locator('.wfm-context')).toContainText('e2e.wls.test');
      await expect(page.locator('.wfm-grid .wfm-card')).toHaveCount(4);
      await expect(page.locator('.wfm-capabilities .wfm-card')).toHaveCount(3);
      const fileBrowser = page.locator('[data-wfm-browser]');
      await expect(fileBrowser).toBeVisible();
      await expect(fileBrowser.locator('.wfm-root-tabs a')).toHaveCount(4);
      const fileBrowserEntryCount = await fileBrowser.locator('[data-wfm-entry]').count();
      expect(fileBrowserEntryCount).toBeGreaterThan(0);
      await expect(fileBrowser.locator('[data-wfm-entry-type="directory"]').first()).toBeVisible();
      const initialBrowserPath = (await fileBrowser.locator('.wfm-browser-current code').innerText()).trim();
      expect(initialBrowserPath.length).toBeGreaterThan(1);
      expect(page.url()).not.toContain('project_path');

      const firstDirectoryLink = fileBrowser.locator('[data-wfm-entry-type="directory"] .wfm-entry-link').first();
      const firstDirectoryName = (await firstDirectoryLink.innerText()).trim();
      await firstDirectoryLink.click();
      await expect(page.locator('[data-wfm-browser] .wfm-up-link')).toBeVisible();
      const drilledPath = (await page.locator('[data-wfm-browser] .wfm-browser-current code').innerText()).replace(/\\/g, '/');
      expect(drilledPath).toContain(firstDirectoryName);
      expect(page.url()).not.toContain('project_path');

      await gotoBackend(
        page,
        `${buildModuleBackendRoute(FILE_MANAGER_MODULE, 'wls-file-manager')}?operation=files.read&project_id=e2e-file-manager&domain=e2e.wls.test&project_type=wls&root=app_code&path=..%2F..`,
        {
          timeout: 30000,
          settleMs: 500,
        },
      );
      const guardedBrowserPath = (await page.locator('[data-wfm-browser] .wfm-browser-current code').innerText()).replace(/\\/g, '/');
      expect(guardedBrowserPath).toContain('/app/code');
      expect(guardedBrowserPath).not.toContain('/..');
      await expect(page.locator('[data-wfm-browser] [data-wfm-entry]').first()).toBeVisible();

      await gotoBackend(
        page,
        `${buildModuleBackendRoute(FILE_MANAGER_MODULE, 'wls-file-manager')}?operation=files.read&project_id=e2e-file-manager&domain=e2e.wls.test&project_type=wls&root=app_code&path=Weline`,
        {
          timeout: 30000,
          settleMs: 500,
        },
      );
      const welineModulePath = (await page.locator('[data-wfm-browser] .wfm-browser-current code').innerText()).replace(/\\/g, '/');
      expect(welineModulePath).toContain('/app/code/Weline');
      await expect(page.locator('[data-wfm-browser] .wfm-up-link')).toBeVisible();
      await expect(page.locator('[data-wfm-browser] [data-wfm-entry]').first()).toBeVisible();

      await gotoBackend(
        page,
        `${buildModuleBackendRoute(FILE_MANAGER_MODULE, 'wls-file-manager')}?operation=files.read&project_id=e2e-file-manager&domain=e2e.wls.test&project_type=wls&root=app_code&path=Weline%2FFileManager%2Fdoc`,
        {
          timeout: 30000,
          settleMs: 500,
        },
      );
      const readmeRow = page.locator('[data-wfm-entry]').filter({ hasText: 'README.md' }).first();
      await expect(readmeRow).toBeVisible();
      await expect(readmeRow.locator('[data-wfm-preview-link]')).toBeVisible();
      await expect(readmeRow.locator('[data-wfm-download-link]')).toBeVisible();
      const readmeDownloadHref = await readmeRow.locator('[data-wfm-download-link]').getAttribute('href');
      expect(readmeDownloadHref).toContain('wls-file-manager/download');
      expect(readmeDownloadHref).toContain('file=Weline%2FFileManager%2Fdoc%2FREADME.md');
      const readmeDownloadResponse = await page.request.get(new URL(readmeDownloadHref, page.url()).toString());
      expect(readmeDownloadResponse.status()).toBe(200);
      expect(readmeDownloadResponse.headers()['content-disposition']).toContain('README.md');
      expect(await readmeDownloadResponse.text()).toContain('WLS Panel Integration');
      await readmeRow.locator('[data-wfm-preview-link]').click();
      const previewPanel = page.locator('[data-wfm-preview-panel]');
      await expect(previewPanel).toBeVisible();
      await expect(previewPanel).toContainText('README.md');
      await expect(previewPanel).toContainText(/Weline FileManager|WLS Panel Integration/);
      expect(page.url()).not.toContain('project_path');

      const writeDirectoryName = `wls-file-manager-e2e-${Date.now()}`;
      const writeFileName = 'notes.txt';
      const uploadFileName = 'upload-e2e.txt';
      const renamedUploadFileName = 'renamed-e2e.txt';
      const recursiveDirectoryName = 'tree-delete-e2e';
      try {
        await gotoBackend(
          page,
          `${buildModuleBackendRoute(FILE_MANAGER_MODULE, 'wls-file-manager')}?operation=files.write&project_id=e2e-file-manager&domain=e2e.wls.test&project_type=wls&root=var`,
          {
            timeout: 30000,
            settleMs: 500,
          },
        );
        await expect(page.locator('#write-operations')).toBeVisible();
        await expect(page.locator('[data-wfm-create-directory-form] button[type="submit"]')).toBeEnabled();
        await expect(page.locator('[data-wfm-save-text-form] button[type="submit"]')).toBeEnabled();
        await expect(page.locator('[data-wfm-upload-form] button[type="submit"]')).toBeEnabled();
        await expect(page.locator('[data-wfm-rename-form] button[type="submit"]')).toBeEnabled();
        await expect(page.locator('[data-wfm-delete-form] button[type="submit"]')).toBeEnabled();
        await page.locator('[data-wfm-create-directory-form] input[name="directory_name"]').fill(writeDirectoryName);
        await page.locator('[data-wfm-create-directory-form] input[name="confirm_write"]').check();
        await page.locator('[data-wfm-create-directory-form] button[type="submit"]').click();
        await expect(page.locator('.wfm-browser-alert.success')).toBeVisible();
        await expect(page.locator('[data-wfm-entry]').filter({ hasText: writeDirectoryName })).toBeVisible();

        await gotoBackend(
          page,
          `${buildModuleBackendRoute(FILE_MANAGER_MODULE, 'wls-file-manager')}?operation=files.write&project_id=e2e-file-manager&domain=e2e.wls.test&project_type=wls&root=var&path=${encodeURIComponent(writeDirectoryName)}`,
          {
            timeout: 30000,
            settleMs: 500,
          },
        );
        await expect(page.locator('#write-operations')).toBeVisible();
        await page.locator('[data-wfm-save-text-form] input[name="file_name"]').fill(writeFileName);
        await page.locator('[data-wfm-save-text-form] textarea[name="file_content"]').fill('WLS FileManager E2E controlled text save.');
        await page.locator('[data-wfm-save-text-form] input[name="confirm_write"]').check();
        await page.locator('[data-wfm-save-text-form] input[name="confirm_phrase"]').fill('SAVE_TEXT');
        await page.locator('[data-wfm-save-text-form] button[type="submit"]').click();
        await expect(page.locator('.wfm-browser-alert.success')).toBeVisible();
        await expect(page.locator('[data-wfm-entry]').filter({ hasText: writeFileName })).toBeVisible();
        await expect(page.locator('#operation-log')).toContainText(writeFileName);
        const uploadFixturePath = artifactPath(uploadFileName);
        fs.writeFileSync(uploadFixturePath, 'WLS FileManager E2E controlled upload.');
        await page.locator('[data-wfm-upload-form] input[name="upload_file"]').setInputFiles(uploadFixturePath);
        await page.locator('[data-wfm-upload-form] input[name="confirm_write"]').check();
        await page.locator('[data-wfm-upload-form] input[name="confirm_phrase"]').fill('UPLOAD_FILE');
        await page.locator('[data-wfm-upload-form] button[type="submit"]').click();
        await expect(page.locator('.wfm-browser-alert.success')).toBeVisible();
        await expect(page.locator('[data-wfm-entry]').filter({ hasText: uploadFileName })).toBeVisible();

        const uploadRelativePath = `${writeDirectoryName}/${uploadFileName}`;
        await page.locator('[data-wfm-rename-form] input[name="entry_path"]').fill(uploadRelativePath);
        await page.locator('[data-wfm-rename-form] input[name="new_name"]').fill(renamedUploadFileName);
        await page.locator('[data-wfm-rename-form] input[name="confirm_write"]').check();
        await page.locator('[data-wfm-rename-form] input[name="confirm_phrase"]').fill('RENAME_ENTRY');
        await page.locator('[data-wfm-rename-form] button[type="submit"]').click();
        await expect(page.locator('.wfm-browser-alert.success')).toBeVisible();
        await expect(page.locator('[data-wfm-entry]').filter({ hasText: renamedUploadFileName })).toBeVisible();
        await expect(page.locator('[data-wfm-entry]').filter({ hasText: uploadFileName })).toHaveCount(0);

        const renamedUploadRelativePath = `${writeDirectoryName}/${renamedUploadFileName}`;
        await page.locator('[data-wfm-delete-form] input[name="entry_path"]').fill(renamedUploadRelativePath);
        await page.locator('[data-wfm-delete-form] input[name="confirm_write"]').check();
        await page.locator('[data-wfm-delete-form] input[name="confirm_phrase"]').fill('DELETE_ENTRY');
        await page.locator('[data-wfm-delete-form] button[type="submit"]').click();
        await expect(page.locator('.wfm-browser-alert.success')).toBeVisible();
        await expect(page.locator('[data-wfm-entry]').filter({ hasText: renamedUploadFileName })).toHaveCount(0);
        await expect(page.locator('#operation-log')).toContainText(/Delete entry|删除条目/);

        const recursiveTargetPath = path.join(VAR_ROOT, writeDirectoryName, recursiveDirectoryName);
        fs.mkdirSync(path.join(recursiveTargetPath, 'child'), { recursive: true });
        fs.writeFileSync(path.join(recursiveTargetPath, 'child', 'payload.txt'), 'WLS FileManager recursive delete E2E.');
        await gotoBackend(
          page,
          `${buildModuleBackendRoute(FILE_MANAGER_MODULE, 'wls-file-manager')}?operation=files.write&project_id=e2e-file-manager&domain=e2e.wls.test&project_type=wls&root=var&path=${encodeURIComponent(writeDirectoryName)}`,
          {
            timeout: 30000,
            settleMs: 500,
          },
        );
        await expect(page.locator('[data-wfm-entry]').filter({ hasText: recursiveDirectoryName })).toBeVisible();
        await expect(page.locator('[data-wfm-delete-form] input[name="delete_recursive"]')).toBeVisible();
        await page.locator('[data-wfm-delete-form] input[name="entry_path"]').fill(`${writeDirectoryName}/${recursiveDirectoryName}`);
        await page.locator('[data-wfm-delete-form] input[name="confirm_write"]').check();
        await page.locator('[data-wfm-delete-form] input[name="delete_recursive"]').check();
        await page.locator('[data-wfm-delete-form] input[name="confirm_phrase"]').fill('DELETE_TREE');
        await page.locator('[data-wfm-delete-form] button[type="submit"]').click();
        await expect(page.locator('.wfm-browser-alert.success')).toBeVisible();
        await expect(page.locator('[data-wfm-entry]').filter({ hasText: recursiveDirectoryName })).toHaveCount(0);
        expect(fs.existsSync(recursiveTargetPath)).toBe(false);
        await expect(page.locator('#operation-log')).toContainText(/Delete directory tree|删除目录树/);

        await page.locator('#write-operations').scrollIntoViewIfNeeded();
        await expectNoHorizontalOverflow(page);
        await page.screenshot({
          path: artifactPath('wls-file-manager-write-desktop.png'),
        });
      } finally {
        removeVarTestDirectory(writeDirectoryName);
      }

      await page.locator('.wfm-title').scrollIntoViewIfNeeded();
      await expect(page.locator('[data-wfm-theme-toggle]')).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await page.screenshot({
        path: artifactPath('wls-file-manager-shell-desktop.png'),
        fullPage: true,
      });

      await page.locator('[data-wfm-theme-toggle]').click();
      await expect(fileManagerShell).toHaveAttribute('data-theme', 'light');
      await page.locator('[data-wfm-theme-toggle]').click();
      await expect(fileManagerShell).toHaveAttribute('data-theme', 'dark');

      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'wls-panel/security'), {
        timeout: 30000,
        settleMs: 500,
      });
      await expect(page.locator('.wls-security-page')).toBeVisible();
      await expect(page.locator('[data-wls-security-projects]')).toBeVisible();
      await expect(page.locator('[data-wls-security-project-card]').first()).toBeVisible();
      await expect(page.locator('[data-wls-security-project-card][data-security-scope="current"]')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy]')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy]')).toContainText(/Select one project|选择/);
      await expect(
        page.locator('[data-wls-security-project-card][data-security-scope="current"] a[href*="project-security-policy"]'),
      ).toBeVisible();
      await expect(page.locator('.wls-rule-editor-grid')).toBeVisible();
      await expect(page.locator('input[name="visual_rules[rate_limit][max_requests]"]')).toBeVisible();
      await expect(page.locator('input[type="checkbox"][name="visual_rules[path_rate_limits][enabled]"]')).toBeVisible();
      await expect(page.locator('.wls-path-rate-panel')).toBeVisible();
      await expect(page.locator('input[name="visual_rules[path_rate_limits][rules][0][path]"]')).toBeVisible();
      await expect(page.locator('input[name="visual_rules[path_rate_limits][rules][0][max_requests]"]')).toBeVisible();
      await expect(page.locator('input[name="visual_rules[path_scan][max_unique_paths]"]')).toBeVisible();
      await expect(page.locator('input[name="visual_rules[ssl_handshake_failure][fast_close_threshold]"]')).toBeVisible();
      await expect(page.locator('input[type="checkbox"][name="visual_rules[unknown_route_ban][only_in_spike_mode]"]')).toBeVisible();
      await expect(page.locator('textarea[name="visual_rules[ip_whitelist][ips]"]')).toBeVisible();
      await expect(page.locator('textarea[name="visual_rules[protected_paths][paths]"]')).toContainText('/.git/');
      await expect(page.locator('textarea[name="rules_json"]')).toBeVisible();
      await expect(page.locator('textarea[name="rules_json"]')).toContainText('"rate_limit"');
      const diffPreview = page.locator('[data-wls-rule-diff-preview]');
      await expect(diffPreview).toBeVisible();
      await expect(diffPreview).toHaveAttribute('data-wls-rule-diff-state', 'empty');
      await page.locator('input[name="visual_rules[rate_limit][max_requests]"]').fill('123');
      await expect(diffPreview).toHaveAttribute('data-wls-rule-diff-state', 'changed');
      await expect(diffPreview).toHaveAttribute('data-wls-rule-diff-count-value', /[1-9]\d*/);
      await expect(diffPreview).toContainText('rate_limit.max_requests');
      await expect(page.locator('.wls-security-rule-summary')).toBeVisible();
      await expect(page.locator('.wls-security-events')).toBeVisible();
      await expect(page.locator('.wls-security-log-filter')).toBeVisible();
      await expect(page.locator('select[name="security_scope"]')).toBeVisible();
      await expect(page.locator('input[name="security_instance"]')).toBeVisible();
      await expect(page.locator('input[name="security_ip"]')).toBeVisible();
      await expect(page.locator('select[name="security_severity"]')).toBeVisible();
      await expect(page.locator('select[name="security_type"]')).toBeVisible();
      await expect(page.locator('select[name="security_blocked"]')).toBeVisible();
      await expect(page.locator('.wls-security-log-footer')).toBeVisible();
      await expect(page.locator('.wls-standalone-shell')).toHaveAttribute('data-wls-theme', 'dark');
      await expectNoHorizontalOverflow(page);

      await page.locator('select[name="security_scope"]').selectOption('current');
      await page.locator('input[name="security_instance"]').fill('default');
      await page.locator('select[name="security_blocked"]').selectOption('1');
      await page.locator('.wls-security-log-filter button[type="submit"]').click();
      await expect(page.locator('.wls-security-page')).toBeVisible();
      expect(new URL(page.url()).searchParams.get('security_scope')).toBe('current');
      await expect(page.locator('select[name="security_scope"]')).toHaveValue('current');
      await expect(page.locator('[data-wls-security-project-card][data-security-scope="current"]')).toHaveClass(/is-active/);
      await expect(page.locator('[data-wls-domain-policy]')).toBeVisible();
      await expect(page.locator('.wls-domain-policy-form')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy] input[name="save_mode"]')).toHaveValue('domain_override');
      await expect(page.locator('[data-wls-domain-policy] input[name="security_domain"]')).not.toHaveValue('');
      await expect(page.locator('[data-wls-domain-policy] input[name="domain_override[rate_limit][max_requests]"]')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy] input[name^="domain_override[path_rate_limits][rules]"][name$="[path]"]').first()).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy] input[name="domain_override[path_scan][max_unique_paths]"]')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy] input[name="domain_override[ssl_handshake_failure][fast_close_threshold]"]')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy] input[type="checkbox"][name="domain_override[unknown_route_ban][only_in_spike_mode]"]')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy] textarea[name="domain_override[ip_whitelist][ips]"]')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy] textarea[name="domain_override[protected_paths][paths]"]')).toBeVisible();
      const inheritanceMap = page.locator('[data-wls-domain-policy-inheritance]');
      await expect(inheritanceMap).toBeVisible();
      await expect(inheritanceMap).toHaveAttribute('data-wls-domain-policy-inherited', /\d+/);
      await expect(inheritanceMap.locator('[data-wls-domain-policy-inheritance-row]').first()).toBeVisible();
      await expect(
        inheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="rate_limit"][data-field-key="max_requests"]'),
      ).toBeVisible();
      await expect(
        inheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="path_rate_limits"][data-field-key="rules"]'),
      ).toBeVisible();
      await expect(
        inheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="ssl_handshake_failure"][data-field-key="fast_close_threshold"]'),
      ).toBeVisible();
      await expect(
        inheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="unknown_route_ban"][data-field-key="only_in_spike_mode"]'),
      ).toBeVisible();
      await expect(
        inheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="ip_whitelist"][data-field-key="ips"]'),
      ).toBeVisible();
      await expect(
        inheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="protected_paths"][data-field-key="paths"]'),
      ).toBeVisible();
      await expect(page.locator('[data-wls-security-policy-audit]')).toBeVisible();
      await expect(page.locator('input[name="security_instance"]')).toHaveValue('default');
      await expect(page.locator('select[name="security_blocked"]')).toHaveValue('1');

      await withSecurityRulesSnapshot(async () => {
        const domainPolicy = page.locator('[data-wls-domain-policy]');
        await domainPolicy.locator('input[type="checkbox"][name="domain_override[enabled]"]').check();
        await domainPolicy.locator('input[name="domain_override[rate_limit][max_requests]"]').fill('214');
        await domainPolicy.locator('input[name^="domain_override[path_rate_limits][rules]"][name$="[path]"]').last().fill('/api/e2e-project');
        await domainPolicy.locator('input[name^="domain_override[path_rate_limits][rules]"][name$="[max_requests]"]').last().fill('42');
        await domainPolicy.locator('input[name="domain_override[ssl_handshake_failure][fast_close_threshold]"]').fill('0.35');
        await domainPolicy.locator('input[name="domain_override[unknown_route_ban][consecutive_count]"]').fill('7');
        await domainPolicy.locator('textarea[name="domain_override[ip_whitelist][ips]"]').fill('127.0.0.1\n10.0.0.0/8');
        await domainPolicy.locator('textarea[name="domain_override[protected_paths][paths]"]').fill('/admin\n/.git/');
        await domainPolicy.locator('button[type="submit"]').filter({ hasText: 'Save Project Policy' }).click();
        await expect(page.locator('.wls-security-page')).toBeVisible();
        await expect(page.locator('.wls-inline-success')).toContainText(/Security rules saved|安全规则已保存/);
        const savedInheritanceMap = page.locator('[data-wls-domain-policy-inheritance]');
        await expect(savedInheritanceMap).toBeVisible();
        await expect(savedInheritanceMap).toHaveAttribute('data-wls-domain-policy-overrides', /[1-9]\d*/);
        await expect(savedInheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-state="overridden"]').first()).toBeVisible();
        await expect(
          savedInheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="rate_limit"][data-field-key="max_requests"]'),
        ).toHaveAttribute('data-state', 'overridden');
        await expect(
          savedInheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="path_rate_limits"][data-field-key="rules"]'),
        ).toHaveAttribute('data-state', 'overridden');
        await expect(
          savedInheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="ssl_handshake_failure"][data-field-key="fast_close_threshold"]'),
        ).toHaveAttribute('data-state', 'overridden');
        await expect(
          savedInheritanceMap.locator('[data-wls-domain-policy-inheritance-row][data-rule-key="ip_whitelist"][data-field-key="ips"]'),
        ).toHaveAttribute('data-state', 'overridden');
        const policyAudit = page.locator('[data-wls-security-policy-audit]');
        await expect(policyAudit).toBeVisible();
        await expect(policyAudit).toHaveAttribute('data-wls-security-policy-audit-count', /[1-9]\d*/);
        const latestAudit = page.locator('[data-wls-security-audit-entry]').first();
        await expect(latestAudit).toContainText(/Project policy saved|项目策略已保存/);
        await expect(latestAudit).toContainText(/Project policy editor|项目策略编辑器/);
        await expect(latestAudit).toContainText('rate_limit');
        await expect(latestAudit).toContainText('path_rate_limits');
        await expect(latestAudit).toContainText('ssl_handshake_failure');
        await expect(latestAudit).toContainText('ip_whitelist');
        await expect(latestAudit).toContainText('protected_paths');
        await expect(latestAudit).not.toContainText('"rate_limit"');
      });

      await expectNoHorizontalOverflow(page);
      await page.screenshot({
        path: artifactPath('wls-panel-security-scope-filter.png'),
        fullPage: true,
      });

      await page.setViewportSize({ width: 390, height: 844 });
      await gotoBackend(
        page,
        `${buildModuleBackendRoute(MODULE, 'wls-panel/security')}?security_scope=current&security_instance=default&security_blocked=1`,
        {
          timeout: 30000,
          settleMs: 500,
        },
      );
      await expect(page.locator('[data-wls-domain-policy]')).toBeVisible();
      await expect(page.locator('.wls-domain-policy-form')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy] input[name="security_domain"]')).not.toHaveValue('');
      await expect(page.locator('[data-wls-domain-policy-inheritance]')).toBeVisible();
      await expect(page.locator('[data-wls-domain-policy-inheritance-row]').first()).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await page.locator('[data-wls-domain-policy]').scrollIntoViewIfNeeded();
      await page.screenshot({
        path: artifactPath('wls-panel-security-domain-policy-mobile.png'),
        fullPage: true,
      });

      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'wls-panel'), {
        timeout: 30000,
        settleMs: 500,
      });
      await expect(page.locator('[data-wls-operation-capabilities]')).toBeVisible();
      await expect(page.locator('[data-wls-plugin-contributions]')).toBeVisible();
      await expect(page.locator('[data-wls-project-config-center]')).toBeVisible();
      await expect(page.locator('[data-wls-operation-card]')).toHaveCount(4);
      await expect(page.locator('[data-wls-project-config-card]').first().locator('[data-wls-config-operation]')).toHaveCount(4);
      await expect(page.locator('.wls-project-card').first().locator('[data-wls-project-operation]')).toHaveCount(4);
      await expectNoHorizontalOverflow(page);
      await page.locator('[data-wls-operation-capabilities]').scrollIntoViewIfNeeded();
      await page.screenshot({
        path: artifactPath('wls-panel-operation-capabilities-mobile.png'),
        fullPage: true,
      });

      await gotoBackend(
        page,
        `${buildModuleBackendRoute(PHP_MANAGER_MODULE, 'wls-php-manager')}?operation=php-profile&project_id=e2e-php-mobile&domain=php-mobile.wls.test&project_type=wls`,
        {
          timeout: 30000,
          settleMs: 500,
        },
      );
      const mobilePhpManagerShell = page.locator('[data-wls-php-manager-shell]');
      await expect(mobilePhpManagerShell).toBeVisible();
      await expect(page.locator('.wpm-sidebar')).toBeVisible();
      await expect(page.locator('[data-wpm-context]')).toContainText('e2e-php-mobile');
      await expect(page.locator('[data-wpm-runtime]')).toBeVisible();
      await expect(page.locator('[data-wpm-project-profile]')).toBeVisible();
      await expect(page.locator('[data-wpm-project-profile-form]')).toBeVisible();
      await expect(page.locator('[data-wpm-ini-plan]')).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await expect.poll(async () => page.locator('.wpm-sidebar').evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(4);
      await page.screenshot({
        path: artifactPath('wls-php-manager-shell-mobile.png'),
        fullPage: true,
      });

      await gotoBackend(
        page,
        `${buildModuleBackendRoute(DB_MANAGER_MODULE, 'wls-db-manager')}?operation=database-profile&project_id=e2e-db-mobile&domain=db-mobile.wls.test&project_type=wls`,
        {
          timeout: 30000,
          settleMs: 500,
        },
      );
      const mobileDbManagerShell = page.locator('[data-wls-db-manager-shell]');
      await expect(mobileDbManagerShell).toBeVisible();
      await expect(page.locator('.wdb-sidebar')).toBeVisible();
      await expect(page.locator('[data-wdb-context]')).toContainText('e2e-db-mobile');
      await expect(page.locator('[data-wdb-profiles]')).toBeVisible();
      await expect(page.locator('[data-wdb-project-profile]')).toBeVisible();
      await expect(page.locator('[data-wdb-project-profile-form]')).toBeVisible();
      await expect(page.locator('[data-wdb-connection-test]')).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await expect.poll(async () => page.locator('.wdb-sidebar').evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(4);
      await page.screenshot({
        path: artifactPath('wls-db-manager-shell-mobile.png'),
        fullPage: true,
      });

      await gotoBackend(
        page,
        `${buildModuleBackendRoute(FILE_MANAGER_MODULE, 'wls-file-manager')}?operation=files.read&project_id=e2e-mobile&domain=mobile.wls.test&project_type=wls`,
        {
          timeout: 30000,
          settleMs: 500,
        },
      );
      const mobileFileManagerShell = page.locator('[data-wls-file-manager-shell]');
      await expect(mobileFileManagerShell).toBeVisible();
      await expect(page.locator('.wfm-sidebar')).toBeVisible();
      await expect(page.locator('.wfm-context')).toContainText('e2e-mobile');
      await expect(page.locator('[data-wfm-browser]')).toBeVisible();
      await expect(page.locator('[data-wfm-browser] [data-wfm-entry]').first()).toBeVisible();
      await expectNoHorizontalOverflow(page);
      await expect.poll(async () => page.locator('.wfm-sidebar').evaluate((element) => element.scrollWidth - element.clientWidth)).toBeLessThanOrEqual(4);
      await page.screenshot({
        path: artifactPath('wls-file-manager-shell-mobile.png'),
        fullPage: true,
      });

      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'wls-panel/marketplace'), {
        timeout: 30000,
        settleMs: 500,
      });

      const marketplaceShell = page.locator('.wls-standalone-shell[data-wls-shell="standalone"]');
      await expect(marketplaceShell).toBeVisible();
      await expect(marketplaceShell).toHaveAttribute('data-wls-theme', 'dark');
      await expect(page.locator('.wls-marketplace-page')).toBeVisible({ timeout: 90000 });
      await expect(page.locator('#installed-plugins')).toBeVisible();
      await expect(page.locator('[data-wls-plugin-refresh]')).toBeVisible();
      await expect(marketplaceShell).toHaveAttribute('data-wls-auto-refresh', '');
      await expect(page.locator('a[href*="appstore/backend?"][href*="wls_panel_return=1"]').first()).toBeVisible();
      await expect(page.locator('a[href*="appstore/backend/installed"][href*="wls_panel_return=1"]').first()).toBeVisible();
      const pluginCardCount = await page.locator('.wls-plugin-card').count();
      expect(pluginCardCount).toBeGreaterThanOrEqual(4);
      await expect(page.locator('.wls-plugin-card').filter({ hasText: 'Weline_PhpManager' })).toBeVisible();
      await expect(page.locator('.wls-plugin-card').filter({ hasText: 'Weline_DbManager' })).toBeVisible();
      await expect(page.locator('.wls-plugin-card').filter({ hasText: 'Weline_FileManager' })).toBeVisible();
      await expect(page.locator('.wls-tag-list').first()).toContainText('module:wls');
      await expect(page.locator('.footer-wapper')).toBeHidden();
      await expectNoHorizontalOverflow(page);

      const sidebarBox = await page.locator('.wls-shell-sidebar').boundingBox();
      const mainBox = await page.locator('[data-wls-main]').boundingBox();
      expect(sidebarBox).not.toBeNull();
      expect(mainBox).not.toBeNull();
      expect(mainBox.y).toBeGreaterThan(sidebarBox.y);

      await gotoBackend(page, `${buildModuleBackendRoute(MODULE, 'wls-panel/marketplace')}?panel_auto_refresh=plugins`, {
        timeout: 60000,
        settleMs: 500,
      });
      await expect(page.locator('.wls-marketplace-page')).toBeVisible();
      await expect(page.locator('.wls-inline-success')).toContainText(
        /Panel plugin capabilities refreshed|面板插件能力已刷新/,
      );
      expect(new URL(page.url()).searchParams.get('panel_auto_refresh')).toBeNull();
      await expect(page.locator('.wls-standalone-shell[data-wls-shell="standalone"]')).toHaveAttribute('data-wls-auto-refresh', '');
      await expectNoHorizontalOverflow(page);

      await page.locator('[data-wls-plugin-refresh]').click();
      await expect(page.locator('.wls-marketplace-page')).toBeVisible({ timeout: 90000 });
      await expect(page.locator('.wls-inline-success')).toContainText(
        /Panel plugin capabilities refreshed|面板插件能力已刷新/,
      );
      await expect(page.locator('[data-wls-plugin-refresh]')).toBeVisible();
      await expectNoHorizontalOverflow(page);

      await page.screenshot({
        path: artifactPath('wls-panel-marketplace-mobile-dark.png'),
        fullPage: true,
      });
    },
  );
});
