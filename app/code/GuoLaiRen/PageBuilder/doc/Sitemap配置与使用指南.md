# PageBuilder Sitemap 配置与使用指南

## 概述

PageBuilder 已经完整集成了 SEO Sitemap 生成功能，通过 Weline_Seo 模块的扩展点机制实现自动 sitemap 生成和提交。

## 架构说明

### PageBuilder 的工作模式

```
站点 (Website) 1:N 页面 (Page)
├── 站点 ID (website_id)
├── 站点 URL (例如: https://example.com)
└── 页面列表
    ├── 首页 (type=home_page, handle=null, URL: /)
    ├── 关于页面 (type=about_page, handle=about, URL: /about)
    ├── 联系页面 (type=contact_page, handle=contact, URL: /contact)
    ├── 博客列表 (type=blog_list, handle=blog, URL: /blog)
    ├── 博客文章 (type=blog_post, handle=article-slug, URL: /article-slug)
    └── 自定义页面 (type=custom_page, handle=custom, URL: /custom)
```

### 系统架构

```
┌──────────────────────────────────────────────────────────────┐
│                    PageBuilder 模块                           │
├──────────────────────────────────────────────────────────────┤
│  Model/Page.php                    页面模型（关联website_id）  │
│  Service/SitemapService.php         核心业务逻辑              │
│  extends/.../PageBuilderSitemapProvider.php  扩展点实现       │
└──────────────────────────────────────────────────────────────┘
                         ▲
                         │ implements SitemapProviderInterface
                         │
┌──────────────────────────────────────────────────────────────┐
│                      Weline_Seo 模块                          │
├──────────────────────────────────────────────────────────────┤
│  Interface/SitemapProviderInterface    定义标准接口           │
│  Service/SitemapRegistryService        自动发现Provider       │
│  Cron/SitemapSubmit                    定时任务（每天3点）     │
│  Controller/Backend/Sitemap            后台管理界面           │
└──────────────────────────────────────────────────────────────┘
```

## 核心组件

### 1. SitemapService (业务逻辑层)

路径: `app/code/GuoLaiRen/PageBuilder/Service/SitemapService.php`

**职责：**
- 按站点生成 sitemap.xml 文件
- 收集已发布页面的 URL
- 根据页面类型设置优先级和更新频率
- 生成符合标准的 XML 格式

**主要方法：**

```php
// 为指定站点生成 Sitemap
public function generateForWebsite(int $websiteId): ?string

// 为所有站点生成 Sitemap
public function generateForAllWebsites(): array

// 获取站点的 Sitemap URL
public function getSitemapUrl(int $websiteId): ?string
```

**优先级和更新频率配置：**

| 页面类型 | 优先级 | 更新频率 |
|---------|--------|---------|
| 首页 (home_page) | 1.0 | daily |
| 关于/联系页面 | 0.8 | monthly |
| 博客列表 (blog_list) | 0.7 | daily |
| 博客文章 (blog) | 0.6 | weekly |
| 政策页面 (privacy/terms等) | 0.2-0.3 | monthly |
| 自定义页面 | 0.5 | monthly |

### 2. PageBuilderSitemapProvider (扩展点实现)

路径: `app/code/GuoLaiRen/PageBuilder/extends/module/Weline_Seo/SitemapProvider/PageBuilderSitemapProvider.php`

**职责：**
- 实现 SitemapProviderInterface 接口
- 被 SEO 模块自动发现和注册
- 提供 scope 和 module 标识

**配置信息：**
- **Scope**: `page_builder`
- **Module**: `GuoLaiRen_PageBuilder`
- **Description**: PageBuilder 页面构建器 Sitemap 生成器

## 文件存储规范

### 目录结构

```
pub/
├── sitemap.xml                              # 主索引文件
└── sitemaps/                                # 模块生成目录
    ├── {website_code_1}/
    │   └── sitemap.xml                      # 站点1的sitemap
    ├── {website_code_2}/
    │   └── sitemap.xml                      # 站点2的sitemap
    └── ...
```

### 示例

假设有两个站点：
- 站点1: code=`main_site`, URL=`https://example.com`
- 站点2: code=`blog_site`, URL=`https://blog.example.com`

生成的文件：
```
pub/sitemaps/main_site/sitemap.xml      → https://example.com/sitemaps/main_site/sitemap.xml
pub/sitemaps/blog_site/sitemap.xml      → https://blog.example.com/sitemaps/blog_site/sitemap.xml
```

## 使用方式

### 方式一：自动生成（定时任务）

**步骤1：配置 SEO 账户**

访问后台：**SEO管理 > 账户管理 > 新增账户**

配置项：
- **Provider**: 选择搜索引擎适配器（如 `google_indexing_api`）
- **Scope**: 填写 `page_builder`
- **Module**: 填写 `GuoLaiRen_PageBuilder`
- **启用定时提交**: 勾选 ✓
- **启用Cron Sitemap**: 勾选 ✓
- **配置信息**: 填写搜索引擎 API 凭据

