# 弹出层 z-index 抬升（Weline UI 基建）

## 规则

所有弹出层（portal 浮层 / 原地 hover·open 飞出层）统一：**相对宿主（或兄弟层叠上下文）z-index + 1**。

| 场景 | API | 说明 |
|------|-----|------|
| Dialog / Menu / Popover portal | `Weline.UI.stack.apply(el, host)` | 已有；挂到 body/top-layer 时自动 host+1 |
| 原地 flyout（megamenu、更多下拉等） | `data-wf-*` 或 `Weline.UI.stack.elevate(host\|layer)` | 先抬升 scope 压过兄弟槽，再 layer = scope + 1 |

## 属性（`wf-` 前缀，可读短名）

| 属性 | 含义 |
|------|------|
| `data-wf-scope` | 层叠作用域（可选；Header 槽会自动识别） |
| `data-wf-host` | 触发悬停/打开的容器 |
| `data-wf-layer` | 弹出面板 |
| `data-wf-raised` | 运行时：当前已抬升 |
| `data-wf-unclip` | 运行时：祖先取消 overflow 裁切 |

CSS 变量：`--wf-stack-z`

## 声明式用法（推荐）

```html
<section data-wf-scope><!-- 可选 -->
  <div data-wf-host><!-- hover / .active / aria-expanded / focus-within -->
    <button type="button">打开</button>
    <div data-wf-layer>…</div>
  </div>
</section>
```

运行时（`weline-ui.js`）自动：

1. `pointerover` / `focusin` / `class|aria-expanded|data-state` 变化时调用 `stack.elevate`
2. 打上 `data-wf-raised`，写 inline `z-index = siblingPeak + 1`（layer 再 +1）
3. 沿 layer→scope 祖先打上 `data-wf-unclip`，Foundation 强制 `overflow: visible`
4. 离开 / 关闭后自动清理

## 命令式

```js
Weline.UI.stack.elevate(hostEl);
Weline.UI.stack.clearElevate(hostEl);
Weline.UI.stack.apply(portalEl, host);
```

## 注意

- 业务模板优先加 `data-wf-host` / `data-wf-layer`，不要写死 `z-index`。
- Portal 组件（`.w-menu` / `.w-popover` 等）继续走 floating portal，无需再标。
- 相对锚点的工具条 / 操作条使用 `data-w-component="anchored-float"`（或 `Weline.UI.floating.attach`），贴边与翻转由浮层内核处理，详见 [`anchored-float.md`](./anchored-float.md)。
- Header 分类 / 更多 / 侧栏子层已改为自研 `data-w-component="popover|menu"`（可 `data-w-open-on="hover"`），不要再写 CSS `:hover { display }` 飞出层。
