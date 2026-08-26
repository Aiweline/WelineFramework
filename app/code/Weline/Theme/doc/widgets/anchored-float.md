# Anchored Float（贴边智能定位）

> 模块：`Weline_Theme` · 基座：`Weline.UI` · 组件名：`anchored-float`  
> 更新：2026-08-25

## 目的

为「相对某个锚点展示的悬浮条 / 工具条 / 轻量浮层」提供与 `tooltip` / `popover` 同一套：

- 视口贴边检测（visualViewport + safe area）
- 自动翻转（flip）与水平平移（shift / clamp）
- 优先外侧展示（默认 `top-end`，空间不足翻到对侧）
- 跨 iframe 文档坐标（预览画布内操作条）

业务页与主题编辑器**不得**手写 `left/top` 或复制断点算法；应声明本组件或调用 `UI.floating.attach`。

可视化编辑 iframe 内的悬浮 chrome（插槽「选择」条、部件操作条）使用固定浅底深字，**不跟随店铺暗色表面**，避免深色页头上黑底不可读。相关样式在 `editor-mode.css` / `theme-editor-overlay.css`。

## 声明式用法（推荐）

自身即浮层（相对父节点锚定）：

```html
<div class="my-toolbar"
     data-w-component="anchored-float"
     data-w-float-self
     data-w-placement="top-end"
     data-w-portal="0">
  <button type="button">…</button>
</div>
```

宿主 + 表面分离：

```html
<div class="widget" data-w-component="anchored-float" data-w-placement="top-end">
  <div data-w-float-surface class="my-toolbar">…</div>
</div>
```

| 属性 | 含义 |
|------|------|
| `data-w-float-self` | 当前元素即浮层表面，锚点默认父元素 |
| `data-w-float-surface` | 浮层表面选择器目标（宿主模式下） |
| `data-w-float-anchor` | 可选 CSS 选择器覆盖锚点 |
| `data-w-placement` | 语义 placement，如 `top-end` / `bottom-start` |
| `data-w-portal` | `0`/`false` 原地 fixed；默认可 portal 到 body/top-layer |
| `data-w-gap` / `data-w-viewport-padding` | 间距与视口内边距（与其它浮层一致） |

显示/隐藏由现有 CSS（如 `.show-actions`、`:hover`）或业务类控制；组件观察 `class` / `style` / `hidden` / `data-state` 变化后自动 `place`。

命令式事件：

- `weline:anchored-float:show`
- `weline:anchored-float:hide`
- `weline:anchored-float:place`

## 编程 API

```js
const api = Weline.UI.floating.attach(el, {
  placement: 'top-end',
  portal: false,
  self: true,
  // anchor: '.widget-wrapper',
});
api?.sync?.();
api?.place?.();
api?.hide?.();
```

底层共享：

| API | 说明 |
|-----|------|
| `Weline.UI.position(anchor, floating, placement)` | 单次定位 |
| `Weline.UI.floating.monitor(...)` | 滚动/缩放持续重排 |
| `Weline.UI.floating.viewport(padding, root?)` | 取视口框；`root` 可为 iframe 节点 |
| `Weline.UI.floating.syncViewport(doc?)` | 发布 `--w-floating-viewport-*` CSS 变量 |

## Theme Editor 复用

预览 iframe 内部件 `.widget-hover-actions` 与插槽条（`class="widget-hover-actions slot-toolbar"` + `data-slot-hover-actions="1"`）均声明：

- `data-w-component="anchored-float"`
- `data-w-float-self` + `data-w-placement="top-end"` + `data-w-portal="0"`

部件由父页 `theme-editor.js` 在注入后 `Weline.UI.floating.attach(...)`；插槽工具条 DOM 由预览 `editor-mode.js` 创建（**不在 iframe 内 attach**），**挂载与 sync 仅以父页 `initSlotToolbarFloats` / `slot-hover-sync` 为准**（与部件同源 `window.Weline.UI`）。插槽模式下 CSS 只隐藏 `.widget-wrapper > .widget-hover-actions:not([data-slot-hover-actions="1"])`，不得误伤插槽条。显示切换后调用实例 `sync`/`place`。样式几何交给 `--w-floating-left/top`（foundation + `editor-mode.css` / `theme-editor-overlay.css`）。hover 时不展示 `::before` 槽名标签，避免误当成工具条。

**Sticky hover（必守）**：操作条贴在锚点外侧时，锚点与浮层之间存在 gap。编辑器 `bindPenetrateStateEvents` / 预览 `bindSlotHoverTargetEvents` 必须像 `menu`/`popover` 的 hover-open 一样：

1. 指针移到 `.widget-hover-actions` / `.slot-toolbar`（及 slot 选择树、信息卡）时保持当前目标；
2. 离开锚点后短延迟（≈180ms）再切换或关闭，便于穿过空隙后操作按钮；
3. 不得在 `mousemove` 一离开锚点矩形就立刻清掉 `.show-actions` / `data-w-slot-hover-target`。
4. **插槽默认命中最内层**：嵌套 `data-wslot` 时，`data-w-slot-hover-target` 默认落在指针下最深容器（如 `navigation`），不得被外层 `header` 吞掉；仅在用户点击上级/下级穿透后 `pin`，离开当前链后恢复最内层默认。虚线高亮必须跟 `data-w-slot-hover-target`，不得单独用 CSS `:hover` 误导。
5. **同一过渡勿重置计时**：离开锚点后的 ≈180ms 延迟若目标未变，持续 `mousemove` 不得反复 `clearTimeout` 再开计时，否则工具条会“粘住”且挡住其它目标。

插槽「选择」条与部件操作条均由 `anchored-float` 贴边；两者的 **显示保持** 契约一致。不得再对手写 `top/left` 做插槽工具条定位。

## 编译与验收

1. 改 `view/ui/js/weline-ui.js` / foundation / overlay 后执行：`php bin/w resource:compile welineUi`
2. 契约：`ThemeEditorUiCapabilityContractTest::testWidgetHoverActionsReuseAnchoredFloatBaseComponent`
3. 手工：贴左/贴右小部件上操作条完整可见，优先浮在部件外侧且不遮挡主体

## 与其它浮层关系

| 组件 | 场景 |
|------|------|
| `tooltip` | 文案提示，hover/focus |
| `popover` / `menu` | 触发器 + 面板，点击或 hover-open |
| `anchored-float` | 工具条/操作条等「表面相对锚点」持续贴边 |
| `stack.elevate` | 原地飞出层 z-index；portal 浮层仍用 `stack.apply` |
