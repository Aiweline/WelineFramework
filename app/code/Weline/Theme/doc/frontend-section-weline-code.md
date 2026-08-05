# 前台 Section `weline-code` 约定（强约束）

> **强约束 / 高压线**：凡前台纳入扫描的源模板中，字面 `<section>` 与 `w:slot wrapper="section"` **必须**配置非空语义 `weline-code`。缺省会在 `setup:upgrade` / Rules 致命门禁失败，并导致 Visitor Pixel 溯源降级为 `missing_code` / `empty_code`。  
> 权威技能入口：`dev/ai/skills/前端主题工程师-主题模板开发/SKILL.md`、`dev/ai/skills/前端主题工程师-组件与页面构建/SKILL.md`、`dev/ai/skills/visitor-pixel/SKILL.md`。  
> 全局硬约束：`dev/ai/global-constraints.md` §7。

## 目标

前台内容块用 `<section>`（或 `w:slot wrapper="section"`）标识，并配置非空 `weline-code`，供：

1. `setup:upgrade` / Rules 致命门禁（`FrontendSectionWelineCodeRule`）
2. Visitor Pixel / GTM 事件来源识别（`section_code` / `section_event_key` / `section_source_status`）
3. 分析面板与下游对「哪个区块触发了事件」的稳定归因

**缺 code 不会丢事件**，但 `section_source_status` 会标记为 `missing_section` / `missing_code` / `empty_code`；新模板禁止依赖该降级路径。

## 命名建议

| 场景 | 模式 | 示例 |
|---|---|---|
| Theme 布局 slot | `theme.{layout}.{role}` | `theme.homepage.hero` |
| Theme 组件 | `theme.component.{name}` | `theme.component.card` |
| 业务模板 | `{module}.{page}.{role}` | `checkout.checkout.items` |
| Index 首页 | `index.home.{role}` | `index.home.hero` |
| SiteBuilder AI | `theme.sitebuilder.{component}` | `theme.sitebuilder.ai_generated_section` |
| Builder 通用 section | `theme.builder.section` | 默认回退 |

role 优先取 slot `id` / DOM `id` / `aria-labelledby`，规范化为 snake_case。禁止无语义的 `section1`。同一文件内禁止重复 code。

## 写法

### 字面 section

```html
<section class="hero" weline-code="theme.homepage.hero">
    ...
</section>
```

PHP 插值（Scanner 视为已配置）：

```html
<section weline-code="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
```

### Slot（wrapper=section）

```html
<w:slot id="hero"
        name="Hero"
        wrapper="section"
        weline-code="theme.homepage.hero"
        class="slot-hero">
</w:slot>
```

`wrapper="section"` 时 **必须** 带非空 `weline-code`，否则 Theme SlotValidator 抛 TemplateException。

## 扫描纳入 / 排除

**纳入**：路径含 `/frontend/`、`/Frontend/`、`/theme/frontend/`；或 `view/hooks/`（非 Backend）；或 `Weline/Index/view/templates/**`。

**排除**：Backend / backend / theme/backend / Admin、`dashboard/widgets`、`view/tpl/`、`generated/`。

## 本地与分项自检（强制）

新增/修改前台 section / `wrapper=section` 的模板后，必须跑：

```bash
php bin/w frontend:check-section-code
php bin/w frontend:check-section-code --json
# 别名亦可：frontend:checksectioncode
```

`core:update` / 分项更新后，子站应跑 CLI；退出码非 0 表示纳入集仍有裸 section / 缺 code / 同文件重复 code。

不扫描 DB 历史 CMS HTML；历史页面靠运行时 `section_source_status` 可观测，不丢事件。

## 排障

| 现象 | 处理 |
|---|---|
| upgrade 报【致命错误】frontend-section-weline-code | 按 CLI 报告补 `weline-code` 后再 upgrade |
| Slot 编译 TemplateException | `wrapper=section` 补语义 code |
| Pixel `missing_code` / DEV warn | DOM 最近父 `section` 缺属性；改源模板并清编译缓存后 `server:reload` |
| `page_view` status=`n/a` | 正常（无交互 element） |
| SiteBuilder 保存后仍裸 section | 应被 `postSaveVirtualComponent` 注入；检查是否未走该保存入口 |

相关实现：`Framework/Rules/Frontend/SectionWelineCodeScanner.php`、`FrontendSectionWelineCodeRule.php`、`Theme/Taglib/Slot*.php`、`Visitor/.../pixel.js`。
