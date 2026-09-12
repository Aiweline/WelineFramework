const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  installBackendBrowserGuards,
  gotoBackend,
  buildModuleBackendRoute,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_CKEditorEditorManager';
const PRODUCT_UUID = '4fe1999c-745a-54e1-a37e-beec2fc31787';

moduleDescribe(test, MODULE, 'CKEditor 暗色外壳与内容浅色岛', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'CK-CKEDITOR-DARK-CONTENT-001' },
    '暗色模式下详情编辑器内容区保持浅色岛深色字',
    async ({ page }) => {
      const guards = installBackendBrowserGuards(page, {
        allowedResponses: [
          { status: 403, pattern: /\/api\/framework\/query-bin/ },
        ],
      });
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      try {
        const route = buildModuleBackendRoute(
          'Weline_Product',
          'catalog',
          'edit-product'
        );
        await gotoBackend(page, `${route}?website_id=0&global_product_uuid=${PRODUCT_UUID}`, {
          waitUntil: 'domcontentloaded',
          timeout: 60000,
          settleMs: 1000,
        });
        await page.locator('[data-product-tab="basic"]').click();
        const editorHost = page.locator('[data-testid="product-edit-description-editor"]');
        await expect(editorHost).toBeVisible({ timeout: 60000 });

        await page.evaluate(() => {
          const roots = [document.documentElement, document.body].filter(Boolean);
          for (const el of roots) {
            el.setAttribute('data-theme', 'dark');
            el.setAttribute('data-theme-mode', 'dark');
            el.setAttribute('data-theme-preference', 'dark');
          }
        });

        const editable = page.locator(
          '.w-product-description-editor .ck-editor__editable, .w-product-description-editor .ck-content'
        ).first();
        await expect(editable).toBeVisible({ timeout: 60000 });

        // Wait until CKEditor has content or at least editable styling
        await page.waitForTimeout(1500);

        const styles = await editable.evaluate((node) => {
          const cs = getComputedStyle(node);
          const heading = node.querySelector('h1, h2, h3, p, li, *');
          const headingCs = heading ? getComputedStyle(heading) : null;
          const themeHref = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
            .map((l) => l.getAttribute('href') || '')
            .find((h) => h.includes('ckeditor-theme.css'));
          return {
            color: cs.color,
            backgroundColor: cs.backgroundColor,
            colorScheme: cs.colorScheme,
            headingColor: headingCs ? headingCs.color : null,
            themeHref: themeHref || '',
          };
        });

        expect(styles.themeHref).toContain('ckeditor-theme.css');

        const parseRgb = (value) => {
          const m = String(value || '').match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
          if (!m) {
            return null;
          }
          return [Number(m[1]), Number(m[2]), Number(m[3])];
        };
        const luminance = (rgb) => {
          if (!rgb) {
            return null;
          }
          const [r, g, b] = rgb.map((c) => {
            const s = c / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
          });
          return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };

        const textRgb = parseRgb(styles.headingColor || styles.color);
        const bgRgb = parseRgb(styles.backgroundColor);
        const textL = luminance(textRgb);
        const bgL = luminance(bgRgb);
        expect(textL, `text color=${styles.headingColor || styles.color}`).not.toBeNull();
        expect(bgL, `bg color=${styles.backgroundColor}`).not.toBeNull();
        expect(bgL).toBeGreaterThan(0.7);
        expect(textL).toBeLessThan(0.45);

        const iconPaint = await page.evaluate(() => {
          const icon = document.querySelector(
            '.ck.ck-toolbar .ck-button:not(.ck-on) .ck-icon'
          );
          if (!icon) {
            return null;
          }
          const fillNode = icon.querySelector('.ck-icon__fill, path');
          const cs = getComputedStyle(icon);
          const fillCs = fillNode ? getComputedStyle(fillNode) : null;
          return {
            color: cs.color,
            fill: fillCs ? fillCs.fill : null,
          };
        });
        expect(iconPaint, 'toolbar icon missing').not.toBeNull();
        const iconColorL = luminance(parseRgb(iconPaint.color));
        const iconFillL = luminance(parseRgb(iconPaint.fill));
        // Prefer fill; fall back to color — both must be light on dark toolbar.
        const iconL = iconFillL ?? iconColorL;
        expect(iconL, `icon paint=${JSON.stringify(iconPaint)}`).not.toBeNull();
        expect(iconL).toBeGreaterThan(0.55);
      } finally {
        // Product admin edit may emit BinQuery 403 noise unrelated to CKEditor contrast.
        for (let i = guards.failures.length - 1; i >= 0; i -= 1) {
          const item = guards.failures[i];
          if (item.includes('query-bin') || item.includes('BinQuery ERR')) {
            guards.failures.splice(i, 1);
          }
        }
        guards.assertClean();
      }
    }
  );
});
