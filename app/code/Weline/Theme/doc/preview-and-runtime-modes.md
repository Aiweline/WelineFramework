# 主题预览与运行态：三种权威来源

> 权威文档。改 Theme 预览、可视化编辑器、版本真实预览、店面主题解析时必读。  
> MCP 技能：`get_skill(weline-theme-development)` / surface `frontend_development`。  
> 同构硬规则：MCP `preview_storefront_delivery_parity`（业务逻辑与交付路径与正式店面一致；本文件只区分**身份/参数权威**，不授权预览专用抽空逻辑）。

## 一句话对照

| 状态 | 称呼 | **身份权威**（theme / scope / status / version / target…） | 典型入口 |
|------|------|--------------------------------------------------------------|----------|
| 1 | **可视化编辑预览** | **请求参数为主**（query + typed `editor_context`） | 编辑器 iframe → **真实店面 path** + `editor_mode=1` / `shell=theme-editor` / `editor_context` |
| 2 | **版本真实预览** | **预览 Token 反解析参数为准**（token 不可被 URL 改写主题身份） | `#btnFrontendPreview` / `start-preview` → 真实店面 URL + `weline_preview_token` |
| 3 | **正式（正常店面）** | **RequestContext / 路径 / Scope 解析为准** | 访客普通 URL；读 `published_release_id` → `r{id}` |

三种状态**业务渲染链路必须同构**；差别只在「这次请求的主题身份从哪来」。

---

## 1. 可视化编辑预览（参数为主）

### 是什么

后台主题编辑器画布 / iframe 里的布局预览。只读展示当前编辑态（draft / 指定 version 等），**不**种店面预览 Token，**不**激活前台「预览模式」浮层。

画布加载的是**真实店面路由**（path = layout），由路由自己套布局与控制器内容——与店面一致。禁止用 `theme-preview/content`、`theme-editor/layout-preview` 等自定义壳地址代替店面 path。

### 权威来源

以**当前请求参数**为准，优先级与装配见 `PreviewContextService` + 编辑器壳：

- URL path：店面公开路径（点击导航，或布局 path 本身；首页为 `/`）
- URL query：`theme_id` / `frontend_theme_id` / `editor_area` / `preview_area` / `layout_option` / `status` / `version_id` / `scope` / `interaction_mode` / `editor_mode` / `shell=theme-editor` 等
- typed **`editor_context`**（JSON）：与 query 对齐的编辑会话身份；保存/删部件必须带同一套上下文
- 版本面板在画布内切换历史版：仍走参数（如 `status` + `version_id`），**不是**店面 Token 路径

### 正确用法

- iframe / `#btnPreview` → **`buildCanvasStorefrontPreviewUrl`**：真实店面 path + 编辑器标记；**禁止**打开 `theme-preview/content` 或 `layout-preview` 当画布
- **禁止**在可视化预览里调用 `start-preview` 种 Cookie（会误进「版本真实预览」态）
- 改预览身份：改参数 / 重建 `editor_context` / 换店面 path，不要假设 RequestContext 里已有店面 Scope
- 调试时以 Network 里 iframe `src`（店面 path + query + `editor_context`）为准

### 禁止

- 用自定义壳 URL（`theme-preview/content`、`layout-preview`）代替真实店面 path
- 用店面 path/Scope 热缓存「猜」编辑器当前主题
- 为安静画布 `editor_mode` early-return 丢掉 Hook/部件/浮层真实逻辑（违反 `preview_storefront_delivery_parity`）

后台主题预览同样不开自定义壳：主题列表的后台预览进入真实后台首页 `weline_dashboard/backend/dashboard`，并带 `editor_mode` / `shell=theme-editor`。编辑器画布在前台区域始终是真实店面 path。

---

## 2. 版本真实预览（Token 反解析为准）

### 是什么

从编辑器「真实前端预览」等入口，打开**真实店面页面**，并带短期预览 Token（Cookie / query），用于在正式路由上核对 draft 或指定版本外观与交互。

### 权威来源

1. `start-preview`（或等价启动 API）把当时选定的 theme / scope / status / version / target 等**写入 Token 载荷**
2. 后续店面请求：`PreviewContextService` **反解析 Token** 得到上下文
3. 代码约定（严重）：**有效 Token 是 Theme/Scope/Store/target 身份的不可变服务端权威**；URL 上的 theme/scope 等**不能覆盖** Token  
   - 例外：显式 `locale` 覆盖仅影响文案/chrome，不写用户语言 Cookie，也不改 Token 里的主题身份

实现锚点：`PreviewContextService::getCurrentContext()`（token 合并在 request 之后、作为身份终裁）；`PreviewTokenService`。

### 正确用法

