#!/usr/bin/env node
/**
 * 主题视觉故障探针（主题开发技能 § 视觉故障定位 的标准第一步）。
 *
 * 目的：一次拿到「结构 + 计算样式 + 命中规则 + 截图」，用证据判定故障在 DOM 生成
 * 还是在布局，禁止靠读 CSS 文本猜测、禁止反复 curl 试探。
 *
 * 用法：
 *   node dev/tools/theme-visual-probe.mjs --url=<页面URL> [选项]
 *
 * 选项：
 *   --selector=<css>       目标元素（必填；如 .hanfu-pdp-trust-mini）
 *   --host-map=<host:port> 本地实例：把 URL 的 host 解析到 127.0.0.1:9555
 *   --width=<px>           视口宽（默认 1440）
 *   --height=<px>          视口高（默认 1600）
 *   --screenshot=<path>    截图输出路径（可选）
 *   --json                 只输出 JSON（默认带人类可读摘要）
 *
 * 判定速查：
 *   children 数与源码不符 / 出现嵌套           → DOM 生成坏了（内联脚本拼 HTML）
 *   结构正常但子项宽度 << 容器宽（逐词换行）   → 布局 min-content 地板塌陷
 *   matchedRules 里出现意外的 width/display     → 有规则覆盖，按来源修
 */
import { spawn } from 'node:child_process';
import { writeFileSync } from 'node:fs';

const CHROME = process.env.THEME_PROBE_CHROME
  || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

function arg(name, fallback = null) {
  const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : fallback;
}
const flag = (name) => process.argv.includes(`--${name}`);

const url = arg('url');
const selector = arg('selector');
if (!url || !selector) {
  console.error('usage: node dev/tools/theme-visual-probe.mjs --url=<url> --selector=<css> [--host-map=127.0.0.1:9555] [--screenshot=path]');
  process.exit(2);
}
const width = Number(arg('width', '1440'));
const height = Number(arg('height', '1600'));
const hostMap = arg('host-map');
const screenshotPath = arg('screenshot');
const port = Number(arg('port', '9400'));

const launchArgs = [
  '--headless=new',
  '--disable-gpu',
  '--hide-scrollbars',
  `--window-size=${width},${height}`,
  `--remote-debugging-port=${port}`,
  `--user-data-dir=/tmp/theme-probe-${Date.now()}`,
];
if (hostMap) {
  launchArgs.push(`--host-resolver-rules=MAP ${new URL(url).hostname} ${hostMap}`);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const chrome = spawn(CHROME, launchArgs, { stdio: 'ignore' });

async function wsUrl() {
  for (let i = 0; i < 80; i++) {
    try {
      const info = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
      if (info.webSocketDebuggerUrl) return info.webSocketDebuggerUrl;
    } catch { /* not ready */ }
    await sleep(250);
  }
  throw new Error('CDP not ready');
}

const ws = new WebSocket(await wsUrl());
let seq = 0;
const pending = new Map();
const styleSheets = new Map();
ws.onmessage = (e) => {
  const msg = JSON.parse(e.data);
  if (msg.method === 'CSS.styleSheetAdded' && msg.params?.header) {
    const h = msg.params.header;
    styleSheets.set(h.styleSheetId, { href: h.sourceURL || '(inline)', origin: h.origin });
  }
  if (msg.id && pending.has(msg.id)) {
    pending.get(msg.id)(msg);
    pending.delete(msg.id);
  }
};
await new Promise((r) => { ws.onopen = r; });

const send = (method, params = {}, sessionId) => new Promise((resolve) => {
  const id = ++seq;
  pending.set(id, resolve);
  ws.send(JSON.stringify({ id, method, params, sessionId }));
});

const target = await send('Target.createTarget', { url: 'about:blank' });
const sessionId = (await send('Target.attachToTarget', { targetId: target.result.targetId, flatten: true })).result.sessionId;
await send('Page.enable', {}, sessionId);
await send('DOM.enable', {}, sessionId);
await send('CSS.enable', {}, sessionId);
await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false }, sessionId);
await send('Page.navigate', { url }, sessionId);
await sleep(6500);

