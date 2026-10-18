# DOM Mutation 观察总线（架构）

## 问题

密级 hydrate（部件/画布/验证码）下，多个脚本各自对 `document` / `documentElement` / `body` 创建 `MutationObserver`，并在回调里改 DOM 后立刻 `observe`，会把同观察者投递打到 DEV `delivery_storm`（约 250ms >40 次），表现为 JS 反馈死循环 / 卡死。DEV 截断只是护栏，不是架构解法。仅靠静态名单迁脚本也会漏扫。

## 架构决策（参考 Vue scheduler / maxUpdateDepth）

| 决策 | 内容 |
|------|------|
| 唯一入口 | 全页级观察应走 **`Weline.dom.observe`**（`Weline.dom.observeMutations` 同义） |
| **Document Observe Gate（强制入线）** | 包装 `MutationObserver` **构造函数**（捕获 callback）+ `observe`：凡 `target ∈ {document, documentElement, body}` → **自动并入共享总线**，不挂私有全页物理观察者 |
| 共享总线 | 同一 `target` + `options` 指纹 + 安静窗毫秒数 → **一个物理** `MutationObserver`（`__welineBusOwned`），多订阅者扇出 `onRecords` / `onFlush` |
| 合并语义 | 首次投递 `disconnect` → **`setTimeout(quietMs)` 安静窗** → 可选 `requestIdleCallback` → flush（含 **`takeRecords` 抽干**）→ 再 `observe`；**禁止** `observe` 后同步再 `deliverRecords` |
| 反馈环保险丝 | **`MAX_FLUSH_DEPTH=50`**；**`MAX_FLUSHES_PER_WINDOW=40` / 250ms** → 停观察 + `weline:dom:mutation-loop` |
| 禁止 | 业务依赖私有 document 根 MO；双 rAF / `queueMicrotask` / **仅** `requestIdleCallback({ timeout })` 立刻 re-observe；放宽 DEV 阈值当修法 |
| 豁免 | 第三方确需裸 document 观察：实例上设 `__welineMoAllowRawDocument = true`（须文档化，禁止静默滥用） |
| 元素级 | 非 document 根默认可私有通道（`observeMutationsCoalesced`）；`share:false` 可强制私有 |
| 订阅 pause | `pause`/`withPaused` 为**订阅级** |

实现位置：

- 总线 + Gate：`Weline_Framework` → [`view/statics/js/dom-observer.js`](../../../Framework/view/statics/js/dom-observer.js)
- DEV 风暴护栏（包底层 Native，不拆 Gate）：`Weline_Frontend` → `view/statics/js/weline.js`

底层原语 `Weline.observeMutationsCoalesced` 仍保留给非共享场景；**新代码优先 `Weline.dom.observe`**。Gate 兜底误用的裸 `observe(document*)`。

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

无总线时的本地降级须包在 `/* ARCH_MO_FALLBACK_START */` … `/* ARCH_MO_FALLBACK_END */`，且 **disconnect + 安静窗** 后再 `observe`。

## 与 DEV 看门狗

`installDevMutationObserverGuard`：若已安装 Gate，只替换 `__WelineNativeMutationObserver`（总线物理投递的风暴计数），**不**替换 `window.MutationObserver`（保持 Gate）。

截断时 `detail` 含：

- `reason`：`sync_reentry` / `delivery_storm`
- `label`：物理观察者 `__welineMoLabel`
- `channelKey` / `subscribers`：共享通道订阅名（若有）
- `createStack` / `observeStack`

全局：`window.__WelineDevMutationLoopLast`、事件 `weline:dev:mutation-loop` / `weline:dom:mutation-loop`。

架构正确时不应触发 DEV 截断。**禁止放宽阈值当修法**。

## 门禁

- MCP 硬规则：`dom_mutation_observe_via_weline_dom`
- 契约：`FrontendViewContractsTest` / DomMutationBus（Gate 标记、`takeRecords`、一作方禁止 document 裸 MO）
- 规范交叉：[前端JS模块加载规范.md](../../Theme/doc/前端JS模块加载规范.md) §1.2

## 后续里程碑

元素级/属性级裸观察者继续审计；第三方 libs（vue/simplebar）走 `__welineMoAllowRawDocument` 豁免并登记。