- 仅 `#btnFrontendPreview`（及文档标明的真实预览入口）调用 `start-preview`
- **首页预览 URL path 必须是 `/`，禁止把空串交给 `getFrontendUrl('')`**：空串会复用当前 `REQUEST_URI`；经 BinQuery 时会变成 `/framework/query-bin?weline_preview_token=…`。用 `ThemePageTypeResolver::getFrontendUrlPathForPreview()`；path 就是布局 path，拒绝 `framework/query-bin`
- 可视化里改完草稿后点「真实前端预览」：**必须**先落盘脏表单/挂起自动保存，再发 Token；店面应按 Token 的 **draft**（除非顶栏显式切到 published）渲染同一 scoped 工作区，**不是**只能看已发布
- 验收/排错：先看 Token 反解出的字段，再看页面；**不要**用当前 URL query 覆盖结论
- 换主题 / 换版本 / 换 Scope：必须**重新 start-preview** 发新 Token，禁止手改 URL 参数指望生效
- 退出：清客户端 Token + gateway `exit` 路径；失效 Token 不得回种
- 实现锚点（店面侧）：有效 Token 时安装 `LayoutIdentity`，并走 draft `processSlots`，禁止误入正式实体硬切

### 与「画布里看版本」的区别

| | 画布内版本 | 版本真实预览 |
|--|-----------|--------------|
| 载体 | 店面 path + 参数 / `editor_context` | 店面 URL + Token |
| 权威 | query / `editor_context` | Token 反解析 |
| 预览浮层 | 否（编辑器壳） | 是（店面预览 chrome） |

---

## 3. 正式 / 正常店面（上下文解析为准）

### 是什么

访客（或未进入预览态的后台浏览）看到的线上布局：已发布实体，按站点 Scope 与路径解析。

### 权威来源

- **RequestContext**（website / store / 语言等）+ 路由 / 页型
- `ThemeContextService` 等按 Scope 解析激活主题（热缓存 / path 身份）
- 布局实体：`published_release_id` → 磁盘 **`r{releaseId}`**；**禁止** s* / 旧 r* scandir 回退

无有效预览 Token、非编辑器画布请求时，**一律**走本态；不得偷偷读 draft 工作区。

### 正确用法

- 发布验收：清预览 Token / 退出预览后，用正式 Host 打开目标页
- 主题身份以上下文解析结果为准；不要把编辑器 query 或过期 Token 残渣当成正式身份
- 缺 `r{id}`：fail-closed（不显示陈旧 draft bake），而不是回退挑目录

---

## Agent / 技能正确使用方式

改 Theme 预览或店面主题解析时：

1. `prepare_project` → `get_skill(weline-theme-development)`（或 surface `frontend_development`）
2. 先判定当前请求属于上表哪一态，再查身份字段来源
3. 业务逻辑修改必须三态同构（`preview_storefront_delivery_parity`）；只允许在身份装配层分支
4. 文档入口：本文件 + [`开发/Theme开发总指南.md`](./开发/Theme开发总指南.md) + [`visual-editor/README.md`](./visual-editor/README.md) + [`version-control/README.md`](./version-control/README.md)

### 速查：我该信谁？

```
有有效预览 Token？     → 信 Token 反解析（态 2）
否则 editor_mode=1 / shell=theme-editor + editor_context？ → 信参数（态 1，店面 path）
否则                   → 信 RequestContext / Scope / published r{id}（态 3）
```

---

## 相关源码（导航）

| 能力 | 位置 |
|------|------|
| 参数 / Token 合并与权威 | `Service/PreviewContextService.php` |
| Token 签发与读取 | `Service/PreviewTokenService.php` |
| 是否走预览存储上下文 | `Service/PreviewRequestInspector.php` |
| 编辑器壳与 preview API | `Controller/Backend/ThemeEditor.php` |
| 画布 URL（店面 path） | `view/statics/js/theme-editor.js` → `buildCanvasStorefrontPreviewUrl` |
| 店面 editor_mode 资源注入 | `Observer/LayoutSlotRenderer.php` + `EditorModeAssetInjector` |
| ~~遗留 content HTTP 壳~~ | **已删除**：无 `ThemePreview/Content.php`，无 302 兼容；画布仅真实店面 path |
| 前台 layout 编译/取槽 | `renderFrontendLayoutTemplateHtml` → 真实 `layouts/{type}/{option}.phtml`（不再走 content 桩） |
| 后台主题预览 | 真实后台首页 `weline_dashboard/backend/dashboard`（不再走 `layout-preview` 壳） |
| Policy 包装 | 真实 `theme/frontend/layouts/{type}/{option}.phtml`（不再走 content 桩） |
| 发布实体路径 | `ThemeLayoutEntitySlotFiller`（仅 `r{published_release_id}`） |

## 修订记录

- 2026-09-18：删掉取样状态机、`getLayoutPreview` / `layout-preview` 路由，以及无人赋值的 `theme_preview_content` / `layout_preview_mode`。后台主题预览改为真实 Dashboard 地址。
- 2026-09-18：可视化编辑预览改为**真实店面 path + 参数**；禁止 `theme-preview/content` / `layout-preview` 冒充前台画布；文档与 Config/Layout 入口对齐。
- 2026-09-16：首版。明确可视化（参数）、版本真实预览（Token 反解析）、正式（上下文）三种权威。
