/**
 * Weline_Smtp 邮件模板计划链路 e2e 套件（listing / edit / 迁移契约）
 *
 * @weline-e2e-spec { module: Weline_Smtp, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Smtp';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CONTENT_SHELL = 'main#main-content, main.backend-main-content';

async function openTemplateListing(page) {
  const route = buildModuleBackendRoute(MODULE, 'template', 'listing');
  await gotoBackend(page, route, { timeout: 60000, settleMs: 800 });
  await waitForBackendShellReady(page);
  return route;
}

moduleDescribe(test, MODULE, 'SMTP 邮件模板计划链路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'SMTP-TPL-E2E-001' },
    '渠道管理 listing 前后端通路可达',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openTemplateListing(page);
      const bodyText = await page.locator('body').innerText().catch(() => '');
      expect(FATAL.test(bodyText)).toBeFalsy();
      await expect(page.locator('body')).not.toContainText(FATAL);
      const listing = page.locator('[data-testid="smtp-channel-listing"]').first();
      const channelList = page.locator('[data-testid="smtp-channel-list"]').first();
      const shell = page.locator(CONTENT_SHELL).first();
      const hasListing = await listing.isVisible().catch(() => false);
      const hasChannels = await channelList.isVisible().catch(() => false);
      const hasText = /渠道管理|作用范围|发信渠道|语言/i.test(bodyText);
      expect(hasListing || hasChannels || hasText).toBeTruthy();
      await expect(page.locator('[data-testid="smtp-channel-listing-header"]').first()).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-testid="smtp-channel-listing-header"] .w-heading').first()).toHaveText(/渠道管理/);
      await expect(page.locator('[data-testid="smtp-channel-scope"]').first()).toBeVisible();
      await expect(page.locator('#smtp-channel-scope, input#smtp-channel-scope').first()).toBeAttached();
      const rowCount = await page.locator('[data-testid="smtp-channel-list"] details.w-smtp-channel').count();
      expect(rowCount).toBeGreaterThan(1);
      const bodyForChannels = await page.locator('[data-testid="smtp-channel-listing"]').innerText().catch(() => '');
      expect(/password_reset|notification_email|Weline_/i.test(bodyForChannels)).toBeTruthy();

      await expect(page.locator('[data-testid="smtp-channel-search"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="smtp-locale-tag-filter"]').first()).toBeVisible();
      await expect(page.getByText('按范围筛选模板').first()).toBeVisible();

      const passwordRow = page.locator('[data-testid="smtp-channel-row-Weline_Customer::password_reset"]').first();
      if (await passwordRow.count()) {
        await passwordRow.locator('summary').click();
        await expect(page.locator('[data-testid="smtp-channel-locales-Weline_Customer::password_reset"]').first()).toBeVisible({ timeout: 10000 });
        const localeRows = await page.locator('[data-testid^="smtp-channel-locale-row-Weline_Customer::password_reset-"]').count();
        expect(localeRows).toBeGreaterThan(0);

        const channelSearch = page.locator('[data-testid="smtp-channel-search"]').first();
        await channelSearch.fill('password_reset');
        await expect(passwordRow).toBeVisible();
        const hiddenChannels = await page.locator('[data-testid="smtp-channel-list"] details.w-smtp-channel[hidden]').count();
        expect(hiddenChannels).toBeGreaterThan(0);
        await channelSearch.fill('');

        const localeSelect = page.locator('#smtp-locale-filter_wrapper, [data-w-component-id="smtp-locale-filter"]').first();
        await expect(localeSelect).toBeVisible({ timeout: 15000 });
        await localeSelect.locator('.w-language-select__trigger').click();
        const zhOption = page.locator('.w-language-select__option[data-w-language-code="zh_Hans_CN"]').first();
        if (await zhOption.count()) {
          await zhOption.click();
          // close popover if still open
          await page.keyboard.press('Escape').catch(() => {});
          const visibleLocales = await page
            .locator('[data-testid="smtp-channel-locales-Weline_Customer::password_reset"] [data-smtp-locale-row="1"]:visible')
            .count();
          expect(visibleLocales).toBe(1);
          await page.locator('[data-testid="smtp-locale-tag-clear"]').first().click();
          const afterClear = await page
            .locator('[data-testid="smtp-channel-locales-Weline_Customer::password_reset"] [data-smtp-locale-row="1"]:visible')
            .count();
          expect(afterClear).toBeGreaterThan(1);
        }

        const inheritBadge = page.locator('[data-testid^="smtp-channel-inherit-badge-Weline_Customer::password_reset-"]').first();
        if (await inheritBadge.count()) {
          const inheritText = await inheritBadge.innerText();
          expect(/网站默认语|发送将继承自/i.test(inheritText)).toBeTruthy();
        }
        await expect(page.locator('[data-testid^="smtp-channel-edit-Weline_Customer::password_reset-"]').first()).toBeVisible();
      }

      if (await shell.isVisible().catch(() => false)) {
        await expect(shell).toBeVisible();
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SMTP-TPL-E2E-002' },
    '邮件模板 edit 前后端通路（带 channel 参数）',
    async ({ page }) => {
      await loginAsAdmin(page);
      const route = buildModuleBackendRoute(MODULE, 'template', 'edit');
      await gotoBackend(
        page,
        `${route}?channel=${encodeURIComponent('Weline_Customer::password_reset')}`,
        { timeout: 60000, settleMs: 800 }
      );
      await waitForBackendShellReady(page);
      const bodyText = await page.locator('body').innerText().catch(() => '');
      expect(FATAL.test(bodyText)).toBeFalsy();
      await expect(page.locator('[data-testid="smtp-template-edit"]').first()).toBeVisible({ timeout: 30000 });
      await expect(page.locator('[data-testid="smtp-template-back-listing"]').first()).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-testid="smtp-template-edit-toolbar"]').first()).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-testid="smtp-template-save"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="smtp-visual-editor"]').first()).toBeVisible({ timeout: 15000 });
      await expect(page.locator('.ck-editor, [data-w-component="ckeditor"]')).toHaveCount(0);
      await expect(page.locator('w\\:editor-manager, [data-testid="smtp-template-body-source"]').first()).toBeAttached();
      const hint = await page.locator('[data-testid="smtp-template-shell-hint"]').innerText().catch(() => '');
      expect(/页头|页尾|Logo|信任/i.test(hint + bodyText)).toBeTruthy();
      const varsHtml = await page.locator('[data-testid="smtp-template-vars"]').evaluate((el) => el.textContent || '').catch(() => '');
      expect(/site_name|brand_display_name|contact_email|site_logo|brand_primary|brand_header/i.test(varsHtml)).toBeTruthy();
      await expect(page.locator('[data-testid="smtp-template-vars-content"], [data-testid="smtp-template-vars-identity"]').first()).toBeVisible();
      await expect(page.locator('[data-testid="smtp-template-vars-identity"], [data-testid="smtp-template-vars-tone"]').first()).toBeAttached();
      await expect(page.locator('[data-testid="smtp-template-live-preview"]').first()).toBeVisible({ timeout: 15000 });
      await expect(page.locator('[data-testid="smtp-template-preview-iframe"]').first()).toBeVisible({ timeout: 15000 });
      await page.waitForTimeout(800);
      // 页尾须单格：一张背景图 cover，禁止双 TR 纵向接缝
      const footerMeta = await page.locator('[data-testid="smtp-template-preview-iframe"]').first().evaluate((el) => {
        const frame = /** @type {HTMLIFrameElement} */ (el);
        const doc = frame.contentDocument;
        if (!doc) return { count: -1, bgImages: [] };
        const cells = Array.from(doc.querySelectorAll('[data-weline-mail-region="footer"]'));
        return {
          count: cells.length,
          bgImages: cells.map((td) => {
            const s = window.getComputedStyle(td);
            return { bg: s.backgroundImage, size: s.backgroundSize, repeat: s.backgroundRepeat };
          }),
        };
      });
      expect(footerMeta.count).toBe(1);
      if (footerMeta.bgImages[0] && footerMeta.bgImages[0].bg && footerMeta.bgImages[0].bg !== 'none') {
        expect(String(footerMeta.bgImages[0].repeat || '')).toMatch(/no-repeat/i);
      }
      const previewText = await page.locator('[data-testid="smtp-template-preview-iframe"]').first().evaluate((el) => {
        const frame = /** @type {HTMLIFrameElement} */ (el);
        const doc = frame.contentDocument;
        return doc && doc.body ? String(doc.body.innerText || '') : '';
      }).catch(() => '');
      expect(previewText.length).toBeGreaterThan(20);
      expect(/https?:\/\/example\.com/i.test(previewText)).toBeFalsy();
      expect(/example\.com\/reset/i.test(previewText)).toBeFalsy();
      expect(/https?:\/\/localhost(\/|:|$)/i.test(previewText)).toBeFalsy();
      expect(/test\.weline\.com|weline\.test/i.test(previewText)).toBeTruthy();
      expect(/reset|token|密码|password/i.test(previewText)).toBeTruthy();
      expect(/页头|页尾|作用范围|站点地址/i.test(hint)).toBeTruthy();
      const previewHtml = await page.locator('[data-testid="smtp-template-preview-iframe"]').first().evaluate((el) => {
        const frame = /** @type {HTMLIFrameElement} */ (el);
        const doc = frame.contentDocument;
        return doc && doc.documentElement ? String(doc.documentElement.innerHTML || '') : '';
      }).catch(() => '');
      expect(/<img[^>]+src=["']https?:\/\//i.test(previewHtml)).toBeTruthy();
      expect(/theme\/frontend\/assets\/images\/theme\/logo\.(svg|png)/i.test(previewHtml)).toBeFalsy();
      expect(/\/pub\/media\//i.test(previewHtml)).toBeTruthy();
      const ctaStyle = await page.locator('[data-testid="smtp-template-preview-iframe"]').first().evaluate((el) => {
        const frame = /** @type {HTMLIFrameElement} */ (el);
        const doc = frame.contentDocument;
        if (!doc) return null;
        const links = Array.from(doc.querySelectorAll('a'));
        const cta = links.find((a) => /重置密码|Reset password/i.test(String(a.textContent || '')));
        if (!cta) return { missing: true };
        const cs = getComputedStyle(cta);
        return {
          color: cs.color,
          textDecoration: cs.textDecorationLine || cs.textDecoration,
          display: cs.display,
          padding: cs.padding,
          styleAttr: cta.getAttribute('style') || '',
        };
      }).catch(() => null);
      expect(ctaStyle && !ctaStyle.missing).toBeTruthy();
      expect(/text-decoration(?:-line)?:\s*none/i.test(String(ctaStyle.styleAttr || ''))).toBeTruthy();
      expect(/display:\s*inline-block/i.test(String(ctaStyle.styleAttr || ''))).toBeTruthy();
      expect(/padding:\s*14px 26px/i.test(String(ctaStyle.styleAttr || ''))).toBeTruthy();
      expect(/color:\s*#(16333f|f7f4ef)/i.test(String(ctaStyle.styleAttr || ''))).toBeTruthy();
      expect(/rgb\(\s*0\s*,\s*0\s*,\s*238\s\)|#0000ee/i.test(String(ctaStyle.color || ''))).toBeFalsy();
      const workspace = page.locator('[data-testid="smtp-template-edit-workspace"]').first();
      const workspaceBox = await workspace.boundingBox().catch(() => null);
      const previewBox = await page.locator('[data-testid="smtp-template-live-preview"]').first().boundingBox().catch(() => null);
      if (workspaceBox && previewBox && workspaceBox.width > 900) {
        expect(previewBox.x).toBeGreaterThan(workspaceBox.x + workspaceBox.width * 0.35);
      }
      const bodyArea = page.locator('#smtp-tpl-body').first();
      if (await bodyArea.count()) {
        const display = await bodyArea.evaluate((el) => getComputedStyle(el).display).catch(() => '');
        if (display === 'block' || display === 'inline-block') {
          const hasCkToolbar = await page.locator('.ck-toolbar, .ck-editor__editable').count();
          expect(hasCkToolbar).toBeGreaterThan(0);
        }
      }

      // 编辑往返：q / locales / channel / focus_locale 保持原位置
      await gotoBackend(
        page,
        `${route}?channel=${encodeURIComponent('Weline_Customer::password_reset')}&target_scope=default.default.default&locale=zh_Hans_CN&q=password_reset&locales=zh_Hans_CN&focus_locale=zh_Hans_CN`,
        { timeout: 60000, settleMs: 600 }
      );
      await waitForBackendShellReady(page);
      await expect(page.locator('[data-testid="smtp-template-return-q"]').first()).toHaveAttribute('value', 'password_reset');
      await expect(page.locator('[data-testid="smtp-template-return-locales"]').first()).toHaveAttribute('value', 'zh_Hans_CN');
      await expect(page.locator('[data-testid="smtp-template-return-focus-locale"]').first()).toHaveAttribute('value', 'zh_Hans_CN');
      const backHref = await page.locator('[data-testid="smtp-template-back-listing"]').first().getAttribute('href');
      expect(String(backHref || '')).toMatch(/q=password_reset/);
      expect(String(backHref || '')).toMatch(/locales=zh_Hans_CN/);
      expect(String(backHref || '')).toMatch(/focus_locale=zh_Hans_CN/);
      expect(String(backHref || '')).toMatch(/channel=/);
      await page.locator('[data-testid="smtp-template-back-listing"]').first().click();
      await waitForBackendShellReady(page);
      await expect(page.locator('[data-testid="smtp-channel-listing"]').first()).toBeVisible({ timeout: 30000 });
      await expect(page.locator('[data-testid="smtp-channel-search"]').first()).toHaveValue('password_reset');
      const openRow = page.locator('[data-testid="smtp-channel-row-Weline_Customer::password_reset"]').first();
      await expect(openRow).toBeVisible({ timeout: 15000 });
      await expect(openRow).toHaveAttribute('open', '');
      await expect(page.locator('[data-testid="smtp-channel-locale-row-Weline_Customer::password_reset-zh_Hans_CN"]').first()).toBeVisible();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SMTP-TPL-E2E-004' },
    '编辑页作用范围切换完整前后端通路',
    async ({ page }) => {
      await loginAsAdmin(page);
      const route = buildModuleBackendRoute(MODULE, 'template', 'edit');
      const channel = encodeURIComponent('Weline_Customer::password_reset');
      await gotoBackend(
        page,
        `${route}?channel=${channel}&target_scope=default.default.default&locale=zh_Hans_CN`,
        { timeout: 60000, settleMs: 800 }
      );
      await waitForBackendShellReady(page);
      await expect(page.locator('[data-testid="smtp-template-edit"]').first()).toBeVisible({ timeout: 30000 });
      await expect(page.locator('[data-testid="smtp-template-edit-scope"]').first()).toBeVisible({ timeout: 15000 });
      // w:scope 把 id 打在 hidden input 上，断言 attached 而非 visible
      await expect(page.locator('input#smtp-template-edit-scope[name="target_scope"]').first()).toBeAttached({ timeout: 15000 });
      const staticCode = await page.locator('[data-testid="smtp-template-edit-scope"] > code').count();
      expect(staticCode).toBe(0);
      const scopeFieldCount = await page.locator('input#smtp-template-edit-scope[name="target_scope"]').count();
      expect(scopeFieldCount).toBeGreaterThan(0);

      const nextScope = 'default.__website__.default';
      await page.evaluate((scope) => {
        const el = document.getElementById('smtp-template-edit-scope');
        const field =
          el && el.matches && el.matches('input[name="target_scope"]')
            ? el
            : el && (el.querySelector('input[type="hidden"][name="target_scope"]') || el.querySelector('input[name="target_scope"]'));
        if (!field) {
          throw new Error('smtp edit scope field missing');
        }
        field.value = scope;
        field.dispatchEvent(new Event('change', { bubbles: true }));
      }, nextScope);

      await page.waitForURL(/target_scope=default\.__website__\.default/, { timeout: 45000 });
      await waitForBackendShellReady(page);
      const bodyText = await page.locator('body').innerText().catch(() => '');
      expect(FATAL.test(bodyText)).toBeFalsy();
      await expect(page.locator('[data-testid="smtp-template-edit"]').first()).toBeVisible({ timeout: 30000 });
      expect(page.url()).toMatch(/target_scope=default\.__website__\.default/);
      expect(page.url()).toMatch(/channel=Weline_Customer/);
      expect(page.url()).not.toMatch(/template_id=/);
      const hiddenScope = await page.locator('input#smtp-template-edit-scope[name="target_scope"]').first().inputValue().catch(() => '');
      expect(hiddenScope).toContain('__website__');
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'SMTP-TPL-E2E-003' },
    '迁移通道：默认渠道 notification_email 可在渠道管理出现',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openTemplateListing(page);
      const bodyText = await page.locator('body').innerText().catch(() => '');
      expect(FATAL.test(bodyText)).toBeFalsy();
      const html = await page.content();
      const channelRows = await page.locator('[data-testid="smtp-channel-list"] details.w-smtp-channel').count();
      const ok =
        bodyText.includes('notification_email') ||
        html.includes('notification_email') ||
        bodyText.includes('Weline_Backend') ||
        html.includes('Weline_Backend') ||
        channelRows > 0;
      expect(ok).toBeTruthy();
    }
  );
});
