/**
 * Request lifecycle trace follows Weline panel open/close (no TTL lease).
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

function assertChapter1PanelGate() {
  const runtime = read('app/code/Weline/Framework/Runtime/RequestLifecycleTrace.php');
  const loader = read('app/code/Weline/DeveloperWorkspace/view/statics/js/dev-tool-panel-loader.js');
  return {
    ok: runtime.includes('isPanelTraceArmed')
      && runtime.includes('w_weline_trace_panel')
      && !runtime.includes('DEFAULT_PANEL_LEASE_TTL')
      && !runtime.includes('request_trace_lease_ttl')
      && !loader.includes('TRACE_LEASE_HEARTBEAT')
      && loader.includes('setTraceRecording')
      && loader.includes('trace/panel'),
    hasPanelGate: runtime.includes('isPanelTraceArmed'),
    hasCookie: runtime.includes('w_weline_trace_panel'),
    noTtl: !runtime.includes('DEFAULT_PANEL_LEASE_TTL'),
    switchWired: loader.includes('setTraceRecording') && loader.includes('trace/panel'),
  };
}

function assertChapter2OpenClose() {
  const traceApi = read('app/code/Weline/DeveloperWorkspace/Api/Rest/V1/Trace.php');
  const panel = read('app/code/Weline/DeveloperWorkspace/view/hooks/dev-tool-panel.phtml');
  const routes = read('app/code/Weline/DeveloperWorkspace/extends/module/Weline_Framework/Query/DeveloperWorkspaceAdminQueryProvider.php');
  const matcher = read('app/code/Weline/Framework/Http/PublicApiAuthRouteMatcher.php');
  return {
    ok: traceApi.includes('function postPanel')
      && traceApi.includes('issuePanelTraceCookie')
      && panel.includes('setTraceRecording(true)')
      && panel.includes('setTraceRecording(false)')
      && routes.includes("'trace/panel'")
      && !routes.includes("'trace/lease'")
      && matcher.includes('dev/tool/rest/v1/trace/panel'),
    hasPostPanel: traceApi.includes('function postPanel'),
    panelOpenClose: panel.includes('setTraceRecording(true)') && panel.includes('setTraceRecording(false)'),
    routeRegistered: routes.includes("'trace/panel'"),
    authAllowlisted: matcher.includes('dev/tool/rest/v1/trace/panel'),
  };
}

moduleDescribe(test, MODULE, '请求链路面板开闭', () => {
  test.setTimeout(60000);

  moduleCase(
    test,
    { module: MODULE, id: 'DW-TRACE-PANEL-CH1' },
    '通路：只认面板开闭门禁无TTL',
    async () => {
      const result = assertChapter1PanelGate();
      expect(result.ok, JSON.stringify(result)).toBe(true);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'DW-TRACE-PANEL-CH2' },
    '通路：打开开启关闭停止链路',
    async () => {
      const result = assertChapter2OpenClose();
      expect(result.ok, JSON.stringify(result)).toBe(true);
    },
  );
});
