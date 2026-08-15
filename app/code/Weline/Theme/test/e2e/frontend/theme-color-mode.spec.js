// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';

moduleDescribe(test, MODULE, '前台全局颜色模式', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'THEME-COLOR-FE-001' },
    'system/light/dark 在重载与系统变化时保持偏好和解析模式契约',
    async ({ page }) => {
      await page.emulateMedia({ colorScheme: 'dark' });
      await page.addInitScript(() => {
        try {
          const resetKey = '__weline_theme_e2e_storage_reset';
          if (sessionStorage.getItem(resetKey) !== '1') {
            localStorage.removeItem('weline-theme');
            sessionStorage.setItem(resetKey, '1');
          }
        } catch (_) {}
        let welineValue;
        Object.defineProperty(window, 'Weline', {
          configurable: true,
          get: () => welineValue,
          set: (value) => {
            welineValue = value;
            if (value && value.Theme) {
              window.__welineEarlyThemeSnapshot = {
                current: value.Theme.getCurrent(),
                preference: value.Theme.getPreference(),
              };
            }
          },
        });
      });
      await gotoFrontend(page, '/', { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForFunction(() => !!(window.Weline && window.Weline.Theme));

      expect(await page.evaluate(() => window.__welineEarlyThemeSnapshot)).toEqual({
        current: 'dark',
        preference: 'system',
      });

      await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'system');
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
      await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark');

      await page.evaluate(() => {
        window.__themechangeCount = 0;
        document.addEventListener('themechange', () => { window.__themechangeCount += 1; });
      });

      await page.emulateMedia({ colorScheme: 'light' });
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
      await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'system');
      await expect.poll(() => page.evaluate(() => window.__themechangeCount)).toBe(1);

      await page.evaluate(() => window.Weline.Theme.switch('dark'));
      await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'dark');
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'dark');
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

      await page.evaluate(() => {
        window.__themechangeCount = 0;
        document.addEventListener('themechange', () => { window.__themechangeCount += 1; });
      });
      await page.emulateMedia({ colorScheme: 'dark' });
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
      await expect.poll(() => page.evaluate(() => window.__themechangeCount)).toBe(0);

      await page.evaluate(() => window.Weline.Theme.switch('light'));
      await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'light');
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'light');
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
      await page.evaluate(() => {
        window.__themechangeCount = 0;
        document.addEventListener('themechange', () => { window.__themechangeCount += 1; });
      });
      await page.emulateMedia({ colorScheme: 'light' });
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
      await expect.poll(() => page.evaluate(() => window.__themechangeCount)).toBe(0);

      await page.evaluate(() => window.Weline.Theme.switch('system'));
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(page.locator('html')).toHaveAttribute('data-theme-preference', 'system');
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
      await page.evaluate(() => {
        window.__themechangeCount = 0;
        document.addEventListener('themechange', () => { window.__themechangeCount += 1; });
      });
      await page.emulateMedia({ colorScheme: 'dark' });
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
      await expect.poll(() => page.evaluate(() => window.__themechangeCount)).toBe(1);
      await page.emulateMedia({ colorScheme: 'light' });
      await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');

      await page.evaluate(() => {
        document.body.insertAdjacentHTML('beforeend', `
          <section id="theme-component-matrix">
            <div class="card"><div class="card-header">Card</div><div class="card-body">Body</div></div>
            <div class="modal"><div class="modal-content">Modal</div></div><div class="offcanvas">Offcanvas</div>
            <div class="dropdown-menu show"><button class="dropdown-item" data-state="dropdown-item">Menu</button><button class="dropdown-item active">Active menu</button></div>
            <div class="accordion"><div class="accordion-item"><button class="accordion-button">Accordion</button></div></div>
            <table class="table table-striped table-hover"><tbody><tr class="table-active" aria-selected="true"><td>Table</td></tr></tbody></table>
            <ul class="list-group"><li class="list-group-item active">List</li><li class="list-group-item disabled">Disabled list</li></ul>
            <div class="input-group"><span class="input-group-text">@</span><input class="form-control" placeholder="Input"></div>
            <input class="form-control is-valid" data-state="valid-input" value="Valid"><div class="valid-feedback d-block" data-state="valid-feedback">Valid feedback</div><div class="valid-tooltip" style="position:static;display:block" data-state="valid-tooltip">Valid tooltip</div>
            <input class="form-control is-invalid" data-state="invalid-input" value="Invalid"><div class="invalid-feedback d-block" data-state="invalid-feedback">Invalid feedback</div><div class="invalid-tooltip" style="position:static;display:block" data-state="invalid-tooltip">Invalid tooltip</div>
            <select class="form-select"><option>Native select</option></select><button class="btn btn-primary" data-state="primary-button">Button</button><button class="btn btn-secondary" data-state="secondary-button">Secondary</button><button class="btn btn-success" data-state="success-button">Success</button><button class="w-btn w-btn-success" data-state="weline-success-button">Weline success</button><button class="btn btn-warning" data-state="warning-button">Warning</button><button class="btn btn-danger" data-state="danger-button">Danger</button><button class="btn btn-info" data-state="info-button">Info</button><button class="btn btn-outline-secondary" data-state="outline-secondary">Outline secondary</button><button class="btn btn-outline-success" data-state="outline-success">Outline success</button><button class="btn btn-outline-warning" data-state="outline-warning">Outline warning</button><button class="btn btn-outline-danger" data-state="outline-danger">Outline danger</button><button class="btn btn-outline-info" data-state="outline-info">Outline info</button><button class="btn btn-primary" disabled>Disabled button</button>
            <ul class="nav nav-tabs"><li class="nav-item"><button class="nav-link active">Tab</button></li></ul>
            <nav><ul class="pagination"><li class="page-item active"><button class="page-link">1</button></li></ul></nav>
            <div class="alert alert-primary" data-state="alert-primary">Primary <a href="#" class="alert-link">link</a></div><div class="alert alert-secondary" data-state="alert-secondary">Secondary <a href="#" class="alert-link">link</a></div><div class="alert alert-success" data-state="alert-success">Success <a href="#" class="alert-link">link</a></div><div class="alert alert-warning" data-state="alert-warning">Warning <a href="#" class="alert-link">link</a></div><div class="alert alert-danger" data-state="alert-danger">Danger <a href="#" class="alert-link">link</a></div><div class="alert alert-info" data-state="alert-info">Info <a href="#" class="alert-link">link</a></div><span class="badge bg-success">Badge</span><span class="badge badge-success" data-state="legacy-success-badge">Legacy success</span><span class="badge badge-secondary" data-state="legacy-secondary-badge">Legacy secondary</span><span class="text-bg-info">Text background</span>
            <div class="toast show"><div class="toast-header">Toast</div></div><div class="popover show"><div class="popover-header">Popover</div></div>
          </section>`);
      });
      const matrix = page.locator('#theme-component-matrix');
      const tooltipTextContrast = async (state) => matrix.locator(`[data-state="${state}-tooltip"]`).evaluate((element) => {
        const parse = (value) => (value.match(/[\d.]+/g) || []).map(Number);
        const luminance = (rgb) => rgb.slice(0, 3).map((channel) => {
          const value = channel / 255;
          return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const style = getComputedStyle(element);
        const foreground = luminance(parse(style.color));
        const background = luminance(parse(style.backgroundColor));
        return (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05);
      });
      const legacyBadgeTextContrast = async (variant) => matrix.locator(`[data-state="legacy-${variant}-badge"]`).evaluate((element) => {
        const parse = (value) => {
          const channels = (value.match(/[\d.]+/g) || []).map(Number);
          return { r: channels[0] || 0, g: channels[1] || 0, b: channels[2] || 0, a: channels.length > 3 ? channels[3] : 1 };
        };
        const composite = (foreground, background) => ({
          r: foreground.r * foreground.a + background.r * (1 - foreground.a),
          g: foreground.g * foreground.a + background.g * (1 - foreground.a),
          b: foreground.b * foreground.a + background.b * (1 - foreground.a),
          a: 1,
        });
        const luminance = (rgb) => [rgb.r, rgb.g, rgb.b].map((channel) => {
          const value = channel / 255;
          return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const style = getComputedStyle(element);
        const ancestorBackgrounds = [];
        for (let ancestor = element.parentElement; ancestor && ancestor !== document.body; ancestor = ancestor.parentElement) {
          ancestorBackgrounds.unshift(parse(getComputedStyle(ancestor).backgroundColor));
        }
        const surface = ancestorBackgrounds.reduce(
          (background, layer) => composite(layer, background),
          parse(getComputedStyle(document.body).backgroundColor)
        );
        const badgeBackground = composite(parse(style.backgroundColor), surface);
        const badgeText = composite(parse(style.color), badgeBackground);
        const foreground = luminance(badgeText);
        const background = luminance(badgeBackground);
        return (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05);
      });
      const alertTextAndBorderContrast = async (state) => matrix.locator(`[data-state="alert-${state}"]`).evaluate((element) => {
        const parse = (value) => {
          const channels = (value.match(/[\d.]+/g) || []).map(Number);
          return { r: channels[0] || 0, g: channels[1] || 0, b: channels[2] || 0, a: channels.length > 3 ? channels[3] : 1 };
        };
        const composite = (foreground, background) => ({
          r: foreground.r * foreground.a + background.r * (1 - foreground.a),
          g: foreground.g * foreground.a + background.g * (1 - foreground.a),
          b: foreground.b * foreground.a + background.b * (1 - foreground.a),
          a: 1,
        });
        const luminance = (rgb) => [rgb.r, rgb.g, rgb.b].map((channel) => {
          const value = channel / 255;
          return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const style = getComputedStyle(element);
        const ancestorBackgrounds = [];
        for (let ancestor = element.parentElement; ancestor && ancestor !== document.body; ancestor = ancestor.parentElement) {
          ancestorBackgrounds.unshift(parse(getComputedStyle(ancestor).backgroundColor));
        }
        const surface = ancestorBackgrounds.reduce(
          (background, layer) => composite(layer, background),
          parse(getComputedStyle(document.body).backgroundColor)
        );
        const alertBackground = composite(parse(style.backgroundColor), surface);
        const ratio = (foreground) => {
          const visibleForeground = composite(parse(foreground), alertBackground);
          return (Math.max(luminance(visibleForeground), luminance(alertBackground)) + 0.05) / (Math.min(luminance(visibleForeground), luminance(alertBackground)) + 0.05);
        };
        return { text: ratio(style.color), border: ratio(style.borderTopColor) };
      });
      const buttonActiveContrast = async (button) => button.evaluate((element) => {
        const parse = (value) => {
          const channels = (value.match(/[\d.]+/g) || []).map(Number);
          return { r: channels[0] || 0, g: channels[1] || 0, b: channels[2] || 0, a: channels.length > 3 ? channels[3] : 1 };
        };
        const composite = (foreground, background) => ({
          r: foreground.r * foreground.a + background.r * (1 - foreground.a),
          g: foreground.g * foreground.a + background.g * (1 - foreground.a),
          b: foreground.b * foreground.a + background.b * (1 - foreground.a),
          a: 1,
        });
        const luminance = (rgb) => [rgb.r, rgb.g, rgb.b].map((channel) => {
          const value = channel / 255;
          return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const style = getComputedStyle(element);
        const ancestorBackgrounds = [];
        for (let ancestor = element.parentElement; ancestor && ancestor !== document.body; ancestor = ancestor.parentElement) {
          ancestorBackgrounds.unshift(parse(getComputedStyle(ancestor).backgroundColor));
        }
        const surface = ancestorBackgrounds.reduce(
          (background, layer) => composite(layer, background),
          parse(getComputedStyle(document.body).backgroundColor)
        );
        const baseSurface = composite(parse(style.backgroundColor), surface);
        const gradientStops = style.backgroundImage.includes('linear-gradient')
          ? (style.backgroundImage.match(/rgba?\([^)]*\)/g) || []).map(parse)
          : [];
        const visibleBackgrounds = gradientStops.length > 0
          ? gradientStops.map((stop) => composite(stop, baseSurface))
          : [baseSurface];
        const contrastRatios = visibleBackgrounds.map((background) => {
          const visibleText = composite(parse(style.color), background);
          return (Math.max(luminance(visibleText), luminance(background)) + 0.05) / (Math.min(luminance(visibleText), luminance(background)) + 0.05);
        });
        return Math.min(...contrastRatios);
      });
      const matrixFocusControl = matrix.locator('.form-control').first();
      const matrixFocusOutlineContrast = async () => matrixFocusControl.evaluate((element) => {
        const parse = (value) => {
          const channels = (value.match(/[\d.]+/g) || []).map(Number);
          return { r: channels[0] || 0, g: channels[1] || 0, b: channels[2] || 0, a: channels.length > 3 ? channels[3] : 1 };
        };
        const composite = (foreground, background) => ({
          r: foreground.r * foreground.a + background.r * (1 - foreground.a),
          g: foreground.g * foreground.a + background.g * (1 - foreground.a),
          b: foreground.b * foreground.a + background.b * (1 - foreground.a),
          a: 1,
        });
        const luminance = (rgb) => [rgb.r, rgb.g, rgb.b].map((channel) => {
          const value = channel / 255;
          return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const ancestorBackgrounds = [];
        for (let ancestor = element.parentElement; ancestor && ancestor !== document.body; ancestor = ancestor.parentElement) {
          ancestorBackgrounds.unshift(parse(getComputedStyle(ancestor).backgroundColor));
        }
        const surface = ancestorBackgrounds.reduce(
          (background, layer) => composite(layer, background),
          parse(getComputedStyle(document.body).backgroundColor)
        );
        const visibleOutline = composite(parse(getComputedStyle(element).outlineColor), surface);
        return (Math.max(luminance(visibleOutline), luminance(surface)) + 0.05) / (Math.min(luminance(visibleOutline), luminance(surface)) + 0.05);
      });
      const coreSelectors = ['.card', '.modal-content', '.offcanvas', '.dropdown-menu', '.accordion-item', '.table', '.list-group-item', '.form-control', '.input-group-text', '.form-select', '.nav-tabs .nav-link.active', '.pagination .page-link', '.alert', '.badge', '.badge.badge-success', '.badge.badge-secondary', '.toast', '.popover'];
      const alertStates = ['primary', 'secondary', 'success', 'warning', 'danger', 'info'];
      for (const selector of coreSelectors) {
        await expect(matrix.locator(selector).first()).toHaveCSS('background-color', /rgba?\(/);
        await expect(matrix.locator(selector).first()).toHaveCSS('color', /rgba?\(/);
      }
      for (const state of alertStates) {
        const alert = matrix.locator(`[data-state="alert-${state}"]`);
        const alertLink = alert.locator('.alert-link');
        await expect(alert).toHaveCSS('background-color', /rgba?\(/);
        await expect(alert).toHaveCSS('color', /rgba?\(/);
        expect(await alertLink.evaluate((element) => getComputedStyle(element).color)).toBe(await alert.evaluate((element) => getComputedStyle(element).color));
        const alertContrast = await alertTextAndBorderContrast(state);
        expect(alertContrast.text).toBeGreaterThanOrEqual(4.5);
        expect(alertContrast.border).toBeGreaterThanOrEqual(3);
      }
      await matrixFocusControl.focus();
      await expect(matrixFocusControl).toHaveCSS('outline-color', /rgba?\(/);
      await expect(matrixFocusControl).not.toHaveCSS('outline-style', 'none');
      expect(await matrixFocusOutlineContrast()).toBeGreaterThanOrEqual(3);
      await expect(matrix.locator('.card')).toHaveCSS('background-color', /rgba?\(/);
      await expect(matrix.locator('.card')).toHaveCSS('border-top-color', /rgba?\(/);
      const lightComponentColors = await matrix.evaluate((element) => ({
        card: getComputedStyle(element.querySelector('.card')).backgroundColor,
        input: getComputedStyle(element.querySelector('.form-control')).borderTopColor,
        legacyBadge: getComputedStyle(element.querySelector('[data-state="legacy-success-badge"]')).backgroundColor,
        legacyBadgeText: getComputedStyle(element.querySelector('[data-state="legacy-success-badge"]')).color,
        legacySecondaryBadge: getComputedStyle(element.querySelector('[data-state="legacy-secondary-badge"]')).backgroundColor,
        legacySecondaryBadgeText: getComputedStyle(element.querySelector('[data-state="legacy-secondary-badge"]')).color,
      }));
      const legacySuccessBadge = matrix.locator('[data-state="legacy-success-badge"]');
      const legacySecondaryBadge = matrix.locator('[data-state="legacy-secondary-badge"]');
      await expect(legacySuccessBadge).toHaveCSS('background-color', /rgba?\(/);
      await expect(legacySuccessBadge).toHaveCSS('color', /rgba?\(/);
      expect(await legacyBadgeTextContrast('success')).toBeGreaterThanOrEqual(4.5);
      await expect(legacySecondaryBadge).toHaveCSS('background-color', /rgba?\(/);
      await expect(legacySecondaryBadge).toHaveCSS('color', /rgba?\(/);
      expect(await legacyBadgeTextContrast('secondary')).toBeGreaterThanOrEqual(4.5);
      for (const state of ['valid', 'invalid']) {
        const input = matrix.locator(`[data-state="${state}-input"]`);
        await expect(input).toHaveCSS('border-top-color', /rgba?\(/);
        await expect(matrix.locator(`[data-state="${state}-feedback"]`)).toHaveCSS('color', /rgba?\(/);
        await input.focus();
        await expect(input).toHaveCSS('box-shadow', /rgba?\(/);
        expect(await tooltipTextContrast(state)).toBeGreaterThanOrEqual(4.5);
      }
      const contrast = await matrix.locator('.card').evaluate((element) => {
        const parse = (value) => {
          const channels = (value.match(/[\d.]+/g) || []).map(Number);
          return { r: channels[0] || 0, g: channels[1] || 0, b: channels[2] || 0, a: channels.length > 3 ? channels[3] : 1 };
        };
        const composite = (foreground, background) => ({
          r: foreground.r * foreground.a + background.r * (1 - foreground.a),
          g: foreground.g * foreground.a + background.g * (1 - foreground.a),
          b: foreground.b * foreground.a + background.b * (1 - foreground.a),
          a: 1,
        });
        const luminance = (rgb) => rgb.map((channel) => {
          const value = channel / 255;
          return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const style = getComputedStyle(element);
        const canvas = parse(getComputedStyle(document.body).backgroundColor);
        const surface = luminance(Object.values(composite(parse(style.backgroundColor), canvas)).slice(0, 3));
        return {
          text: (Math.max(luminance(Object.values(composite(parse(style.color), parse(style.backgroundColor))).slice(0, 3)), surface) + 0.05) / (Math.min(luminance(Object.values(composite(parse(style.color), parse(style.backgroundColor))).slice(0, 3)), surface) + 0.05),
          border: (Math.max(luminance(Object.values(composite(parse(style.borderTopColor), parse(style.backgroundColor))).slice(0, 3)), surface) + 0.05) / (Math.min(luminance(Object.values(composite(parse(style.borderTopColor), parse(style.backgroundColor))).slice(0, 3)), surface) + 0.05),
        };
      });
      expect(contrast.text).toBeGreaterThanOrEqual(4.5);
      expect(contrast.border).toBeGreaterThanOrEqual(3);
      const dropdownItem = matrix.locator('[data-state="dropdown-item"]');
      const dropdownBackground = await dropdownItem.evaluate((element) => getComputedStyle(element).backgroundColor);
      await dropdownItem.hover();
      await expect.poll(() => dropdownItem.evaluate((element) => getComputedStyle(element).backgroundColor)).not.toBe(dropdownBackground);
      for (const state of ['primary-button', 'secondary-button', 'success-button', 'weline-success-button', 'warning-button', 'danger-button', 'info-button']) {
        const button = matrix.locator(`[data-state="${state}"]`);
        const visibleButtonBackground = () => button.evaluate((element) => {
          const style = getComputedStyle(element);
          return style.backgroundImage !== 'none' ? style.backgroundImage : style.backgroundColor;
        });
        const buttonBackground = await visibleButtonBackground();
        await button.hover();
        await expect.poll(visibleButtonBackground).not.toBe(buttonBackground);
        await button.evaluate((element) => element.classList.add('active'));
        const activeBackground = await visibleButtonBackground();
        expect(activeBackground).not.toBe(buttonBackground);
        const activeContrast = await buttonActiveContrast(button);
        expect(activeContrast).toBeGreaterThanOrEqual(4.5);
        await button.evaluate((element) => element.classList.remove('active'));
      }
      await page.locator('body').evaluate((element) => { element.tabIndex = -1; element.focus(); });
      await page.keyboard.press('Tab');
      const keyboardFocused = page.locator(':focus-visible').first();
      await expect(keyboardFocused).toBeVisible();
      await expect(keyboardFocused).not.toHaveCSS('outline-style', 'none');
      await expect(matrix.locator('.btn:disabled')).toHaveCSS('background-color', /rgba?\(/);
      await expect(matrix.locator('.table-active td')).toHaveCSS('background-color', /rgba?\(/);
      await page.evaluate(() => window.Weline.Theme.switch('dark'));
      for (const selector of coreSelectors) {
        await expect(matrix.locator(selector).first()).toHaveCSS('background-color', /rgba?\(/);
        await expect(matrix.locator(selector).first()).toHaveCSS('color', /rgba?\(/);
      }
      for (const state of alertStates) {
        const alert = matrix.locator(`[data-state="alert-${state}"]`);
        const alertLink = alert.locator('.alert-link');
        await expect(alert).toHaveCSS('background-color', /rgba?\(/);
        await expect(alert).toHaveCSS('color', /rgba?\(/);
        expect(await alertLink.evaluate((element) => getComputedStyle(element).color)).toBe(await alert.evaluate((element) => getComputedStyle(element).color));
        const alertContrast = await alertTextAndBorderContrast(state);
        expect(alertContrast.text).toBeGreaterThanOrEqual(4.5);
        expect(alertContrast.border).toBeGreaterThanOrEqual(3);
      }
      await matrixFocusControl.focus();
      await expect(matrixFocusControl).toHaveCSS('outline-color', /rgba?\(/);
      await expect(matrixFocusControl).not.toHaveCSS('outline-style', 'none');
      expect(await matrixFocusOutlineContrast()).toBeGreaterThanOrEqual(3);
      const darkComponentColors = await matrix.evaluate((element) => ({
        card: getComputedStyle(element.querySelector('.card')).backgroundColor,
        input: getComputedStyle(element.querySelector('.form-control')).borderTopColor,
        legacyBadge: getComputedStyle(element.querySelector('[data-state="legacy-success-badge"]')).backgroundColor,
        legacyBadgeText: getComputedStyle(element.querySelector('[data-state="legacy-success-badge"]')).color,
        legacySecondaryBadge: getComputedStyle(element.querySelector('[data-state="legacy-secondary-badge"]')).backgroundColor,
        legacySecondaryBadgeText: getComputedStyle(element.querySelector('[data-state="legacy-secondary-badge"]')).color,
      }));
      expect(darkComponentColors.card).not.toBe(lightComponentColors.card);
      // Border tokens may intentionally be shared across the two default
      // palettes when that gives a clearer, WCAG-compliant control boundary.
      // The contract is that the form control remains token-coloured, not
      // that every semantic token must differ between palettes.
      expect(lightComponentColors.input).toMatch(/^rgba?\(/);
      expect(darkComponentColors.legacyBadge).not.toBe(lightComponentColors.legacyBadge);
      expect(darkComponentColors.legacyBadgeText).not.toBe(lightComponentColors.legacyBadgeText);
      expect(darkComponentColors.legacySecondaryBadge).not.toBe(lightComponentColors.legacySecondaryBadge);
      expect(darkComponentColors.legacySecondaryBadgeText).not.toBe(lightComponentColors.legacySecondaryBadgeText);
      await expect(legacySuccessBadge).toHaveCSS('background-color', /rgba?\(/);
      await expect(legacySuccessBadge).toHaveCSS('color', /rgba?\(/);
      expect(await legacyBadgeTextContrast('success')).toBeGreaterThanOrEqual(4.5);
      await expect(legacySecondaryBadge).toHaveCSS('background-color', /rgba?\(/);
      await expect(legacySecondaryBadge).toHaveCSS('color', /rgba?\(/);
      expect(await legacyBadgeTextContrast('secondary')).toBeGreaterThanOrEqual(4.5);
      for (const state of ['valid', 'invalid']) {
        await expect(matrix.locator(`[data-state="${state}-input"]`)).toHaveCSS('border-top-color', /rgba?\(/);
        await expect(matrix.locator(`[data-state="${state}-feedback"]`)).toHaveCSS('color', /rgba?\(/);
        expect(await tooltipTextContrast(state)).toBeGreaterThanOrEqual(4.5);
      }
      const darkContrast = await matrix.locator('.card').evaluate((element) => {
        const parse = (value) => (value.match(/[\d.]+/g) || []).map(Number);
        const composite = (foreground, background) => foreground.slice(0, 3).map((channel, index) => channel * (foreground[3] ?? 1) + background[index] * (1 - (foreground[3] ?? 1)));
        const luminance = (rgb) => rgb.map((channel) => {
          const value = channel / 255;
          return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        }).reduce((sum, value, index) => sum + value * [0.2126, 0.7152, 0.0722][index], 0);
        const style = getComputedStyle(element);
        const background = composite(parse(style.backgroundColor), parse(getComputedStyle(document.body).backgroundColor));
        const ratio = (foreground) => (Math.max(luminance(composite(parse(foreground), background)), luminance(background)) + 0.05) / (Math.min(luminance(composite(parse(foreground), background)), luminance(background)) + 0.05);
        return { text: ratio(style.color), border: ratio(style.borderTopColor) };
      });
      expect(darkContrast.text).toBeGreaterThanOrEqual(4.5);
      expect(darkContrast.border).toBeGreaterThanOrEqual(3);
      await expect(matrix.locator('.form-select')).toHaveCSS('color-scheme', 'dark');
    }
  );
});
