# Sitemap Nginx 配置修复

## 问题描述

访问 sitemap.xml 文件时返回 502 错误：
```
http://my.com:9981/sitemaps/my/sitemap.xml → 502 Bad Gateway
```

## 问题原因

### 1. 文件位置

sitemap.xml 文件实际保存在：
```
$WELINE_ROOT/pub/sitemaps/{website_code}/sitemap.xml
```

例如：
```
E:/WelineFramework/DEV-workspace/pub/sitemaps/my/sitemap.xml
```

### 2. Nginx 配置问题

**原配置：**
```nginx
root $WELINE_ROOT;  # 项目根目录

location / {
    if (!-e $request_filename) {
        rewrite ^/(.*)$ /index.php?s=$1 last;
    }
}
```

**问题分析：**

1. 访问 `/sitemaps/my/sitemap.xml` 时
2. Nginx 在 `$WELINE_ROOT/sitemaps/my/sitemap.xml` 查找文件
3. 但实际文件在 `$WELINE_ROOT/pub/sitemaps/my/sitemap.xml`
4. 文件找不到，被 rewrite 规则重定向到 PHP
5. PHP 无法处理静态 XML 文件，返回 502 错误

## 解决方案

### ✅ 已修复

已在 `nginx.conf` 中添加 `/sitemaps/` 路径的专用配置：

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

## 应用修复

### 方法 1：重新加载 Nginx（推荐）

```bash
# Windows (以管理员身份运行)
nginx -t                  # 测试配置
nginx -s reload           # 重新加载配置

# Linux/Mac
sudo nginx -t
sudo nginx -s reload

# 或者
sudo systemctl reload nginx
```

### 方法 2：重启 Nginx

```bash
# Windows
nginx -s stop
nginx

# Linux/Mac
sudo systemctl restart nginx
```

### 方法 3：如果使用 Docker

Docker 环境不需要修改，`docker/nginx.conf` 已经正确配置：
```nginx
root /var/www/html/pub;  # 直接指向 pub 目录
```

## 验证修复

### 1. 检查文件是否存在

```bash
# PowerShell
Get-ChildItem -Recurse pub\sitemaps

# Linux/Mac
ls -la pub/sitemaps/*/sitemap.xml
```

### 2. 浏览器访问测试

```
http://my.com:9981/sitemaps/my/sitemap.xml
http://my.com:9981/sitemaps/default/sitemap.xml
```

**预期结果：**
- 状态码：200 OK
- Content-Type: application/xml
- 显示 XML 内容

### 3. curl 测试

```bash
curl -I http://my.com:9981/sitemaps/my/sitemap.xml

# 预期输出
HTTP/1.1 200 OK
Content-Type: application/xml
Cache-Control: public, must-revalidate
```

## 配置对比

### ❌ 修复前

| 请求 | Nginx 查找路径 | 结果 |
|------|---------------|------|
| `/sitemaps/my/sitemap.xml` | `$WELINE_ROOT/sitemaps/my/sitemap.xml` | ❌ 文件不存在 → rewrite 到 PHP → 502 |

### ✅ 修复后

| 请求 | Nginx 查找路径 | 结果 |
|------|---------------|------|
| `/sitemaps/my/sitemap.xml` | `$WELINE_ROOT/pub/sitemaps/my/sitemap.xml` | ✅ 文件存在 → 直接返回 |

## 其他相关配置

### robots.txt

确保 robots.txt 中包含 sitemap 引用：

```txt
# robots.txt
Sitemap: http://my.com:9981/sitemaps/my/sitemap.xml
Sitemap: http://my.com:9981/sitemaps/default/sitemap.xml
```

### 提交到搜索引擎

修复后，可以通过以下方式提交：

1. **Google Search Console**
   - 登录 https://search.google.com/search-console
   - 添加 sitemap URL

2. **Bing Webmaster Tools**
   - 登录 https://www.bing.com/webmasters
   - 提交 sitemap URL

3. **自动提交（已配置）**
   - 后台 → SEO管理 → 账户管理
   - 配置 SEO 账户并启用 Cron Sitemap
   - 系统每天凌晨 3:00 自动提交

## 常见问题

### Q1: 修改后仍然 502？

**检查清单：**
- [ ] 是否重新加载了 nginx？
- [ ] nginx 配置测试是否通过？(`nginx -t`)
- [ ] 文件是否真的存在？
- [ ] 文件权限是否正确？(644)

### Q2: 显示 404？

**可能原因：**
- sitemap 文件还未生成
- website_code 不正确

**解决方法：**
```bash
# 重新生成 sitemap
php test_pagebuilder_sitemap.php

# 或访问后台
# SEO管理 → Sitemap管理 → 调用 PageBuilder 生成器
```

### Q3: Docker 环境也需要修改吗？

**不需要**。Docker 的 nginx.conf 已经正确配置：
```nginx
root /var/www/html/pub;
```

### Q4: 为什么不在 SitemapService 中改 URL？

**设计原则：**
- URL 应该遵循标准 Web 规范：`/sitemaps/xxx/sitemap.xml`
- `pub` 目录是 Web 服务器的实现细节，不应该暴露在 URL 中
- 通过 nginx 配置解决路径映射问题是更优雅的方案

## 性能优化

修复后的配置包含以下优化：

### 1. 缓存控制

```nginx
expires modified 1d;
add_header Cache-Control "public, must-revalidate";
```

- XML 文件缓存 1 天
- 减少服务器负载
- 加快后续访问速度

### 2. Gzip 压缩

确保 nginx 主配置中启用了 XML 压缩：

```nginx
gzip_types text/xml application/xml;
```

### 3. 直接文件访问

静态文件由 nginx 直接提供，不经过 PHP：
- 响应速度更快
- 节省 PHP-FPM 资源

## 总结

### 修复内容

✅ 在 `nginx.conf` 中添加 `/sitemaps/` location 配置  
✅ 设置正确的 root 路径为 `$WELINE_ROOT/pub`  
✅ 配置 XML 文件缓存  

### 需要做的

⚠️ **重新加载 nginx 配置**：`nginx -s reload`

### 验证结果

访问：`http://my.com:9981/sitemaps/my/sitemap.xml`  
预期：200 OK，显示 XML 内容

---

**文档创建时间：** 2026-01-30  
**最后更新：** 2026-01-30
