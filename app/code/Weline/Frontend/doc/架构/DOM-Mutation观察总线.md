# DOM Mutation 观察总线（架构）

## 问题

密级 hydrate（部件/画布/验证码）下，多个脚本各自对 `document` / `documentElement` / `body` 创建 `MutationObserver`，并在回调里改 DOM 后立刻 `observe`，会把同观察者投递打到 DEV `delivery_storm`（约 250ms >40 次），表现为 JS 反馈死循环 / 卡死。DEV 截断只是护栏，不是架构解法。

## 架构决策（参考 Vue scheduler / maxUpdateDepth）

| 决策 | 内容 |
|------|------|
| 唯一入口 | 全页级观察必须走 **`Weline.dom.observe`**（`Weline.dom.observeMutations` 同义） |
| 共享总线 | 同一 `target` + `options` 指纹 + 安静窗毫秒数 → **一个物理** `MutationObserver`，多订阅者扇出 `onRecords` / `onFlush` |
| 合并语义 | 首次投递 `disconnect` → **`setTimeout(quietMs)` 安静窗** → 可选 `requestIdleCallback` → flush（含 **`takeRecords` 抽干**）→ 再 `observe` |
| 反馈环保险丝 | **`MAX_FLUSH_DEPTH=50`**（同栈嵌套，对齐 Vue maxUpdateDepth 思路）；**`MAX_FLUSHES_PER_WINDOW=40` / 250ms**（短窗链式 flush）；触发则停观察并 `console.error` + `weline:dom:mutation-loop` |
| 禁止 | 业务/Theme/Captcha 对 document 根 **裸 `new MutationObserver`**（仅允许 `/* ARCH_MO_FALLBACK_* */` 无总线时的降级）；双 rAF / `queueMicrotask` / **仅** `requestIdleCallback({ timeout })` 立刻 re-observe |
| 元素级 | 非 document 根默认可私有通道（`observeMutationsCoalesced`）；`share:false` 可强制私有 |
| 订阅 pause | `pause`/`withPaused` 为**订阅级**（跳过本订阅回调），不拆共享观察者 |

实现位置：`Weline_Frontend` → `view/statics/js/weline.js`（`observeDomMutations` + `domMutationChannels` + coalesce 保险丝）。

底层原语 `Weline.observeMutationsCoalesced` 仍保留给非共享场景；**新代码优先 `Weline.dom.observe`**。

## 用法

```js
const sub = Weline.dom.observe({
  target: document.documentElement,
  options: { childList: true, subtree: true },
  idleTimeoutMs: 100,
  label: 'my-feature',
  onRecords(records) { /* 收集节点 */ },
  onFlush() { /* 幂等 mount / scan */ },
});
// 自身写 DOM 时：
sub.withPaused(() => { /* mutate */ });
// 卸载：
sub.disconnect();
```

## 与 DEV 看门狗

`installDevMutationObserverGuard` 仍检测同步重入与短窗风暴并截断。架构正确时不应触发。总线保险丝先停共享通道；DEV 看门狗兜底裸观察者。**禁止放宽阈值当修法**。

## 门禁

- MCP 硬规则：`dom_mutation_observe_via_weline_dom`
- 契约：`WelineDomMutationBusContractTest`（含 Vue 式 depth/`takeRecords`、一作方禁止 document 裸 MO）
- 规范交叉：[前端JS模块加载规范.md](../../Theme/doc/前端JS模块加载规范.md) §1.2

## 后续里程碑

元素级/属性级裸观察者（浮层 visibility、主题编辑器局部）继续审计；第三方 libs（vue/simplebar）白名单除外。