**步骤2：等待自动执行**

定时任务每天凌晨 3:00 自动执行：
1. 调用 `PageBuilderSitemapProvider` 生成 sitemap
2. 为所有站点生成 sitemap.xml 文件
3. 使用配置的适配器提交到搜索引擎

**查看执行日志：**
```bash
# 查看 Cron 日志
tail -f var/log/cron.log

# 手动触发（测试用）
php bin/weline cron:run seo_sitemap_submit
```

### 方式二：后台手动生成

访问后台：**SEO管理 > Sitemap管理**

#### 操作选项：

**1. 查看注册的生成器**
- 显示所有已注册的 SitemapProvider
- 查看 PageBuilder 生成器的状态

**2. 使用 Provider 生成**
- 点击 "调用 PageBuilder 生成器"
- 自动为所有站点生成 sitemap
- 显示生成的 URL 列表

**3. 调用所有生成器**
- 一次性调用所有已注册的 Provider
- 批量生成所有模块的 sitemap

**4. 查看生成的文件**
- 按站点查看已生成的 sitemap 文件
- 显示文件大小、修改时间
- 提供下载和预览链接

### 方式三：程序化调用

```php
use Weline\Framework\Manager\ObjectManager;
use GuoLaiRen\PageBuilder\Service\SitemapService;

// 为指定站点生成
$sitemapService = ObjectManager::getInstance(SitemapService::class);
$sitemapUrl = $sitemapService->generateForWebsite(1);
echo "Sitemap URL: " . $sitemapUrl;

// 为所有站点生成
$sitemapUrls = $sitemapService->generateForAllWebsites();
foreach ($sitemapUrls as $url) {
    echo "Generated: " . $url . "\n";
}

// 获取现有的 Sitemap URL
$existingUrl = $sitemapService->getSitemapUrl(1);
if ($existingUrl) {
    echo "Existing Sitemap: " . $existingUrl;
}
```

## 配置验证

### 1. 检查 Provider 注册状态

访问后台：**SEO管理 > Sitemap管理**

在 "Sitemap 生成器" 面板中应该能看到：
```
Scope: page_builder
Module: GuoLaiRen_PageBuilder
Description: PageBuilder 页面构建器 Sitemap 生成器
```

### 2. 测试生成功能

**方法1：使用后台界面**
1. 进入 **SEO管理 > Sitemap管理**
2. 点击 "调用 PageBuilder 生成器"
3. 查看返回结果，应该显示成功生成的文件数量

**方法2：使用命令行**
```bash
# 创建测试脚本
cat > test_sitemap.php << 'EOF'
<?php
require 'app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use GuoLaiRen\PageBuilder\Service\SitemapService;

$service = ObjectManager::getInstance(SitemapService::class);
$urls = $service->generateForAllWebsites();

echo "生成的 Sitemap URL:\n";
foreach ($urls as $url) {
    echo "- {$url}\n";
}
EOF

# 执行测试
php test_sitemap.php
```

### 3. 验证生成的文件

```bash
# 检查目录结构
ls -la pub/sitemaps/

# 查看某个站点的 sitemap 内容
cat pub/sitemaps/{website_code}/sitemap.xml

# 验证 XML 格式
xmllint --noout pub/sitemaps/{website_code}/sitemap.xml && echo "格式正确"
```

### 4. 测试页面 URL 生成

访问测试站点页面，检查 URL 是否正确：
- 首页: `https://example.com/`
- 关于页面: `https://example.com/about`
- 博客文章: `https://example.com/blog-article-slug`

## Nginx 配置（重要！）

### ⚠️ 访问 sitemap.xml 返回 502 错误

如果访问 `http://your-site.com/sitemaps/{website_code}/sitemap.xml` 时返回 **502 Bad Gateway** 错误，需要配置 nginx。

**问题原因：**

sitemap.xml 文件保存在 `pub/sitemaps/` 目录，但默认 nginx 配置的 root 指向项目根目录，导致文件找不到，被重定向到 PHP，返回 502 错误。

### 解决方案（已自动修复）

在 `nginx.conf` 中添加以下配置：

```nginx
# Sitemap 文件访问
location ~ ^/sitemaps/
{
    root $WELINE_ROOT/pub;
    # XML 文件缓存设置
    location ~ .*\.xml$
    {
        expires modified 1d;
        add_header Cache-Control "public, must-revalidate";
    }
}
```

**配置说明：**
- `location ~ ^/sitemaps/`：匹配以 `/sitemaps/` 开头的请求
- `root $WELINE_ROOT/pub`：将根目录设置为 `pub` 目录
- 访问 `/sitemaps/my/sitemap.xml` → 实际读取 `$WELINE_ROOT/pub/sitemaps/my/sitemap.xml`
- 设置 1 天缓存，减轻服务器负载

### 应用配置

```bash
# 1. 测试配置是否正确
nginx -t

# 2. 重新加载配置（推荐）
nginx -s reload

# 或者重启 nginx
nginx -s stop && nginx

# Linux/Mac
sudo systemctl reload nginx
```