const expression = `
(() => {
  const sel = ${JSON.stringify(selector)};
  const el = document.querySelector(sel);
  if (!el) {
    return { found: false, url: location.href, bodyLen: document.body ? document.body.innerHTML.length : 0 };
  }
  const px = (n) => Math.round(n * 10) / 10;
  const box = el.getBoundingClientRect();
  const cs = getComputedStyle(el);
  const kids = [...el.children].map((c) => {
    const r = c.getBoundingClientRect();
    const s = getComputedStyle(c);
    return {
      tag: c.tagName.toLowerCase(),
      cls: (c.className || '').toString(),
      w: px(r.width),
      h: px(r.height),
      display: s.display,
      text: (c.textContent || '').trim().slice(0, 60),
    };
  });
  const ancestors = [];
  let n = el.parentElement;
  for (let i = 0; n && i < 5; i++, n = n.parentElement) {
    const s = getComputedStyle(n);
    ancestors.push({
      cls: (n.className || '').toString().slice(0, 60),
      display: s.display,
      w: px(n.getBoundingClientRect().width),
      gridCols: s.gridTemplateColumns,
    });
  }
  // 命中本元素的规则（含来源），只看几何相关声明，便于定位覆盖来源。
  const rules = [];
  const walk = (list, href) => {
    for (const r of list) {
      if (r.cssRules) { try { walk(r.cssRules, href); } catch {} continue; }
      if (!r.selectorText) continue;
      let hit = false;
      try { hit = el.matches(r.selectorText); } catch { continue; }
      if (!hit) continue;
      const decls = {};
      for (const p of ['display','grid-template-columns','grid-template-rows','width','min-width','max-width','flex','flex-direction','align-items','justify-items','padding','padding-inline-start','inline-size','min-inline-size','word-break','overflow-wrap','white-space','box-sizing']) {
        const v = r.style.getPropertyValue(p);
        if (v) decls[p] = v;
      }
      if (Object.keys(decls).length) rules.push({ href, sel: r.selectorText, decls });
    }
  };
  for (const sheet of document.styleSheets) {
    let list; try { list = sheet.cssRules; } catch { continue; }
    walk(list, sheet.href || '(inline)');
  }
  return {
    found: true,
    url: location.href,
    box: { w: px(box.width), h: px(box.height) },
    computed: {
      display: cs.display,
      gridTemplateColumns: cs.gridTemplateColumns,
      flexDirection: cs.flexDirection,
      alignItems: cs.alignItems,
      overflow: cs.overflow,
      wordBreak: cs.wordBreak,
    },
    childCount: el.children.length,
    children: kids,
    ancestors,
    // CSSOM 侧兜底（构造表/跨域表可能枚举不到；权威结果看 matchedRules = CDP 层叠）
    cssomMatchedRules: rules,
    htmlHead: el.outerHTML.slice(0, 600),
  };
})()
`;

const { result } = await send('Runtime.evaluate', { expression, returnByValue: true }, sessionId);
const data = result.result?.value ?? { error: 'evaluate failed' };

// 命中规则改用浏览器自身的层叠计算（CSS 域）：不依赖 JS 遍历 CSSOM，
// 构造样式表 / @import / 后注入样式都能覆盖。
if (data.found) {
  try {
    const docNode = await send('DOM.getDocument', { depth: -1 }, sessionId);
    const rootId = docNode.result?.root?.nodeId;
    const found = await send('DOM.querySelector', { nodeId: rootId, selector }, sessionId);
    const nodeId = found.result?.nodeId;
    if (nodeId) {
      const ms = await send('CSS.getMatchedStylesForNode', { nodeId }, sessionId);
      const geom = new Set(['display', 'grid-template-columns', 'grid-template-rows', 'width', 'min-width', 'max-width', 'flex', 'flex-direction', 'align-items', 'justify-items', 'padding', 'padding-inline-start', 'inline-size', 'min-inline-size', 'word-break', 'overflow-wrap', 'white-space', 'box-sizing']);
      const sheetHref = (id) => {
        const entry = styleSheets.get(id);
        return entry ? entry.href : (id ? `(sheet:${id})` : '(inline)');
      };
      data.matchedRules = (ms.result?.matchedCSSRules || []).map((m) => {
        const rule = m.rule || {};
        const decls = {};
        for (const p of rule.style?.cssProperties || []) {
          if (p.name && geom.has(p.name) && p.value) decls[p.name] = p.value;
        }
        return {
          href: sheetHref(rule.styleSheetId),
          sel: rule.selectorList?.text || '',
          origin: rule.origin || '',
          decls,
        };
      }).filter((r) => Object.keys(r.decls).length > 0);
      data.matchedRuleCount = (ms.result?.matchedCSSRules || []).length;
    }
  } catch (e) {
    data.matchedRulesError = String(e && e.message || e);
  }
}

if (screenshotPath) {
  const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true }, sessionId);
  if (shot.result?.data) {
    writeFileSync(screenshotPath, Buffer.from(shot.result.data, 'base64'));
    data.screenshot = screenshotPath;
  }
}

await send('Target.closeTarget', { targetId: target.result.targetId });
ws.close();
chrome.kill();

if (flag('json')) {
  console.log(JSON.stringify(data, null, 2));
} else {
  console.log(JSON.stringify(data, null, 2));
  if (data.found) {
    const narrow = data.children.filter((c) => c.w < data.box.w / 3);
    console.log('\n--- 摘要 ---');
    console.log(`容器 ${data.box.w}px / display=${data.computed.display} / cols=${data.computed.gridTemplateColumns}`);
    console.log(`子项 ${data.childCount} 个；宽度异常(<容器1/3)的子项：${narrow.length}`);
    if (narrow.length) console.log('  → 布局塌陷：检查 min-content 地板（minmax(0,1fr) / min-inline-size:0）');
    if (data.childCount === 0) console.log('  → 结构缺失：脚本未注入或 DOM 生成失败');
  } else {
    console.log('\n--- 摘要 ---：未找到目标元素（页面未注入 / 选择器错 / 被缓存为旧版本）');
  }
}
