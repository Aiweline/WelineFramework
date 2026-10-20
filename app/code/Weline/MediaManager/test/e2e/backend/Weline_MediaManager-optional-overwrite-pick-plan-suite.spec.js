/**
 * Plan-suite: optional overwrite-target pick chapter.
 *
 * 独立注册整条链路用例（复用非 spec 的 flow 模块），**禁止** require 其它
 * `*.spec.js`：Playwright 会以 "should not import test file" fail-closed，
 * 而全量收集时一个坏文件会让所有过滤运行一起失败。
 *
 * @weline-e2e-spec { module: Weline_MediaManager, type: e2e-plan-suite, layer: backend }
 */
const {
  test,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const {
  MODULE,
  runOptionalOverwritePickFlow,
} = require('./Weline_MediaManager-optional-overwrite-pick.flow');

moduleDescribe(test, MODULE, 'Weline_MediaManager 可选覆盖目标计划链路', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-OVERWRITE-PICK-SUITE' },
    '计划链路e2e组：覆盖目标映射与 AI 指定覆盖入口',
    async ({ page }) => {
      await runOptionalOverwritePickFlow(page);
    },
  );
});
