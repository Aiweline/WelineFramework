# CSS 关键路径契约（Critical CSS Contract · Apple 设计系统）

> 状态：**现行**（2026-10-21 起，激进模式）
> 适用范围：前台默认主题 + 后台默认主题 + 消费本设计系统的设计主题
> 配套：[`组件与Token规范.md`](./组件与Token规范.md)、[`../部件静态资源固化规范.md`](../部件静态资源固化规范.md)
> 目标：**首屏不等待任何 CSS 请求**；只发送页面用到的组件字节；层叠结果稳定、可预测。

---

## 0. 一句话

**首屏关键 CSS 全部内联；主题主包只 preload，不阻塞渲染；组件 CSS 按编译期用量合并；内联在后，覆盖历史冗余规则。**

---

## 1. 三层 CSS 与阻塞策略

| 层 | 内容 | 输出方式 | 阻塞渲染 |
|---|---|---|---|
| Critical（首屏） | `layout-critical` 壳层几何 | `<style data-weline-layout-critical>` 内联 | 否（内联即用） |
| Critical（Token） | 色盘/语义变量（`PACK_TOKENS*`） | `<style data-weline-inline-pack="tokens">` 内联 | 否 |
| Critical（组件） | 编译期分析出的组件 CSS | `<style data-weline-ui-components="area">` 内联 | 否 |
| Theme Pack（主包） | foundation + backend/frontend + theme.css + dark | `<link rel="preload" as="style" onload="…rel='stylesheet'">` + `<noscript>` 回退 | **否** |
| 模块页面 CSS | `<css nonblocking>Module::css/…</css>` | preload + onload + noscript | 否 |
| 设计主题皮肤 CSS | 主题 head 用 preload 辅助函数 | preload + onload + noscript | 否 |
| Widget CSS | `layout-source` / `source` bake | 既有 N+1 合并：前 N 阻塞、后延迟（`media=print` onload） | 按既有规则（布局桶仍阻塞） |

### 1.1 输出顺序（硬）

```
<preload theme pack>                 ← 只发起预取，不阻塞
<style layout-critical>              ← 壳层几何，最先可绘制
<style tokens inline>                ← 色盘/语义变量
<style components inline>            ← 组件 CSS（在 preload 之后，源顺序胜出）
… fonts（display=swap）…
<noscript><link stylesheet theme pack></noscript>
```

- **内联必须位于 preload 之后**：同为设计系统规则时，源顺序让内联覆盖主包里的历史基础规则（否则开关高度等会被旧规则打回）。
- 内联组件 CSS 的层叠层与源文件一致（`@layer components`），不得改成无层；否则会压过页面级 `@layer page` 覆盖。
- `<noscript>` 必须提供阻塞 stylesheet 回退；JS 关闭时页面仍完整可用。

---

## 1.2 首屏自足性（硬）

主包被阻断（preload 未到达）时，首屏仍必须正确显示：

- 组件 CSS 必须**自足**：display / 尺寸 / 边框 / 背景 / 字号 / 状态都写在 `components/*.css`，
  不依赖 `foundation.css` 的同名基础规则；
- 语义 Token 必须内联（`ui-tokens` bundle：色盘映射 + 控件高/圆角/阴影/字阶）；
- 首屏布局原语必须内联（`ui-critical` bundle：`w-container/w-grid/w-auto-grid/w-stack/w-cluster/w-text/w-toolbar` 等）；
- 验收方法：Playwright 路由 **abort** `**/theme-head/**`（主包永不加载），断言
  按钮 40px/12px、输入 40px、开关 51×31、卡片 18px、主色为色盘值；通过后才算达标。

## 1.3 页面级 CSS 通道

- 模块页面私有 CSS：`<css nonblocking>Vendor_Module::css/backend/x.css</css>`，
  输出 `preload + onload + noscript`；只有确属首屏关键（壳层/字体/布局桶）才用默认 `<css>` 阻塞。
- 设计主题皮肤 CSS：head 里用 preload 辅助函数输出（参考 `app/design/Weline/hanfu/.../hanfu-skin.phtml`
  的 `$hanfuCss`），不得直接写阻塞 `<link rel="stylesheet">`。
- Widget CSS 仍按既有 bake：`layout-source`/`kind=layout` 保持阻塞（LCP/CLS），普通 source 按 N+1 延迟合包。

