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

function assertChapter3TplPerfOverlay() {
  const runtime = read('app/code/Weline/Framework/Runtime/RequestLifecycleTrace.php');
  const traceApi = read('app/code/Weline/DeveloperWorkspace/Api/Rest/V1/Trace.php');
  const loader = read('app/code/Weline/DeveloperWorkspace/view/statics/js/dev-tool-panel-loader.js');
  const panel = read('app/code/Weline/DeveloperWorkspace/view/hooks/dev-tool-panel.phtml');
  const routes = read('app/code/Weline/DeveloperWorkspace/extends/module/Weline_Framework/Query/DeveloperWorkspaceAdminQueryProvider.php');
  const matcher = read('app/code/Weline/Framework/Http/PublicApiAuthRouteMatcher.php');
  const unit = read('app/code/Weline/Framework/Test/Unit/Runtime/RequestLifecycleTraceTest.php');
  return {
    ok: runtime.includes('w_weline_tpl_perf')
      && runtime.includes('isPanelTplPerfArmed')
      && runtime.includes('issuePanelTplPerfCookie')
      && traceApi.includes('function postTplPerf')
      && routes.includes("'trace/tpl-perf'")
      && matcher.includes('dev/tool/rest/v1/trace/tpl-perf')
      && loader.includes('setTplPerfOverlay')
      && loader.includes("apiFetch('trace/tpl-perf'")
      && loader.includes('wls_tpl_perf')
      && panel.includes('toggle-tpl-perf')
      && panel.includes('模板耗时徽标')
      && unit.includes('testPanelTplPerfCookieArmsTemplateOverlayWithoutQuery'),
    cookie: runtime.includes('w_weline_tpl_perf'),
    api: traceApi.includes('function postTplPerf'),
    loader: loader.includes('setTplPerfOverlay'),
    ui: panel.includes('toggle-tpl-perf'),
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

  moduleCase(
    test,
    { module: MODULE, id: 'DW-TRACE-PANEL-CH3' },
    '通路：模板耗时徽标开关经面板token武装',
    async () => {
      const result = assertChapter3TplPerfOverlay();
      expect(result.ok, JSON.stringify(result)).toBe(true);
    },
  );
});
