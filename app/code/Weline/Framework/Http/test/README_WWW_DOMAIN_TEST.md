# WWW 和非 WWW 域名测试指南

## 问题描述

在生产环境中，当网站配置的 URL 是 `example.com`，但用户访问 `www.example.com` 时（或反之），会导致：
1. 网站匹配失败
2. 缓存键不匹配
3. URL 解析问题

## 修复内容

### 1. 添加域名规范化功能

在 `Url` 类中添加了两个新方法：
- `normalizeHost()`: 规范化域名（移除或添加 www 前缀）
- `isHostMatch()`: 检查两个 URL 的主机名是否匹配（考虑 www 和非 www 的情况）

### 2. 修复网站匹配逻辑

在 `Url::parser()` 方法中，添加了域名匹配逻辑：
- 首先尝试精确匹配（原有逻辑）
- 如果精确匹配失败，使用 `isHostMatch()` 进行域名匹配
- 这样即使网站配置为 `example.com`，访问 `www.example.com` 也能正确匹配

### 3. 优化缓存键生成

在 `RequestCache::getDomainKey()` 方法中：
- 优先使用网站代码（如果网站代码相同，www 和非 www 会使用相同的缓存键）
- 如果没有网站代码，使用规范化后的域名（移除 www 前缀）

## 测试方法

### 方法1：使用 hosts 文件测试

1. **编辑 hosts 文件**
   - Windows: `C:\Windows\System32\drivers\etc\hosts`
   - Linux/Mac: `/etc/hosts`
   
   添加以下内容：
   ```
   127.0.0.1 test.example.com
   127.0.0.1 www.test.example.com
   ```

2. **配置网站**
   - 在后台配置网站 URL 为 `http://test.example.com` 或 `http://www.test.example.com`

3. **测试访问**
   - 访问 `http://test.example.com/test`
   - 访问 `http://www.test.example.com/test`
   - 两者都应该能正常工作

### 方法2：运行测试脚本

```bash
php app/code/Weline/Framework/Http/test/test_www_domain.php
```

### 方法3：运行单元测试

```bash
php bin/w test Weline\\Framework\\Http\\Test\\WwwDomainTest
```

## 测试场景

### 场景1：网站配置为 example.com，访问 www.example.com
- **预期结果**: 能够正确匹配网站，REQUEST_URI 不包含域名

### 场景2：网站配置为 www.example.com，访问 example.com
- **预期结果**: 能够正确匹配网站，REQUEST_URI 不包含域名

### 场景3：HTTPS 协议下的匹配
- **预期结果**: HTTPS 下也能正确匹配

### 场景4：带端口的域名匹配
- **预期结果**: 带端口时也能正确匹配

### 场景5：子域名不应该被误匹配
- **预期结果**: `api.example.com` 不应该匹配到 `example.com` 的配置

## 注意事项

1. **子域名处理**: 本修复只处理 www 和非 www 的情况，子域名（如 `api.example.com`）不会被误匹配
2. **协议和端口**: 匹配时会检查协议（http/https）和端口是否一致
3. **缓存键**: 如果网站代码相同，www 和非 www 的域名会使用相同的缓存键，这有助于缓存共享

## 相关文件

- `app/code/Weline/Framework/Http/Url.php` - URL 解析和域名匹配逻辑
- `app/code/Weline/Framework/Http/Cache/RequestCache.php` - 缓存键生成逻辑
- `app/code/Weline/Framework/Http/test/WwwDomainTest.php` - 单元测试
- `app/code/Weline/Framework/Http/test/test_www_domain.php` - 测试脚本

## 生产环境部署建议

1. **清理缓存**: 部署后建议清理相关缓存
   ```bash
   php bin/w cache:clear
   ```

2. **验证配置**: 确保网站配置中的 URL 格式正确

3. **监控日志**: 部署后监控错误日志，确保没有异常

