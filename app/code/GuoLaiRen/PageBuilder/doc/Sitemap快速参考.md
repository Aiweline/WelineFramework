# PageBuilder Sitemap 快速参考

## 系统状态

✅ **已完成配置** - PageBuilder 的 Sitemap 生成功能已完全配置并集成

## 工作原理

```
PageBuilder 页面 (按 website_id 分组)
         ↓
   SitemapService 生成 XML
         ↓
    pub/sitemaps/{website_code}/sitemap.xml
         ↓
   自动提交到搜索引擎 (通过 SEO 模块)
```

## 快速测试

```bash
# 1. 运行测试脚本
php test_pagebuilder_sitemap.php

# 2. 检查生成的文件
ls -la pub/sitemaps/

# 3. 查看内容
cat pub/sitemaps/{website_code}/sitemap.xml
```

## 三种使用方式

### 1️⃣ 自动生成（推荐）

**配置路径：** 后台 → SEO管理 → 账户管理

```
Provider: google_indexing_api (或其他)
Scope: page_builder
Module: GuoLaiRen_PageBuilder
启用定时提交: ✓
启用Cron Sitemap: ✓
```

**执行时间：** 每天凌晨 3:00

### 2️⃣ 后台手动生成

**操作路径：** 后台 → SEO管理 → Sitemap管理

点击 "调用 PageBuilder 生成器" 按钮

### 3️⃣ 代码调用

```php
use GuoLaiRen\PageBuilder\Service\SitemapService;

$service = ObjectManager::getInstance(SitemapService::class);

// 为所有站点生成
$urls = $service->generateForAllWebsites();

// 为指定站点生成
$url = $service->generateForWebsite($websiteId);
```

## 核心文件

| 文件 | 说明 |
|------|------|
| `Service/SitemapService.php` | 业务逻辑 |
| `extends/.../PageBuilderSitemapProvider.php` | SEO 扩展点 |
| `Model/Page.php::save_after()` | 自动 URL 提交 |

## 页面要求

要在 Sitemap 中显示，页面必须：
- ✅ `status = 1` (已发布)
- ✅ 关联 `website_id`
- ✅ 有效的 `handle` (非首页) 或 `type = home_page` (首页)

## URL 格式

| 页面类型 | Handle | URL |
|---------|--------|-----|
| 首页 | null | `/` |
| 关于页面 | about | `/about` |
| 博客文章 | my-article | `/my-article` |

## 优先级配置

| 页面类型 | 优先级 | 更新频率 |
|---------|--------|---------|
| 首页 | 1.0 | daily |
| 关于/联系 | 0.8 | monthly |
| 博客列表 | 0.7 | daily |
| 博客文章 | 0.6 | weekly |

## 常见命令

```bash
# 刷新扩展注册
php bin/weline framework:extends-scan

# 手动触发定时任务
php bin/weline cron:run seo_sitemap_submit

# 查看定时任务日志
tail -f var/log/cron.log

# 设置文件权限
chmod -R 755 pub/sitemaps/
```

## 验证清单

- [ ] 运行测试脚本无错误
- [ ] 后台能看到 PageBuilder Provider
- [ ] 生成的文件存在且格式正确
- [ ] URL 可以访问
- [ ] SEO 账户已配置（如需自动提交）
- [ ] robots.txt 已添加 Sitemap 位置

## 提交到搜索引擎

**Google Search Console:**
- URL: `https://your-site.com/sitemaps/{website_code}/sitemap.xml`

**Bing Webmaster:**
- URL: `https://your-site.com/sitemaps/{website_code}/sitemap.xml`

## 故障排查

| 问题 | 解决方案 |
|------|---------|
| **访问返回 502** | 🔧 Nginx 配置问题，详见 `Sitemap-Nginx配置修复.md`<br>执行 `nginx -s reload` 应用修复 |
| Sitemap 为空 | 检查是否有已发布的页面 |
| Provider 未注册 | 执行 `extends-scan` 刷新 |
| 文件权限错误 | `chmod -R 755 pub/sitemaps/` |
| 定时任务未执行 | 检查 Cron 配置和 SEO 账户 |

## 更多信息

详细文档：`doc/Sitemap配置与使用指南.md`