## 2. 组件 CSS 用量（编译期，唯一事实源）

1. 组件注册：`view/ui/css/components/<component>.css`，各自 bundle（`ui-*`），并在
   `foundation` bundle 中保持 `foundation → tokens → components` 顺序进入主包（供 noscript/兜底）。
2. 编译期分析：`resource:compile welineUi` 扫描模板 `class`，产出
   `view/statics/ui/components/usage.json`（区域清单 + 文件数 + 每组件字节）。
3. 首屏内联：`ComponentUsageService::renderInlineStyle($area)` 只输出该区域用到的组件（合并 + 压缩）。
4. 安全兜底：无法判定（动态类名/未知模板）→ 输出该区域全部组件；不允许“可能缺样式”的静默省略。
5. 现状（2026-10-21）：六组件在前后台均为全局关键（按钮/输入/开关/导航/表面/反馈），
   因此首屏内联为全量；后续按路由/布局细分时只改分析器，head 契约不变。

---

## 3. 继承与覆盖（去冗余）

- **组件外观只允许存在一份定义**：`components/*.css` 是唯一来源；
  `foundation.css` 中与组件重复的历史规则标记为 deprecated，按批次删除（删除前逐页截图对比）。
- 组件 CSS 不写 `!important`；不写颜色字面量；只消费语义 Token。
- 页面私有样式只能落 `@layer page`（模块 backend CSS）或既有页面 CSS 通道；
  不允许用更高特异性压过组件基础态（`data-tone`/`data-size` 已覆盖场景）。
- 同一元素多个来源（主题、设计皮肤、页面）冲突时：**Token > 组件 > 页面布局**；
  颜色/圆角/阴影/控件高度不得在页面层重写。

---

## 4. 预算与门禁

| 指标 | 上限 | 说明 |
|---|---|---|
| 首屏内联 CSS（gzip） | ≤ 24 KB（实测：后台 **8.75 KB** / 前台 **9.86 KB**） | layout-critical + 语义 Token + 首屏布局原语 + 组件；超出需拆分或按路由裁剪 |
| 阻塞 stylesheet（JS 开启） | **0** | 除 `<noscript>` 回退外不得出现 `rel="stylesheet"` 主包 |
| 主包 preload 数 | ≤ 2 | 前台：storefront + overlay；后台：单包 |
| 组件 CSS 字节 | 记录于 `usage.json` | 只允许下降或持平；新增组件需写明预算 |
| `!important` | 0 | 组件层与模块页面 CSS |

---

## 5. 验收（必须可复现）

```bash
# 1) 编译 + 用量分析
php bin/w resource:compile welineUi

# 2) 真实页面：不得有阻塞主包（noscript 除外）
curl -s <backend-page> | grep -o '<link[^>]*rel="stylesheet"[^>]*>'   # 仅允许 noscript 内/部件 CSS
curl -s <backend-page> | grep -c 'rel="preload" as="style"'            # ≥1
curl -s <backend-page> | grep -c 'data-weline-inline-pack'             # ≥1
curl -s <backend-page> | grep -c 'data-weline-ui-components'           # 1

# 3) 视觉与层叠
#    - 开关 51×31、按钮 40px 高/12px 圆角、输入 40px 高
#    - 1440/375、亮/暗截图对比
#    - 首屏（preload 未完成时）壳层/组件已正确绘制

# 4) 契约测试
vendor/bin/phpunit --no-coverage app/code/Weline/Theme/test/Unit/Ui/DesignSystemContractTest.php
vendor/bin/phpunit --no-coverage app/code/Weline/Theme/test/Unit/Backend/BackendThemeStandardContractTest.php
php app/code/Weline/Theme/bin/backend-theme-audit.php --strict
```

---

## 6. 禁止

- 在 JS 开启的页面上输出阻塞主包 `<link rel="stylesheet">`（noscript 回退除外）。
- 把内联组件 CSS 放在 preload 之前（会被主包历史规则覆盖）。
- 组件 CSS 内联时不压缩、或把全部组件无差别塞进每个页面（除安全兜底）。
- 为“快”把 Token/组件写成字面量、或绕过 `usage.json` 手工维护组件清单。
- 删除 `foundation.css` 历史规则时不做逐页截图对比。