### 验证修复

```bash
# 测试访问
curl -I http://your-site.com/sitemaps/my/sitemap.xml

# 预期输出
HTTP/1.1 200 OK
Content-Type: application/xml
Cache-Control: public, must-revalidate
```

**详细文档：** 参见 `doc/Sitemap-Nginx配置修复.md`

---

## 常见问题

### 1. Sitemap 为空或没有生成

**原因：**
- 站点下没有已发布的页面
- 页面的 `status` 不是 `STATUS_PUBLISHED (1)`
- 页面没有关联 `website_id`

**解决方案：**
```sql
-- 检查页面状态
SELECT page_id, name, handle, type, status, website_id 
FROM guolairen_page_builder_page;

-- 发布页面
UPDATE guolairen_page_builder_page 
SET status = 1 
WHERE page_id = ?;
```

### 2. Provider 没有被发现

**原因：**
- Extends 扫描缓存未更新

**解决方案：**
```bash
# 清除扩展缓存
rm -rf var/cache/extends/

# 刷新扩展注册
php bin/weline framework:extends-scan
```

### 3. 定时任务未执行

**原因：**
- Cron 未配置或未启用
- SEO 账户未启用或配置错误

**解决方案：**
```bash
# 检查 Cron 配置
crontab -l

# 手动触发测试
php bin/weline cron:run seo_sitemap_submit

# 检查 SEO 账户
SELECT * FROM weline_seo_account 
WHERE scope = 'page_builder' 
AND module = 'GuoLaiRen_PageBuilder';
```

### 4. 文件权限问题

**错误信息：**
```
failed to open stream: Permission denied
```

**解决方案：**
```bash
# 设置正确的权限
chmod -R 755 pub/sitemaps/
chown -R www-data:www-data pub/sitemaps/
```

## SEO 最佳实践

### 1. 提交到搜索引擎

**Google Search Console:**
1. 登录 [Google Search Console](https://search.google.com/search-console)
2. 选择对应的网站
3. 左侧菜单：站点地图 → 添加新的站点地图
4. 输入: `sitemaps/{website_code}/sitemap.xml`
5. 点击提交

**Bing Webmaster Tools:**
1. 登录 [Bing Webmaster Tools](https://www.bing.com/webmasters)
2. 站点地图 → 提交站点地图
3. 输入完整 URL

### 2. robots.txt 配置

在 `pub/robots.txt` 中添加：
```
User-agent: *
Allow: /

# Sitemap 位置
Sitemap: https://example.com/sitemaps/main_site/sitemap.xml
Sitemap: https://example.com/sitemaps/blog_site/sitemap.xml
```

### 3. 更新频率建议

- **首页/博客列表**: 每天更新内容时重新生成
- **博客文章**: 发布或修改时重新生成
- **静态页面**: 每周或每月生成一次即可

### 4. 监控和维护

定期检查：
- Sitemap 文件是否可访问
- URL 数量是否正常
- 搜索引擎抓取状态
- 404 错误页面

## 技术细节

### 页面状态和 SEO 集成

PageBuilder 的 `Page` 模型实现了自动 SEO 集成：

```php
// 保存后钩子 - 自动提交已发布页面的 URL 到 SEO 模块
public function save_after(): void
{
    // 仅当页面为已发布状态时，提交 URL 到 SEO 模块
    if ($this->getData('status') === self::STATUS_PUBLISHED) {
        $urlSubmitService->requestSubmit(
            $this->getFullUrl(),
            'page_builder',
            'GuoLaiRen_PageBuilder',
            ['subject_id' => $this->getId()]
        );
    }
}
```

这意味着：
1. 发布页面时自动提交 URL 到 SEO 模块
2. SEO 模块会根据配置自动提交到搜索引擎
3. 无需手动操作，实现完全自动化

### 扩展点机制

PageBuilder 通过 extends 扩展点机制注册到 SEO 模块：

**目录结构：**
```
app/code/GuoLaiRen/PageBuilder/
└── extends/
    └── module/
        └── Weline_Seo/
            └── SitemapProvider/
                └── PageBuilderSitemapProvider.php
```

**自动发现流程：**
1. SEO 模块扫描所有模块的 `extends/module/Weline_Seo/` 目录
2. 发现实现 `SitemapProviderInterface` 的类
3. 注册到 `SitemapRegistryService`
4. 定时任务或手动操作时自动调用

## 相关文件

- `Model/Page.php` - 页面模型
- `Service/SitemapService.php` - Sitemap 生成服务
- `extends/module/Weline_Seo/SitemapProvider/PageBuilderSitemapProvider.php` - 扩展点实现
- `doc/Sitemap自动生成与SEO集成.md` - 集成说明文档

## 参考资源

- [Sitemap 协议规范](https://www.sitemaps.org/protocol.html)
- [Google 站点地图指南](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview)
- [Weline SEO 模块文档](../../Weline/Seo/doc/Sitemap扩展开发指南.md)
