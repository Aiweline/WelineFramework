# CSS 关键路径契约（Layout-First · Apple 设计系统）

> 状态：**现行**（2026-10-22 修订：布局 CSS 优先阻塞，保证骨架/LCP 稳定）
> 适用范围：前台默认主题 + 后台默认主题 + 消费本设计系统的设计主题
> 配套：[`组件与Token规范.md`](./组件与Token规范.md)、[`../部件静态资源固化规范.md`](../部件静态资源固化规范.md)

---

## 0. 一句话

**布局/骨架 CSS 必须首屏立即阻塞生效；组件与装饰 CSS 才允许合并、延迟或按需加载。**

---

## 1. 分层与阻塞策略（硬）

| 层 | 内容 | 输出方式 | 阻塞渲染 |
|---|---|---|---|
| Layout-critical | `layout-critical-{area}.css` 壳层几何 | `<style data-weline-layout-critical>` 内联，最先输出 | 否（内联即用） |
| Theme Pack | 色盘/Token + foundation + backend/frontend + theme.css | **单个阻塞 `<link>`（`tryLinkHtml`）** | **是** |
| 设计主题皮肤 | `hanfu-skin.phtml` 等品牌布局/皮肤 CSS | 阻塞 `<link>` | **是** |
| 组件层 | `components/*.css` | 按 `foundation → tokens → components` 顺序合并进 Theme Pack | 是（随主包） |
| 模块页面 CSS | `<css>`（默认） | 阻塞 `<link>` | 是 |
| 模块页面 CSS（可选） | `<css nonblocking>` | preload + onload + noscript | 否，仅限非布局样式 |
| Widget layout | `layout-source` / `kind=layout` | 合并为一个阻塞包 | 是 |
| Widget source | `source` | 前 N 个（默认 6）合并为阻塞包，其余延迟（`media=print` onload） | 前 N 是 |
| Theme overlay | 设计主题私有 leaves | 阻塞主包之后 | 是 |

### 1.1 输出顺序

```
<style layout-critical>        ← 骨架几何，最先可绘制
<link theme pack>              ← 阻塞：Token + foundation + 组件 + 主题
<link design-theme skin>       ← 阻塞：品牌布局/皮肤
<link widget layout / source>
```

- 布局/骨架规则（shell、sidebar、grid、header、footer、widget 几何）**禁止**放进内联之外的延迟通道。
- `tryInlineCssHtml` / `tryPreloadLinkHtml` 仅作为可选 API 保留；使用前提是：该包**不含布局/骨架规则**，且已有内联关键 CSS 兜底。
- 不得把 Theme Pack / 设计主题皮肤改成 `media=print` 或 preload 来“降阻塞”；实测会导致 LCP 骨架闪屏。

---

## 2. 组件 CSS 用量（编译期）

1. 组件各自成 bundle（`ui-button`…），并按 `foundation → tokens → components` 顺序进入 Theme Pack。
2. `resource:compile welineUi` 扫描模板 `class` 使用，写
   `view/statics/ui/components/usage.json`（区域清单 + 每组件字节）。
3. 组件 CSS 必须**自足**（不依赖 foundation 同名基础规则），便于未来按需拆分。
4. 现状：六组件在前后台均为全局高频使用，合并进单一 Theme Pack（一次请求、按 hash 长缓存）是当前最优。
5. 安全兜底：无法判定时输出全部组件，禁止静默缺样式。

---

## 3. 层叠与去冗余

- 组件外观只允许存在一份定义：`components/*.css` 是唯一来源；`foundation.css` 中重复的历史规则按批次删除。
- 组件层不写 `!important`、不写颜色字面量，只消费语义 Token。
- 页面私有样式只能落 `@layer page` 或既有页面 CSS 通道；不得重写颜色/圆角/阴影/控件高度。
- 同一元素多来源冲突时：**Token > 组件 > 页面布局**。

---

## 4. 显式 spinner 优先（防双 loading）

`.w-button[aria-busy="true"]:has(.w-spinner)::after { content: none; }`

