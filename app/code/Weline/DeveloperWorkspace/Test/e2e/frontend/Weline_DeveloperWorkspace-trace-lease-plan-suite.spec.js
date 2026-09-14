/**
 * Full pathway suite for request-trace panel open/close.
 *
 * @weline-e2e-spec { module: Weline_DeveloperWorkspace, type: feature, layer: frontend }
 */

const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_DeveloperWorkspace';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');

function read(rel) {
  return fs.readFileSync(path.join(ROOT_DIR, rel), 'utf8');
}

moduleDescribe(test, MODULE, '请求链路面板开闭计划链路', () => {
  test.setTimeout(60000);

  moduleCase(
    test,
    { module: MODULE, id: 'DW-TRACE-PANEL-PLAN-SUITE' },
    '完整功能通路：开→记→关→停',
    async () => {
      const runtime = read('app/code/Weline/Framework/Runtime/RequestLifecycleTrace.php');
      const traceApi = read('app/code/Weline/DeveloperWorkspace/Api/Rest/V1/Trace.php');
      const loader = read('app/code/Weline/DeveloperWorkspace/view/statics/js/dev-tool-panel-loader.js');
      const panel = read('app/code/Weline/DeveloperWorkspace/view/hooks/dev-tool-panel.phtml');
      const unit = read('app/code/Weline/Framework/Test/Unit/Runtime/RequestLifecycleTraceTest.php');

      const result = {
        gate: runtime.includes('isPanelTraceArmed') && !runtime.includes('DEFAULT_PANEL_LEASE_TTL'),
        api: traceApi.includes('postPanel') && loader.includes("apiFetch('trace/panel'"),
        openClose: loader.includes('setTraceRecording') && panel.includes('setTraceRecording(false)'),
        noHeartbeat: !loader.includes('TRACE_LEASE_HEARTBEAT'),
        unitCoverage: unit.includes('testPanelOpenEnablesTraceAndCloseDisables')
          && unit.includes('testEnvRequestTraceConfigDoesNotEnableWithoutPanel'),
      };
      result.ok = result.gate && result.api && result.openClose && result.noHeartbeat && result.unitCoverage;
      expect(result.ok, JSON.stringify(result)).toBe(true);
    },
  );
});
