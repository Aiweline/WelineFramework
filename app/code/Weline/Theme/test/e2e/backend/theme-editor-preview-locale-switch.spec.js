// @weline-e2e-runtime wls
// @ts-check
const {
  test,
  expect,
  getActiveTheme,
  gotoBackend,
  loginAsAdmin,
} = require('../../../../../../../tests/e2e/framework');

test.describe('Theme editor preview locale switch', () => {
  test.setTimeout(180000);

  test('preview language switcher posts locale-change and reloads canvas', async ({ page }) => {
    const activeTheme = getActiveTheme('frontend');
    test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

    await page.setViewportSize({ width: 1440, height: 900 });
    await loginAsAdmin(page, {
      timeout: 60000,
      settleMs: 1000,
      allowPasswordFallback: true,
    });

    await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${activeTheme.id}&page_type=homepage`, {
      waitUntil: 'domcontentloaded',
      timeout: 90000,
      settleMs: 2000,
    });

    const previewFrame = page.locator('#previewFrame');
    await expect(previewFrame).toHaveAttribute(
      'src',
      /(?:theme-preview\/content|layout-preview|[?&](?:editor_mode=1|shell=theme-editor))/,
      { timeout: 90000 },
    );

    // Edit-lock modal can sit on top while the canvas boots.
    await page.locator('#themeEditor').locator('text=/正在确认编辑权限|正在准备编辑/').waitFor({ state: 'hidden', timeout: 90000 }).catch(() => {});
    await page.locator('.w-modal, [role="dialog"]').filter({ hasText: '编辑锁' }).waitFor({ state: 'hidden', timeout: 90000 }).catch(() => {});

    const websiteDefaultLocale = await page.evaluate(() => {
      const raw = String(document.getElementById('themeEditor')?.dataset?.defaultLocale || '').trim().replace(/-/g, '_');
      return raw || 'zh_Hans_CN';
    });

    const frame = page.frameLocator('#previewFrame');
    await frame.locator('body').first().waitFor({ state: 'visible', timeout: 90000 });
    await expect(frame.locator('script[src*="weline-theme-preview.js"]')).toHaveCount(1, { timeout: 90000 });
    // Script tag in DOM ≠ module listeners installed; wait for Preview API boot.
    await expect.poll(async () => {
      return page.frameLocator('#previewFrame').locator('body').evaluate(() => {
        return Boolean(window.Weline?.Theme?.Preview);
      });
    }, { timeout: 90000 }).toBe(true);
    // Menu options stay hidden until the trigger opens; attached is enough for click.
    await expect(frame.locator('a.w-language-switcher__option[data-lang]').first()).toBeAttached({ timeout: 90000 });

    // Prefer a non-default storefront language so the canvas must use a real path prefix.
    // (Website default stays unprefixed; App 301-strips default language segments.)
    const targetLocale = await frame.locator('body').evaluate((defaultLocale) => {
      const preferred = ['zh_Hans_CN', 'zh_Hant_TW', 'en_US', 'ja_JP', 'fr_FR'];
      const options = Array.from(document.querySelectorAll('a.w-language-switcher__option[data-lang]'));
      const pick = preferred
        .map((code) => options.find((el) => String(el.getAttribute('data-lang') || '').replace(/-/g, '_') === code))
        .find((el) => {
          if (!(el instanceof HTMLAnchorElement)) {
            return false;
          }
          const code = String(el.getAttribute('data-lang') || '').replace(/-/g, '_');
          return code && code.toLowerCase() !== String(defaultLocale || '').toLowerCase();
        })
        || options.find((el) => {
          if (!(el instanceof HTMLAnchorElement)) {
            return false;
          }
          const code = String(el.getAttribute('data-lang') || '').replace(/-/g, '_');
          return code && code.toLowerCase() !== String(defaultLocale || '').toLowerCase();
        })
        || options[0];
      if (!(pick instanceof HTMLAnchorElement)) {
        throw new Error('no language option in preview');
      }
      const locale = String(pick.getAttribute('data-lang') || '').trim();
      pick.dispatchEvent(new MouseEvent('click', {
        bubbles: true,
        cancelable: true,
        composed: true,
        view: window,
      }));
      return locale;
    }, websiteDefaultLocale);

    const escapedLocale = String(targetLocale).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const isWebsiteDefault = String(targetLocale).replace(/-/g, '_').toLowerCase()
      === String(websiteDefaultLocale).replace(/-/g, '_').toLowerCase();

    await expect.poll(async () => {
      return page.evaluate(() => {
        const bag = window.__WelineLocaleSwitchTrace || [];
        return {
          stages: bag.map((item) => item.stage),
          previewSrc: String(document.getElementById('previewFrame')?.src || ''),
          bag,
        };
      });
    }, { timeout: 90000 }).toMatchObject({
      stages: expect.arrayContaining(['parent-locale-change-ok']),
    });

    const result = await page.evaluate(() => ({
      stages: (window.__WelineLocaleSwitchTrace || []).map((item) => item.stage),
      bag: window.__WelineLocaleSwitchTrace || [],
      previewSrc: String(document.getElementById('previewFrame')?.src || ''),
      toolbar: String(document.getElementById('editorLangSwitcher')?.value || ''),
      defaultLocale: String(document.getElementById('themeEditor')?.dataset?.defaultLocale || ''),
    }));
    // eslint-disable-next-line no-console
    console.log('[locale-switch-trace]', JSON.stringify({
      targetLocale,
      websiteDefaultLocale,
      isWebsiteDefault,
      ...result,
    }, null, 2));
    expect(result.stages.includes('parent-locale-change-fail')).toBeFalsy();
    expect(result.previewSrc).not.toMatch(/[?&](?:locale|locale_code|lang)=/);
    if (isWebsiteDefault) {
      expect(result.previewSrc).not.toMatch(new RegExp(`/${escapedLocale}(?:/|\\?)`));
    } else {
      expect(result.previewSrc).toMatch(new RegExp(`/${escapedLocale}(?:/|\\?)`));
    }

    await expect.poll(async () => {
      return page.frameLocator('#previewFrame').locator('html').first().getAttribute('lang');
    }, { timeout: 90000 }).toMatch(new RegExp(String(targetLocale).slice(0, 2), 'i'));

    const canvasProbe = await page.frameLocator('#previewFrame').locator('body').evaluate(() => {
      const htmlLang = String(document.documentElement.getAttribute('lang') || '');
      const switcher = document.querySelector('[data-i18n-switcher] [aria-expanded], [data-weline-choice-switcher="language"]');
      const triggerText = String(switcher?.textContent || '').replace(/\s+/g, ' ').trim();
      return {
        htmlLang,
        triggerText,
        locationPath: String(window.location.pathname || ''),
        locationSearch: String(window.location.search || ''),
      };
    });
    // eslint-disable-next-line no-console
    console.log('[locale-switch-canvas-probe]', JSON.stringify(canvasProbe, null, 2));
    expect(canvasProbe.locationSearch).not.toMatch(/[?&](?:locale|locale_code|lang)=/);
    if (isWebsiteDefault) {
      expect(canvasProbe.locationPath).not.toMatch(new RegExp(`/${escapedLocale}(?:/|$)`));
    } else {
      expect(canvasProbe.locationPath).toMatch(new RegExp(`/${escapedLocale}(?:/|$)`));
    }
    expect(String(canvasProbe.htmlLang || '')).toMatch(new RegExp(String(targetLocale).slice(0, 2), 'i'));
  });
});
