# URL Rewrite 旧 Slug 301 保留计划

## 目标

产品等业务实体的 slug 变化后，旧 slug 不能从 `url_rewrite` 中消失。旧 slug 必须作为废弃重写记录保留，并明确知道新的 canonical slug 在哪里，访问旧 URL 时返回 `301` 跳转到新 URL，避免搜索引擎和外部流量入口丢失。

本计划采用 `url_rewrite` 表内方案：canonical 记录保持稳定，旧 slug 作为同表 redirect 记录保存，不新增独立历史表。

## 表结构调整

在 `Weline\UrlManager\Model\UrlRewrite` 上通过 `#[Col]` 扩展字段，执行 `php bin/w setup:upgrade` 同步 schema。

新增字段建议：

- `rewrite_type`：`canonical` 或 `redirect`，默认 `canonical`。兼容旧数据时，空值或 `null` 按 `canonical` 处理。
- `target_rewrite_id`：redirect 记录指向的 canonical `rewrite_id`。
- `target_rewrite`：redirect 记录指向的新 slug，例如 `product/new-slug`。
- `redirect_code`：redirect 状态码，默认 `301`。
- `is_active`：记录是否启用，默认 `1`。
- `created_at`：创建时间。
- `updated_at`：更新时间。

索引建议：

- 保留现有 `(website_id, url_identify)` 唯一索引。
- 增加 `(website_id, rewrite, rewrite_type, is_active, rewrite_id)` 查询索引，用于旧 slug 命中和 canonical 命中。
- 增加 `(target_rewrite_id, rewrite_type, is_active)` 查询索引，用于压缩历史跳转链。

## 写入规则

产品 slug 写入时，canonical 行必须稳定：

- canonical 行继续使用稳定 `url_identify`，例如 `product_{websiteId}_{productId}`。
- 如果新 slug 和当前 canonical slug 一致，只确保 canonical 行字段完整，不创建 redirect。
- 如果 slug 从旧值变为新值，canonical 行仍保留原 `rewrite_id/url_identify`，只把 `rewrite` 更新为新 slug。

旧 slug 保留为 redirect 行：

- redirect 行的 `rewrite` 保存旧 slug。
- redirect 行的 `target_rewrite_id` 指向 canonical 行。
- redirect 行的 `target_rewrite` 保存最新 canonical slug。
- redirect 行的 `redirect_code` 固定为 `301`。
- redirect 行的 `url_identify` 不能复用 canonical identify，建议使用 `redirect_{canonicalRewriteId}_{sha1(oldRewrite)}`，避免 `(website_id, url_identify)` 唯一索引冲突。

多次改 slug 时必须压缩链路：

- 例如 `old-slug -> new-slug -> newest-slug` 后，`old-slug` 和 `new-slug` 都必须直接指向 `newest-slug`。
- 如果新 slug 曾经是 active redirect，必须停用该 redirect 或转回 canonical，避免自己跳自己。
- 禁止保存 `target_rewrite_id` 等于自身的 redirect。

冲突判断：

- 其他产品的 active canonical 和 active redirect 都视为占用，不能被新产品抢用。
- 同一产品自己的历史 redirect 可以恢复为 canonical，但恢复时必须停用对应 redirect。

删除规则：

- 产品删除没有新目标时，不创建新的 `301`。
- 删除流程按现有语义处理相关 rewrite，避免旧 URL 指向不存在内容。

## 读取和路由规则

所有 canonical 读取必须过滤 redirect：

- `ProductUrlRewriteService::findExistingRewrite()` 只查 active canonical。
- `SeoUrlGenerateRewrite` 出站 URL 生成只查 active canonical。
- SEO 自动提交和 sitemap 只读取 active canonical。

`RouterRewrite` 访问入口按以下顺序处理：

1. 先按 `website_id + rewrite` 查 active canonical，命中后保持现有内部 rewrite 行为。
2. canonical 未命中时，再查 active redirect。
3. redirect 命中后，使用 `Response::redirect($targetUrl, 301)` 返回永久跳转。
4. 跳转时保留原 query string，例如 `old-slug?utm_source=x` 跳到 `new-slug?utm_source=x`。
5. 找不到 canonical 或 redirect 时，保持现有 not found 缓存行为。

缓存要求：

- slug 更新后必须清理 `url_rewrite` 缓存。
- slug 更新后必须清理产品 handle 缓存。
- `RouterRewrite` 的进程内 `not_found` 缓存需要提供显式清理方法，避免旧 slug 刚写入 redirect 后仍被短时间缓存为未命中。

## SEO 规则

redirect 记录只服务访问迁移，不是收录目标：

- 不进入 sitemap。
- 不进入 SEO URL 自动提交任务。
- 不作为 canonical URL 输出。
- 不作为出站 URL 生成结果。

SEO cron `UrlRewriteSubmitSyncService` 必须只扫描 active canonical。旧数据 `rewrite_type` 为空时按 canonical 兼容，避免升级后现有 URL 被排除。

## 验证计划

实施前：

- 对 `UrlRewrite`、`ProductUrlRewriteService`、`RouterRewrite`、`SeoUrlGenerateRewrite`、`UrlRewriteSubmitSyncService` 运行 GitNexus upstream impact。
- 如果 impact 返回 HIGH 或 CRITICAL，先记录风险再进入实现。

Schema 和静态验证：

- 执行 `php bin/w setup:upgrade`。
- 执行相关 PHP 语法检查。
- 运行 UrlManager、WeShop Product、SEO 现有相关测试。

数据路径验证：

- 准备真实产品，先生成 `product/old-slug`。
- 修改为 `product/new-slug`。
- 检查 canonical 行仍是稳定 `url_identify`，且 `rewrite=product/new-slug`。
- 检查 redirect 行存在，`rewrite=product/old-slug`，`target_rewrite` 指向 `product/new-slug`，`redirect_code=301`。
- 再改为 `product/newest-slug`，确认所有历史 redirect 都直接指向 `product/newest-slug`。

HTTP 和 Browser 实测：

- 启动独立 WLS 实例，禁止使用默认 `9501`，例如：

```bash
php bin/w server:start -p 9983 -n ai-test-seo-redirect
```

- HTTP 预检旧 slug，必须看到 `301` 和正确 `Location`。
- HTTP 预检新 slug，必须返回 `200`。
- 使用 Codex in-app Browser 打开旧 slug，确认最终地址栏为新 slug，页面正常渲染。
- 在新 slug 页面检查 `link rel="canonical"` 指向新 slug。
- 直接刷新新 slug，不应再次跳转。
- 验证完成后停止 WLS：

```bash
php bin/w server:stop -n ai-test-seo-redirect
```

SEO 验证：

- 运行 URL rewrite cron diff。
- 确认只为新 canonical slug 创建 `weline_seo_task`。
- 确认旧 redirect slug 不进入 SEO 自动提交队列。

## 已知范围

- v1 先覆盖产品 slug 变更。
- 分类、CMS 页面后续复用同一 `url_rewrite` redirect 字段接入。
- 旧 slug 默认长期保留，不设置过期清理。