模板已有 `.w-spinner` 时禁止 `::after` 再生成一个；没有显式 spinner 的按钮才使用伪元素。

---

## 5. PROD 编译与静态发布（硬）

- PROD 编译模板在 `generated/complicate/<Vendor>/<Module>/view`；`template:clear` 必须按模块清该子树。
- `@static` 在编译期烤入 build id；CSS 重编译后必须：
  1. `resource:compile welineUi`
  2. `StaticBuildPublisher::publish(BP."pub/static")`
  3. `template:clear`
  4. `server:reload`
- 清缓存后必须做源码级验收（curl HTML），不能只看浏览器 DOM：preload 加载后会变成 stylesheet。

---

## 5.2 为什么 Theme Pack 不能整体异步（实测）

- 尝试：内联 `layout-critical + tokens + critical-utilities + components`，Theme Pack `preload`。
- 结果（`/tmp/theme-verify/block-drawer.png`，阻断 Theme Pack）：卡片/按钮/抽屉正常，
  但 **图标变成超大块、表格/工具栏布局塌陷**——foundation 的 base/utilities/icon 规则不在内联集合内。
- 二次尝试：再内联 foundation 的 `reset/tokens/base/layout/utilities + @keyframes + .w-icon`（53KB raw），
  仍失败——后台 topbar/sidebar 重叠、图标错乱；backend.css/theme.css 的 shell/布局规则不可缺。
- 结论：**局部抽取不足以支撑首屏；Theme Pack 必须保持阻塞**。正确路径是完整 provenance：
  foundation + backend/frontend + theme 每条规则标注 layout/skin，再谈拆分；
  在此之前只做“减小阻塞包”（删除历史重复组件规则），不做布局异步。

## 5.3 设计主题 head（全量）

- 所有设计主题的 `frontend/partials/head/*.phtml` 必须遵守本契约：
  `theme:layout-critical` 内联；Theme Pack 用 `tryLinkHtml` 阻塞；禁止
  `tryPreloadLinkHtml` / `tryInlineCssHtml` / `renderInlineStyle`。
- `DesignSystemContractTest::testAllDesignThemesFollowLayoutFirstHeadContract` 全量扫描
  `app/design/**/frontend/partials/head/`，新增主题自动受检。

## 6. 预算与验收

| 指标 | 要求 |
|---|---|
| LCP 骨架 | 首屏不因 CSS 未到达而塌缩/闪屏；layout-critical + Theme Pack 顺序正确 |
| 组件 CSS | 合并进 Theme Pack，一次请求、压缩、长缓存；`usage.json` 记录字节 |
| 阻塞 stylesheet | 允许且要求布局类阻塞；禁止把布局类改成延迟 |
| `!important` | 组件层 0 |
| 双 spinner | 0 |

```bash
php bin/w resource:compile welineUi
php bin/w template:clear
php bin/w server:reload

# 源码级：Theme Pack 必须是阻塞 stylesheet，layout-critical 在它之前
curl -s <page> | grep -o '<style data-weline-layout-critical>'
curl -s <page> | grep -o '<link[^>]*data-weline-theme-head-pack[^>]*rel="stylesheet"'

vendor/bin/phpunit --no-coverage app/code/Weline/Theme/test/Unit/Ui/DesignSystemContractTest.php
vendor/bin/phpunit --no-coverage app/code/Weline/Theme/test/Unit/Backend/BackendThemeStandardContractTest.php
php app/code/Weline/Theme/bin/backend-theme-audit.php --strict
```

---

## 7. 禁止

- 把 Theme Pack、设计主题皮肤、widget layout CSS 改成 preload / `media=print`。
- 用“零阻塞”换取骨架闪屏；布局 CSS 的阻塞是 LCP 契约的一部分。
- 只在内联里放布局规则而不进主包（主包未达时页面仍会塌）。
- 为“快”把 Token/组件写成字面量，或绕过 `usage.json` 手工维护组件清单。
- 删除 `foundation.css` 历史规则时不做逐页截图对比。
