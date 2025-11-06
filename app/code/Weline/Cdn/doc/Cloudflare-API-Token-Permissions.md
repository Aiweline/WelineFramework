# Cloudflare API Token 权限配置指南

## 概述

在使用 Weline_Cdn 模块的 Cloudflare 适配器时，需要创建一个具有适当权限的 API Token。本指南将详细说明需要配置的权限。

## 必需权限

根据代码中使用的 API 端点，Cloudflare API Token 需要以下权限：

### 1. Zone 读取权限
- **权限名称**：`Zone:Read`
- **用途**：用于搜索和获取 Zone 信息
- **API 端点**：
  - `GET /zones` - 搜索域名对应的 Zone
  - `GET /zones/{zone_id}` - 获取 Zone 详细信息

### 2. 缓存清理权限
- **权限名称**：`Zone:Cache Purge:Edit`
- **用途**：用于清理 CDN 缓存
- **API 端点**：
  - `POST /zones/{zone_id}/purge_cache` - 清理缓存（支持全部、URL、Host、Tag、Prefixes）

### 3. Cache Rules 读取权限
- **权限名称**：`Zone:Cache Rules:Read`
- **用途**：用于读取缓存规则
- **API 端点**：
  - `GET /zones/{zone_id}/rulesets/phases/http_request_cache_settings/entrypoint` - 获取缓存规则

### 4. Cache Rules 编辑权限
- **权限名称**：`Zone:Cache Rules:Edit`
- **用途**：用于创建和更新缓存规则
- **API 端点**：
  - `PUT /zones/{zone_id}/rulesets/{ruleset_id}` - 更新缓存规则
  - `POST /zones/{zone_id}/rulesets/phases/http_request_cache_settings/entrypoint` - 创建缓存规则

## 创建 API Token 步骤

### 方式一：使用自定义令牌（推荐）

1. **登录 Cloudflare 控制台**
   - 访问 [Cloudflare 控制台](https://dash.cloudflare.com/)
   - 使用您的账户凭据登录

2. **导航到 API Token 页面**
   - 点击右上角的个人资料图标
   - 选择"我的个人资料"（My Profile）
   - 点击"API 令牌"（API Tokens）选项卡
   - 或直接访问：https://dash.cloudflare.com/profile/api-tokens

3. **创建自定义令牌**
   - 点击"创建令牌"（Create Token）按钮
   - 选择"自定义令牌"（Custom Token）模板

4. **配置权限**
   
   按照以下配置设置权限：

   **权限配置**：
   ```
   Zone:Read
   Zone:Cache Purge:Edit
   Zone:Cache Rules:Read
   Zone:Cache Rules:Edit
   ```

   **资源设置**：
   - 选择"特定区域"（Specific Zone）
   - 选择需要管理的域名（Zone）
   - 或者选择"所有区域"（All Zones）以管理所有域名

5. **创建并保存 Token**
   - 确认权限设置无误后，点击"创建令牌"（Create Token）
   - **重要**：生成的 API Token 只会显示一次，请立即复制并妥善保存

### 方式二：使用预设模板（快捷方式）

Cloudflare 也提供了一些预设模板，但为了安全起见，建议使用自定义令牌：

1. 在 API Token 页面，点击"创建令牌"
2. 选择"编辑 Cloudflare Workers"（Edit Cloudflare Workers）模板
3. 但此模板可能不包含所有需要的权限，建议使用自定义令牌

## 权限详细说明

### Zone:Read
- **功能**：读取 Zone 信息
- **用途**：用于域名管理时查找 Zone ID
- **安全级别**：只读，相对安全

### Zone:Cache Purge:Edit
- **功能**：清理缓存
- **用途**：用于清理全部缓存、按 URL 清理、按 Host 清理等
- **安全级别**：会清除缓存，可能影响网站性能，需谨慎使用

### Zone:Cache Rules:Read
- **功能**：读取缓存规则
- **用途**：用于获取当前的缓存规则配置
- **安全级别**：只读，相对安全

### Zone:Cache Rules:Edit
- **功能**：编辑缓存规则
- **用途**：用于创建和更新 Cache Rules
- **安全级别**：会修改缓存策略，可能影响网站缓存行为，需谨慎使用

## 安全建议

1. **最小权限原则**：只授予必要的权限，避免使用全局权限
2. **特定区域限制**：如果可能，将 Token 限制在特定的 Zone，而不是所有区域
3. **定期轮换**：定期更换 API Token，提高安全性
4. **妥善保管**：不要将 API Token 提交到代码仓库或公开分享
5. **监控使用**：定期检查 API Token 的使用日志，发现异常及时处理

## 在 Weline_Cdn 中配置

1. 进入后台：**CDN管理 > 账户管理**
2. 点击"添加账户"或编辑现有账户
3. 选择适配器：**Cloudflare**
4. 在"API Token"字段中粘贴您创建的 API Token
5. 点击"生成"按钮可以生成一个随机 Token（仅用于测试，实际使用需要从 Cloudflare 获取）
6. 保存账户

## 验证 Token 权限

创建 Token 后，可以通过以下方式验证：

1. **在 Cloudflare 控制台**：
   - 查看 Token 的权限列表
   - 确认包含上述所有必需权限

2. **在 Weline_Cdn 中测试**：
   - 添加域名时，系统会自动查找 Zone ID
   - 如果可以成功获取 Zone ID，说明 Zone:Read 权限正常
   - 尝试清理缓存，如果成功，说明 Zone:Cache Purge:Edit 权限正常
   - 尝试获取或推送规则，如果成功，说明 Cache Rules 相关权限正常

## 常见问题

### Q: 为什么需要这么多权限？
A: Weline_Cdn 模块提供了完整的 CDN 管理功能，包括缓存清理和规则管理，因此需要相应的权限。

### Q: 可以只给部分权限吗？
A: 可以，但功能会受限：
- 只有 Zone:Read 和 Zone:Cache Purge:Edit：只能清理缓存，不能管理规则
- 缺少 Cache Rules 权限：无法使用规则管理功能

### Q: Token 权限不足怎么办？
A: 如果遇到权限错误，请检查：
1. Token 是否包含所有必需权限
2. Token 是否对目标 Zone 有效
3. Token 是否已过期或被撤销

### Q: 如何撤销 Token？
A: 在 Cloudflare 控制台的 API Token 页面，找到对应的 Token，点击"撤销"（Revoke）按钮。

## 参考链接

- [Cloudflare API Token 文档](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/)
- [Cloudflare API 权限列表](https://developers.cloudflare.com/fundamentals/api/get-started/permissions/)
- [Cloudflare Cache Rules API](https://developers.cloudflare.com/cache/how-to/cache-rules/)
- [Cloudflare Cache Purge API](https://developers.cloudflare.com/api/operations/zone-purge-cache-files-by-url)

## 更新日志

- 2024-01-XX：初始版本

