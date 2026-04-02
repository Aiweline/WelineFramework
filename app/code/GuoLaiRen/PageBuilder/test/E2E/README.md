# AI 建站完整流程 E2E 测试

## 测试目标

验证从创建会话到最终能访问上线网站域名的完整 AI 建站流程。

## 完整流程

```
创建会话 → 填写需求 → 构建虚拟主题 → 发布上线 → 访问域名
```

### 步骤 1: 创建会话

```bash
POST /admin/pagebuilder/backend/ai-site-agent/post-create-session
```

**返回**：
- `public_id`: 会话公开 ID
- `workspace_url`: 工作区 URL

### 步骤 2: 填写需求

更新会话 scope：

```json
{
  "site_title": "My Test Site",
  "target_domain": "test.example.com",
  "brief_description": "A test site",
  "default_locale": "en_US",
  "locales": ["en_US", "zh_Hans_CN"],
  "page_types": ["home_page", "about_page", "contact_page"],
  "page_type_layouts": {
    "home_page": {
      "header": {"component": "header-default", "config": {}},
      "content": [
        {"code": "hero-banner", "config": {"title": "Welcome"}},
        {"code": "feature-list", "config": {}}
      ],
      "footer": {"component": "footer-default", "config": {}}
    }
  }
}
```

### 步骤 3: 构建虚拟主题

```bash
POST /admin/pagebuilder/backend/ai-site-agent/post-start-build
```

**执行内容**：
1. 创建草稿 Website（`m_weline_websites_website` 表）
2. 生成虚拟主题（`guolairen_pb_virtual_theme` 表）
3. 生成虚拟页面布局（存储在 scope 中）

**返回**：
- `draft_website_id`: 草稿站点 ID
- `virtual_theme_id`: 虚拟主题 ID
- `execution_token`: 执行令牌（用于 SSE 流）

### 步骤 4: 发布上线

```bash
POST /admin/pagebuilder/backend/ai-site-agent/post-start-publish
```

**执行内容**：
1. 物化页面（创建 `guolairen_pb_page` 记录）
2. 激活虚拟主题（`is_active = 1`）
3. 绑定到 Website

**返回**：
- `materialized_pages_by_type`: 已创建的页面列表
- `preview_full_url`: 预览完整 URL
- `published_at`: 发布时间

### 步骤 5: 访问域名

**通过 Website URL 访问**：
```
http://{target_domain}/{page_handle}
```

**或通过框架命令测试**：
```bash
php bin/w http:request '{page_handle}' --website-id={website_id}
```

## 运行 E2E 测试

### 自动化测试

```bash
php bin/w phpunit:run --module=GuoLaiRen_PageBuilder --filter=AiSiteFullFlowTest
```

### 手动测试流程

1. **创建会话**：
```bash
curl -X POST http://localhost/admin/pagebuilder/backend/ai-site-agent/post-create-session \
  -H "Cookie: WELINE_SESSID=your_session_id" \
  -H "Content-Type: application/json"
```

2. **填写需求**：
```bash
curl -X POST http://localhost/admin/pagebuilder/backend/ai-site-agent/post-replace-scope \
  -H "Cookie: WELINE_SESSID=your_session_id" \
  -H "Content-Type: application/json" \
  -d '{
    "public_id": "your_public_id",
    "scope": {
      "site_title": "My Test Site",
      "target_domain": "test.local.test",
      "page_types": ["home_page", "about_page"]
    }
  }'
```

3. **构建虚拟主题**：
```bash
curl -X POST http://localhost/admin/pagebuilder/backend/ai-site-agent/post-start-build \
  -H "Cookie: WELINE_SESSID=your_session_id" \
  -H "Content-Type: application/json" \
  -d '{
    "public_id": "your_public_id"
  }'
```

4. **发布上线**：
```bash
curl -X POST http://localhost/admin/pagebuilder/backend/ai-site-agent/post-start-publish \
  -H "Cookie: WELINE_SESSID=your_session_id" \
  -H "Content-Type: application/json" \
  -d '{
    "public_id": "your_public_id"
  }'
```

5. **访问网站**：
```bash
# 方式 1: 通过框架命令
php bin/w http:request 'home' --website-id=1

# 方式 2: 通过浏览器（需配置 hosts）
# 在 /etc/hosts 或 C:\Windows\System32\drivers\etc\hosts 添加：
# 127.0.0.1 test.local.test
# 然后访问：http://test.local.test
```

## 验收标准

✅ **测试通过条件**：

1. 会话创建成功，返回 `public_id`
2. Scope 更新成功，数据持久化
3. 构建成功，创建了 Website 和 VirtualTheme
4. 发布成功，创建了 PageBuilder Page 记录
5. 虚拟主题已激活（`is_active = 1`）
6. 页面状态为已发布（`status = 'published'`）
7. **能通过 Website URL 访问到首页**（关键验收点）

## 数据库验证

### 验证 Website 已创建

```sql
SELECT website_id, name, url, scope
FROM m_weline_websites_website
WHERE scope = 'page_builder'
ORDER BY website_id DESC
LIMIT 5;
```

### 验证虚拟主题已激活

```sql
SELECT virtual_theme_id, name, website_id, is_active, session_id
FROM guolairen_pb_virtual_theme
ORDER BY virtual_theme_id DESC
LIMIT 5;
```

### 验证页面已发布

```sql
SELECT page_id, website_id, type, name, handle, status
FROM guolairen_pb_page
WHERE website_id = {your_website_id}
ORDER BY page_id DESC;
```

## 常见问题

### Q1: Website name 唯一约束冲突

**问题**：`duplicate key value violates unique constraint "uk_name"`

**解决**：每次测试使用唯一的网站名称（如添加时间戳）

```php
$siteName = 'E2E Test Site ' . time();
```

### Q2: 虚拟主题未激活

**问题**：`is_active = 0`

**解决**：确保 `AiSitePublishService::publish()` 中调用了 `setIsActive(true)`

### Q3: 无法访问域名

**问题**：404 Not Found

**排查**：
1. 检查 Website URL 是否正确
2. 检查 Page handle 是否正确
3. 检查 Page status 是否为 'published'
4. 检查路由是否注册（`php bin/w setup:upgrade --route`）

## 测试数据清理

测试完成后自动清理：

```php
// 删除页面
$page->clearData()->clearQuery()
    ->where('website_id', $websiteId)
    ->delete();

// 删除虚拟主题
$virtualTheme->clearData()->clearQuery()
    ->load($virtualThemeId)
    ->delete();

// 删除 Website
$website->clearData()->clearQuery()
    ->load($websiteId)
    ->delete();
```

## 性能指标

- 创建会话：< 100ms
- 构建虚拟主题：< 2s
- 发布上线：< 3s
- 总流程：< 5s

## 相关文件

- E2E 测试：`test/E2E/AiSiteFullFlowTest.php`
- 会话服务：`Service/AiSiteAgentSessionService.php`
- 草稿站点服务：`Service/AiSiteDraftWebsiteService.php`
- 虚拟主题服务：`Service/AiSiteVirtualThemeService.php`
- 发布服务：`Service/AiSitePublishService.php`
- 物化服务：`Service/AiSiteMaterializationService.php`
