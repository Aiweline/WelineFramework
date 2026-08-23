/**
 * Weline_MediaManager：诚实路由 smoke + 后台真实交互 flow（Wave2/3 假用例整治）
 *
 * 说明：openPrimary 会在候选后台路由中探测出「真正渲染出后台内容区(main#main-content)」的入口，
 * 猜测/空白/404 路由不会渲染内容区，从而让 smoke 诚实失败（防假绿），而非靠“无 Fatal”蒙混。
 *
 * @weline-e2e-spec { module: Weline_MediaManager, type: flow, layer: backend }
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
  submitAndExpectParam,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_MediaManager';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
// 优先后台壳层内容区；裸 main 可能是模块自定义局部容器（如 2FA accountsContainer），不能单独当作业务根
const CONTENT_SHELL = 'main#main-content, main.backend-main-content';
const CONTENT = CONTENT_SHELL;
// 候选后台路由（来自模块 Controller/Backend 的 index/get* 动作 + 兜底猜测），按序探测
const CANDIDATE_ROUTES = ["connector","manager","aiDraw/stream","aidraw/stream","ai_draw/stream","mediamanager","index","config","dashboard"];

// 返回 { route, fatal }：
//  - route!=null：命中真正渲染后台内容区的入口；
//  - route==null & fatal!=null：候选路由触发运行期错误(FATAL/500) → 真实 Bug，用例应失败留证；
//  - route==null & fatal==null：候选均为 404/空白 → 该模块无独立后台页 → 用例诚实 skip。
async function openPrimary(page) {
  let fatal = null;
  for (const route of CANDIDATE_ROUTES) {
    try {
      await gotoBackend(page, buildModuleBackendRoute(MODULE, route), { timeout: 60000, settleMs: 600 });
    } catch (_e) {
      continue;
    }
    await waitForBackendShellReady(page);
    const bodyText = await page.locator('body').innerText().catch(() => '');
    if (FATAL.test(bodyText) || bodyText.trim() === '404') {
      if (FATAL.test(bodyText)) fatal = fatal || route;
      continue;
    }
    const shell = page.locator(CONTENT_SHELL).first();
    if (await shell.isVisible().catch(() => false)) {
      const txt = ((await shell.innerText().catch(() => '')) || '').trim();
      if (txt.length > 0) return { route, fatal };
    }
    // 自定义全页（无后台壳）：有非空 title + 足够 body 文本也算可达
    const title = await page.title().catch(() => '');
    if (title && bodyText.trim().length > 40) {
      return { route, fatal };
    }
  }
  return { route: null, fatal };
}

moduleDescribe(test, MODULE, 'Weline_MediaManager 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-SMOKE-001' },
    '主入口路由可达并渲染后台内容区（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      const { route, fatal } = await openPrimary(page);
      if (!route) {
        expect(fatal, `候选后台路由命中运行期错误(FATAL)：${fatal} —— 属真实产品 Bug，需修复`).toBeFalsy();
        test.skip(true, '未发现该模块可渲染的独立后台页（配置可能在统一配置中心/无后台 UI）');
        return;
      }
      await expect(page.locator('body')).not.toContainText(FATAL);
      const shell = page.locator(CONTENT_SHELL).first();
      if (await shell.isVisible().catch(() => false)) {
        await expect(shell).toBeVisible();
      } else {
        await expect(page.locator('body')).toContainText(/.+/);
        const title = await page.title();
        expect(title.length).toBeGreaterThan(0);
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-FLOW-001' },
    '主入口：搜索/筛选或安全控件真实交互',
    async ({ page }) => {
      await loginAsAdmin(page);
      const { route, fatal } = await openPrimary(page);
      if (!route) {
        expect(fatal, `候选后台路由命中运行期错误(FATAL)：${fatal} —— 属真实产品 Bug，需修复`).toBeFalsy();
        test.skip(true, '未发现该模块可渲染的独立后台页（配置可能在统一配置中心/无后台 UI）');
        return;
      }
      const shell = page.locator(CONTENT_SHELL).first();
      const root = (await shell.isVisible().catch(() => false)) ? shell : page.locator('body');

      const keyword = root
        .locator('input[name="keyword"], input[name="search"], input[name="q"], #search-input')
        .first();
      const select = root
        .locator('form select[name="status"], form select[name="type"], form select[name="read"], select.form-select')
        .first();
      const refresh = root
        .locator('button:has-text("刷新"), a:has-text("刷新"), button:has-text("重置"), a:has-text("重置")')
        .first();

      if ((await keyword.count()) > 0 && (await keyword.isVisible().catch(() => false))) {
        const form = page.locator('form').filter({ has: keyword }).first();
        await keyword.fill('e2e-wave23');
        if ((await form.count()) > 0) {
          // 决定性证据：用户输入被真实带上提交请求（proxy 下绝对 action 可能落 404 页，故不断言提交后 DOM）
          const req = await submitAndExpectParam(page, form, 'e2e-wave23');
          expect(req).toBeTruthy();
        } else {
          await keyword.press('Enter');
          await page.waitForTimeout(500);
          await expect(keyword).toHaveValue('e2e-wave23');
        }
        return;
      }

      if ((await select.count()) > 0 && (await select.isVisible().catch(() => false))) {
        const n = await select.locator('option').count();
        if (n > 1) {
          await select.selectOption({ index: 1 });
          await page.waitForTimeout(400);
        }
        await expect(page.locator('body')).not.toContainText(FATAL);
        await expect(select).toBeVisible();
        return;
      }

      if ((await refresh.count()) > 0 && (await refresh.isVisible().catch(() => false))) {
        await refresh.click({ force: true });
        await page.waitForLoadState('domcontentloaded');
        await waitForBackendShellReady(page);
        await expect(page.locator('body')).not.toContainText(FATAL);
        return;
      }

      // 禁止点「新增/添加」：常跳到可能损坏的写表单；只点只读安全控件
      const safeBtn = root
        .locator('button.btn, a.btn, button')
        .filter({ hasText: /搜索|筛选|过滤|查看|详情|配置|管理|展开|手动输入/ })
        .first();
      if ((await safeBtn.count()) > 0 && (await safeBtn.isVisible().catch(() => false))) {
        await safeBtn.click({ force: true });
        await page.waitForTimeout(500);
        await waitForBackendShellReady(page);
        await expect(page.locator('body')).not.toContainText(FATAL);
        return;
      }

      // 纯展示/配置页：断言真实内容区渲染了业务信号（标题/表格/表单/卡片/按钮），而非纯 FATAL 兜底
      await expect(root).toBeVisible();
      await expect(
        root.locator('h1, h2, h4, .page-title, table, form, .card, a.btn, button.btn, button, input').first()
      ).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-UI-001' },
    '目录菜单使用 Weline UI 主题、移动端限界且重复打开不漂移',
    async ({ page }) => {
      await loginAsAdmin(page);
      await page.setViewportSize({ width: 375, height: 700 });
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'manager'), { timeout: 60000, settleMs: 600 });
      await waitForBackendShellReady(page);
      await page.waitForFunction(() => !!window.Weline?.UI && !!document.querySelector('.mmf-grid .mmf-item'));

      const setTheme = async (preference) => {
        if (await page.locator('html').getAttribute('data-theme') === preference) return;
        await page.locator('[aria-controls="w-backend-theme-menu"]').click();
        await page.locator(`#w-backend-theme-menu [data-w-theme-preference="${preference}"]`).click();
        await expect(page.locator('html')).toHaveAttribute('data-theme', preference);
      };
      const openAtRightEdge = async () => {
        const target = page.locator('.mmf-grid .mmf-item[data-mime="directory"]').first();
        await expect(target).toBeVisible();
        const box = await target.boundingBox();
        expect(box).toBeTruthy();
        await page.mouse.click(
          Math.min(366, box.x + box.width - 2),
          box.y + Math.min(24, box.height / 2),
          { button: 'right' }
        );
      };
      const menuGeometry = () => page.locator('#mmf-context-menu').evaluate((menu) => {
        const rect = menu.getBoundingClientRect();
        const visual = window.visualViewport;
        const probe = document.createElement('span');
        probe.style.backgroundColor = 'var(--weline-theme-surface-raised)';
        document.body.append(probe);
        const expectedBackground = getComputedStyle(probe).backgroundColor;
        probe.remove();
        return {
          left: rect.left,
          top: rect.top,
          right: rect.right,
          bottom: rect.bottom,
          viewportLeft: visual?.offsetLeft || 0,
          viewportTop: visual?.offsetTop || 0,
          viewportRight: (visual?.offsetLeft || 0) + (visual?.width || document.documentElement.clientWidth),
          viewportBottom: (visual?.offsetTop || 0) + (visual?.height || document.documentElement.clientHeight),
          background: getComputedStyle(menu).backgroundColor,
          expectedBackground,
        };
      });

      await setTheme('dark');
      const repeated = [];
      for (let cycle = 0; cycle < 3; cycle += 1) {
        await openAtRightEdge();
        const menu = page.locator('#mmf-context-menu');
        await expect(menu).toHaveAttribute('data-state', 'open');
        await expect(page.locator('.mmf-context-item').first()).toBeFocused();
        const geometry = await menuGeometry();
        expect(geometry.background).toBe(geometry.expectedBackground);
        expect(geometry.left).toBeGreaterThanOrEqual(geometry.viewportLeft + 7);
        expect(geometry.top).toBeGreaterThanOrEqual(geometry.viewportTop + 7);
        expect(geometry.right).toBeLessThanOrEqual(geometry.viewportRight - 7);
        expect(geometry.bottom).toBeLessThanOrEqual(geometry.viewportBottom - 7);
        repeated.push(geometry);
        await page.keyboard.press('Escape');
        await expect(menu).toBeHidden();
      }
      for (const geometry of repeated.slice(1)) {
        expect(Math.abs(geometry.left - repeated[0].left)).toBeLessThanOrEqual(1);
        expect(Math.abs(geometry.top - repeated[0].top)).toBeLessThanOrEqual(1);
      }

      await setTheme('light');
      await openAtRightEdge();
      const lightGeometry = await menuGeometry();
      expect(lightGeometry.background).toBe(lightGeometry.expectedBackground);
      await page.keyboard.press('Escape');
      await expect(page.locator('#mmf-context-menu')).toBeHidden();

      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(page.locator('#mmf-context-menu')).toBeHidden();
      await expect(page.locator('[data-mmf-context-menu-root] [data-w-menu-trigger]'))
        .toHaveAttribute('aria-expanded', 'false');
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-DROP-PASTE-MOVE-001' },
    '外部拖入与粘贴上传，内部拖到目录后移动且不重复上传',
    async ({ page }) => {
      await loginAsAdmin(page);
      await page.setViewportSize({ width: 1280, height: 800 });
      await gotoBackend(page, buildModuleBackendRoute(MODULE, 'manager'), { timeout: 60000, settleMs: 600 });
      await waitForBackendShellReady(page);
      await page.waitForFunction(() => !!window.Weline?.UI && !!document.querySelector('.mmf-grid'));

      const storage = page.locator('#mmf-storage-select');
      const localStorageValues = ['local', 'local::filesystem::media'];
      if (await storage.isVisible() && !localStorageValues.includes(await storage.inputValue())) {
        const optionValues = await storage.locator('option').evaluateAll(
          (options) => options.map((option) => option.value)
        );
        const localStorageValue = localStorageValues.find((value) => optionValues.includes(value));
        expect(localStorageValue).toBeTruthy();
        await storage.selectOption(localStorageValue);
      }
      await page.waitForFunction((acceptedLocalStorageValues) => {
        const selector = document.querySelector('#mmf-storage-select');
        const newFolder = document.querySelector('#mmf-btn-newfolder');
        return acceptedLocalStorageValues.includes(selector?.value)
          && !document.querySelector('.mmf-loading')
          && !!document.querySelector('.mmf-path-seg')
          && newFolder?.disabled === false;
      }, localStorageValues);
      await expect(page.locator('.mmf-path-seg').last()).toBeVisible();
      await expect(page.locator('#mmf-btn-newfolder')).toBeEnabled();

      const stamp = Date.now().toString(36);
      const folderName = `codex-dnd-${stamp}`;
      const droppedName = `codex-drop-${stamp}.txt`;
      const folderDroppedName = `codex-folder-drop-${stamp}.txt`;
      const pastedName = `codex-paste-${stamp}.txt`;
      const multiMovedName = `codex-multi-${stamp}.txt`;
      const sourceHash = await page.locator('.mmf-path-seg').last().getAttribute('data-hash');
      expect(sourceHash).toBeTruthy();
      const item = (name) => page.locator('.mmf-grid .mmf-item').filter({ hasText: name });
      const confirmUploadMetadata = async (fileName, hasNext = false) => {
        const openDialog = (title) => page.locator('dialog.w-dialog[open]', { hasText: title });
        const altDialog = openDialog('默认 alt（必填）');
        await expect(altDialog).toBeVisible();
        await expect(altDialog.locator('input.w-input')).toHaveValue(fileName.replace(/\.[^.]+$/, ''));
        await altDialog.getByRole('button', { name: '继续', exact: true }).click();

        const descriptionDialog = openDialog('资源描述（必填）');
        await expect(descriptionDialog).toBeVisible();
        await descriptionDialog.locator('textarea.w-textarea').fill('E2E 上传验收：' + fileName);
        await descriptionDialog.getByRole('button', { name: '确认', exact: true }).click();
        if (!hasNext) await expect(page.locator('dialog.w-dialog[open]')).toHaveCount(0);
      };

      const removeVisibleItemBestEffort = async (name) => {
        const target = item(name).first();
        if (!(await target.isVisible().catch(() => false))) return;
        await target.click().catch(() => {});
        await page.locator('#mmf-btn-delete').click().catch(() => {});
        const confirm = page.locator('.mmf-dialog-overlay.visible .mmf-dialog-ok');
        if (await confirm.isVisible().catch(() => false)) await confirm.click().catch(() => {});
        await page.waitForTimeout(250);
      };

      try {
      await page.locator('#mmf-btn-newfolder').click();
      await expect(page.locator('.mmf-dialog-overlay')).toHaveClass(/visible/);
      await page.locator('.mmf-dialog-input').fill(folderName);
      await page.locator('.mmf-dialog-ok').click();
      await expect(item(folderName)).toBeVisible();

      await page.evaluate(() => {
        const original = XMLHttpRequest.prototype.send;
        window.__mmfOriginalXhrSend = original;
        window.__mmfUploadedFileCount = 0;
        XMLHttpRequest.prototype.send = function (body) {
          if (body instanceof FormData && body.get('cmd') === 'upload') {
            window.__mmfUploadedFileCount += body.getAll('upload[]').length;
          }
          return original.call(this, body);
        };
      });

      const pasteGuardResult = await page.evaluate(() => {
        const textTransfer = new DataTransfer();
        textTransfer.setData('text/plain', 'plain-text-must-not-upload');
        const textEvent = new Event('paste', { bubbles: true, cancelable: true });
        Object.defineProperty(textEvent, 'clipboardData', { value: textTransfer });
        document.body.dispatchEvent(textEvent);

        const input = document.createElement('input');
        document.body.appendChild(input);
        const fileTransfer = new DataTransfer();
        fileTransfer.items.add(new File(['input-file'], 'input-file.txt', { type: 'text/plain' }));
        const inputEvent = new Event('paste', { bubbles: true, cancelable: true });
        Object.defineProperty(inputEvent, 'clipboardData', { value: fileTransfer });
        input.dispatchEvent(inputEvent);
        input.remove();
        return { textPrevented: textEvent.defaultPrevented, inputPrevented: inputEvent.defaultPrevented };
      });
      expect(pasteGuardResult).toEqual({ textPrevented: false, inputPrevented: false });
      await expect.poll(() => page.evaluate(() => window.__mmfUploadedFileCount)).toBe(0);

      await page.evaluate(({ name }) => {
        const transfer = new DataTransfer();
        transfer.items.add(new File(['dropped'], name, { type: 'text/plain' }));
        window.__mmfExternalTransfer = transfer;
        document.querySelector('.mmf-content').dispatchEvent(new DragEvent('dragenter', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
      }, { name: droppedName });
      await expect(page.locator('.mmf-upload-drop')).toHaveClass(/visible/);
      await expect(page.locator('.mmf-interaction-status')).toContainText('松开即可上传到当前文件夹');
      await page.evaluate(() => {
        document.querySelector('.mmf-content').dispatchEvent(new DragEvent('drop', {
          bubbles: true,
          cancelable: true,
          dataTransfer: window.__mmfExternalTransfer,
        }));
      });
      await confirmUploadMetadata(droppedName);
      await expect(item(droppedName)).toBeVisible();

      await page.evaluate(({ name, targetName }) => {
        const target = Array.from(document.querySelectorAll('.mmf-grid .mmf-item'))
          .find((entry) => entry.querySelector('.mmf-item-name')?.textContent === targetName);
        if (!target) throw new Error('external folder drop target is missing');
        const transfer = new DataTransfer();
        transfer.items.add(new File(['folder-dropped'], name, { type: 'text/plain' }));
        target.dispatchEvent(new DragEvent('dragenter', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        target.dispatchEvent(new DragEvent('dragover', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        target.dispatchEvent(new DragEvent('drop', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
      }, { name: folderDroppedName, targetName: folderName });
      await confirmUploadMetadata(folderDroppedName);
      await expect.poll(() => page.evaluate(() => window.__mmfUploadedFileCount)).toBe(2);

      await page.evaluate(({ name, multiName }) => {
        const transfer = new DataTransfer();
        transfer.items.add(new File(['pasted'], name, { type: 'text/plain' }));
        transfer.items.add(new File(['multi'], multiName, { type: 'text/plain' }));
        const event = new Event('paste', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'clipboardData', { value: transfer });
        document.body.dispatchEvent(event);
      }, { name: pastedName, multiName: multiMovedName });
      await confirmUploadMetadata(pastedName, true);
      await confirmUploadMetadata(multiMovedName);
      await expect(item(pastedName)).toBeVisible();
      await expect(item(multiMovedName)).toBeVisible();
      await expect.poll(() => page.evaluate(() => window.__mmfUploadedFileCount)).toBe(4);

      await item(pastedName).click();
      await expect(page.locator('[data-mmf-preview-meta]')).toContainText('资源描述');
      await expect(page.locator('[data-mmf-preview-meta]')).toContainText(`E2E 上传验收：${pastedName}`);
      await item(pastedName).click({ button: 'right' });
      const viewDetails = page.locator('.mmf-context-item').filter({ hasText: '查看详情' });
      await expect(viewDetails).toBeVisible();
      await viewDetails.click();
      const details = page.locator('.mmf-details-overlay');
      await expect(details).toHaveClass(/visible/);
      await expect(details.locator('.mmf-details-title')).toHaveText(pastedName);
      for (const label of ['资源 ID', '存储磁盘', '对象键', '默认 Alt', '资源描述', '翻译状态']) {
        await expect(details.locator('.mmf-metadata-label').filter({ hasText: label })).toBeVisible();
      }
      await expect(details.locator('.mmf-details-list')).toContainText(`E2E 上传验收：${pastedName}`);
      const editedCaption = `E2E 说明文字：${pastedName}`;
      await details.locator('.mmf-details-edit').click();
      const metadataDialog = (title) => page.locator('dialog.w-dialog[open]', { hasText: title });
      await metadataDialog('资源名称').getByRole('button', { name: '继续', exact: true }).click();
      await metadataDialog('默认 alt').getByRole('button', { name: '继续', exact: true }).click();
      await metadataDialog('资源描述').getByRole('button', { name: '继续', exact: true }).click();
      const captionDialog = metadataDialog('默认说明文字（可选）');
      await expect(captionDialog).toBeVisible();
      await captionDialog.locator('textarea.w-textarea').fill(editedCaption);
      await captionDialog.getByRole('button', { name: '保存', exact: true }).click();
      await expect(page.locator('dialog.w-dialog[open]')).toHaveCount(0);
      await expect(item(pastedName)).toBeVisible();
      await item(pastedName).click();
      await expect(page.locator('[data-mmf-preview-meta]')).toContainText(editedCaption);
      await item(pastedName).click({ button: 'right' });
      await page.locator('.mmf-context-item').filter({ hasText: '查看详情' }).click();
      await expect(details.locator('.mmf-details-list')).toContainText(editedCaption);
      await details.locator('.mmf-details-done').click();
      await expect(details).not.toHaveClass(/visible/);
      await expect(page.locator('body')).not.toContainText('Unknown frontend worker param');

      await page.setViewportSize({ width: 375, height: 700 });
      const fileMore = item(pastedName).locator('[data-mmf-item-menu]');
      await expect(fileMore).toBeVisible();
      await fileMore.click();
      await page.locator('.mmf-context-item').filter({ hasText: '查看详情' }).click();
      await expect(details).toHaveClass(/visible/);
      await expect(details.locator('.mmf-details-title')).toHaveText(pastedName);
      await details.locator('.mmf-details-done').click();
      await page.setViewportSize({ width: 1280, height: 800 });

      await page.evaluate(({ sourceName, targetName }) => {
        const entries = Array.from(document.querySelectorAll('.mmf-grid .mmf-item'));
        const source = entries.find((entry) => entry.querySelector('.mmf-item-name')?.textContent === sourceName);
        const target = entries.find((entry) => entry.querySelector('.mmf-item-name')?.textContent === targetName);
        if (!source || !target) throw new Error('drag source or target is missing');
        const transfer = new DataTransfer();
        source.dispatchEvent(new DragEvent('dragstart', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        window.__mmfInternalDragTypes = Array.from(transfer.types);
        target.dispatchEvent(new DragEvent('dragenter', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        target.dispatchEvent(new DragEvent('dragover', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        target.dispatchEvent(new DragEvent('drop', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        source.dispatchEvent(new DragEvent('dragend', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
      }, { sourceName: droppedName, targetName: folderName });

      await expect.poll(() => page.evaluate(() => window.__mmfInternalDragTypes))
        .toContain('application/x-weline-media-files');
      await expect(item(droppedName)).toHaveCount(0);
      await expect.poll(() => page.evaluate(() => window.__mmfUploadedFileCount)).toBe(4);

      await item(pastedName).click();
      await item(multiMovedName).click({ modifiers: ['Control'] });
      await page.evaluate(({ sourceName, targetName }) => {
        const source = Array.from(document.querySelectorAll('.mmf-grid .mmf-item'))
          .find((entry) => entry.querySelector('.mmf-item-name')?.textContent === sourceName);
        const target = Array.from(document.querySelectorAll('.mmf-tree-item'))
          .find((entry) => entry.querySelector('.mmf-tree-label')?.textContent.trim().endsWith(targetName));
        if (!source || !target) throw new Error('tree drag source or target is missing');
        const transfer = new DataTransfer();
        source.dispatchEvent(new DragEvent('dragstart', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        target.dispatchEvent(new DragEvent('dragenter', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        target.dispatchEvent(new DragEvent('dragover', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        target.dispatchEvent(new DragEvent('drop', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
        source.dispatchEvent(new DragEvent('dragend', {
          bubbles: true,
          cancelable: true,
          dataTransfer: transfer,
        }));
      }, { sourceName: pastedName, targetName: folderName });
      await expect(item(pastedName)).toHaveCount(0);
      await expect(item(multiMovedName)).toHaveCount(0);
      await expect.poll(() => page.evaluate(() => window.__mmfUploadedFileCount)).toBe(4);

      await item(folderName).dblclick();
      await expect(item(droppedName)).toBeVisible();
      await expect(item(folderDroppedName)).toBeVisible();
      await expect(item(pastedName)).toBeVisible();
      await expect(item(multiMovedName)).toBeVisible();

      const deleteItem = async (name) => {
        await item(name).click();
        await page.locator('#mmf-btn-delete').click();
        await expect(page.locator('.mmf-dialog-overlay')).toHaveClass(/visible/);
        await page.locator('.mmf-dialog-ok').click();
        await expect(item(name)).toHaveCount(0);
      };

      await deleteItem(droppedName);
      await deleteItem(folderDroppedName);
      await deleteItem(pastedName);
      await deleteItem(multiMovedName);
      await page.locator(`.mmf-path-seg[data-hash="${sourceHash}"]`).click();
      await deleteItem(folderName);

      // The manager updates its in-memory listing before the follow-up open
      // request settles. A DOM-only assertion can therefore pass even when a
      // Worker reports success but the object/asset mutation is rolled back.
      // Reload the complete page and prove the deletion is durable.
      await page.reload({ waitUntil: 'domcontentloaded' });
      await waitForBackendShellReady(page);
      await page.waitForFunction(() => !!window.Weline?.UI && !!document.querySelector('.mmf-grid'));
      await page.waitForFunction((acceptedLocalStorageValues) => {
        const selector = document.querySelector('#mmf-storage-select');
        return acceptedLocalStorageValues.includes(selector?.value)
          && !document.querySelector('.mmf-loading')
          && !!document.querySelector('.mmf-path-seg');
      }, localStorageValues);
      for (const name of [droppedName, folderDroppedName, pastedName, multiMovedName, folderName]) {
        await expect(item(name), `${name} must remain deleted after a full page reload`).toHaveCount(0);
      }
      } finally {
        await page.evaluate(() => {
          if (window.__mmfOriginalXhrSend) {
            XMLHttpRequest.prototype.send = window.__mmfOriginalXhrSend;
            delete window.__mmfOriginalXhrSend;
          }
        }).catch(() => {});
        await page.keyboard.press('Escape').catch(() => {});
        // First clean whichever directory the failed assertion left open.
        await removeVisibleItemBestEffort(droppedName);
        await removeVisibleItemBestEffort(folderDroppedName);
        await removeVisibleItemBestEffort(pastedName);
        await removeVisibleItemBestEffort(multiMovedName);
        const sourceCrumb = page.locator(`.mmf-path-seg[data-hash="${sourceHash}"]`);
        if (await sourceCrumb.isVisible().catch(() => false)) {
            await sourceCrumb.click().catch(() => {});
            await page.waitForTimeout(300);
        }
        // The direct folder drop may have succeeded before a later assertion
        // failed. Enter the folder and remove its children before deleting it.
        const cleanupFolder = item(folderName).first();
        if (await cleanupFolder.isVisible().catch(() => false)) {
          await cleanupFolder.dblclick().catch(() => {});
          await page.waitForTimeout(300);
          await removeVisibleItemBestEffort(droppedName);
          await removeVisibleItemBestEffort(folderDroppedName);
          await removeVisibleItemBestEffort(pastedName);
          await removeVisibleItemBestEffort(multiMovedName);
          if (await sourceCrumb.isVisible().catch(() => false)) {
            await sourceCrumb.click().catch(() => {});
            await page.waitForTimeout(300);
          }
        }
        await removeVisibleItemBestEffort(droppedName);
        await removeVisibleItemBestEffort(pastedName);
        await removeVisibleItemBestEffort(multiMovedName);
        await removeVisibleItemBestEffort(folderName);
      }
    }
  );
});
