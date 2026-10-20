/**
 * Optional overwrite-target pick UI (upload / AI save).
 *
 * @weline-e2e-spec { module: Weline_MediaManager, type: flow, layer: backend }
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

moduleDescribe(test, MODULE, 'Weline_MediaManager 可选覆盖目标', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'MEDIAMANAGER-OVERWRITE-PICK-001' },
    '管理器含可选覆盖目标映射与 AI 指定覆盖入口',
    async ({ page }) => {
      await runOptionalOverwritePickFlow(page);
    },
  );
});
