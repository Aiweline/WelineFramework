# Weline_Websites 域名管理功能扩展 - 任务清单

> 最后更新：2026-02-27  
> 关联计划：[plan.md](./plan.md)

## 任务状态说明

- `[ ]` 待办 / pending
- `[-]` 进行中 / in_progress
- `[x]` 已完成 / done

---

## 阶段〇：代码现状修正（优先）

> ⚠️ **此阶段为最高优先级**，修正当前代码与计划目标的偏差

### 0.1 DomainPool 模型扩展（🔴 高优先级）

当前 DomainPool 模型字段不足，需要补充以下字段：

- [ ] **DomainPool 模型字段新增** `Model/DomainPool.php`
  - [ ] 新增 `parent_domain_id` 字段（INT，关联 Domain.domain_id）
  - [ ] 新增 `resolve_status` 字段（VARCHAR(20)，默认 pending）
  - [ ] 新增 `resolved_ip` 字段（VARCHAR(45)）
  - [ ] 新增 `resolved_ipv6` 字段（VARCHAR(45)）
  - [ ] 新增 `is_local_server` 字段（TINYINT(1)，默认 0）
  - [ ] 新增 `resolve_checked_at` 字段（DATETIME）
  - [ ] 新增 `resolve_error` 字段（TEXT）
  - [ ] 新增 `https_status` 字段（VARCHAR(20)，默认 none）
  - [ ] 新增 `https_expires_at` 字段（DATE）
  - [ ] 新增 `https_error` 字段（TEXT）
  - [ ] 新增 `cert_id` 字段（INT，关联证书表）
  - [ ] 新增 `site_ready` 字段（TINYINT(1)，默认 0）
  - [ ] 编写 upgrade() 方法（使用 hasField 检测）
  - [ ] 编写所有新字段的 Getter/Setter 方法

### 0.2 DomainPool API 修正（🔴 高优先级）

当前 `Controller/Backend/Api/DomainPool.php` 注入的是 `Domain` 模型，需修正为 `DomainPool`：

- [ ] **DomainPool API 控制器修正** `Controller/Backend/Api/DomainPool.php`
  - [ ] 将注入的 `Domain $domainModel` 改为 `DomainPool $poolModel`
  - [ ] 修改所有查询逻辑，从 `Domain` 表改为 `DomainPool` 表
  - [ ] 返回字段增加 `site_ready`、`resolve_status`、`https_status` 等

### 0.3 DomainResolveService 重构（🟡 中优先级）

当前 Service 操作 `Domain` 模型，需改为操作 `DomainPool`：

- [ ] **DomainResolveService 重构** `Service/DomainResolveService.php`
  - [ ] 将 `checkResolve(Domain $domain)` 改为 `checkPoolResolve(DomainPool $pool)`
  - [ ] 将 `autoResolveToLocal(Domain $domain)` 改为 `autoResolvePoolToLocal(DomainPool $pool)`
  - [ ] 新增 `batchCheckPoolResolve(array $poolIds): array`
  - [ ] 保留旧方法作为 @deprecated，内部调用新方法（兼容迁移）

### 0.4 Domain 模型清理（🟡 中优先级）

当前 `Domain` 模型包含了应属于 `DomainPool` 的字段，需清理：

- [ ] **Domain 模型字段清理** `Model/Domain.php`
  - [ ] 标记以下字段为 @deprecated（暂不删除，等迁移完成）：
    - `resolve_status`
    - `resolved_ip`
    - `https_status`
    - `site_ready`
  - [ ] 确保 `dns_provider`、`cdn_provider` 等根域相关字段保留
  - [ ] 在文档中明确说明：根域只负责 NS 归属检测

### 0.5 废弃旧 Cron 任务（🟡 中优先级）

- [ ] **废弃 DomainResolveCheck** `Cron/DomainResolveCheck.php`
  - [ ] 在类文档中标记 `@deprecated`
  - [ ] 添加注释说明：逻辑已迁移到 `DomainPoolResolveCheck`
  - [ ] 从 `cron.php` 中移除注册（或注释掉）

### 0.6 DomainAutoResolve 重构（🟡 中优先级）

- [ ] **DomainAutoResolve 重构** `Cron/DomainAutoResolve.php`
  - [ ] 将查询从 `Domain` 表改为 `DomainPool` 表
  - [ ] 更新解析逻辑以使用 `DomainPool` 字段
  - [ ] 确保 `parent_domain_id` 关联正确用于获取注册商信息

### 0.7 新增 Cron 任务（🟢 新增）

- [ ] **DomainPoolResolveCheck 创建** `Cron/DomainPoolResolveCheck.php`
  - [ ] 基于旧 `DomainResolveCheck` 逻辑改造
  - [ ] 查询 `DomainPool` 而非 `Domain`
  - [ ] 更新 `DomainPool` 的 `resolve_status`、`resolved_ip`、`is_local_server`
  - [ ] 自动计算 `site_ready`

- [ ] **DomainNsCheck 创建** `Cron/DomainNsCheck.php`
  - [ ] 检测 `Domain` 根域的 NS 归属
  - [ ] 更新 `Domain.dns_provider`
  - [ ] 不涉及解析状态，只涉及 NS 识别

- [ ] **DomainCertRequest 创建** `Cron/DomainCertRequest.php`
  - [ ] 筛选 `DomainPool` 中符合条件的域名
  - [ ] 调用证书申请服务
  - [ ] 更新 `DomainPool.https_status`、`cert_id`

### 0.8 新增 Service（🟢 新增）

- [ ] **SubdomainGeneratorService 创建** `Service/SubdomainGeneratorService.php`
  - [ ] 从 `Domain` 根域产生默认子域名
  - [ ] 写入 `DomainPool` 表
  - [ ] 设置 `parent_domain_id` 关联

---

## 阶段一：基础设施

### 1.1 数据模型扩展

> ⚠️ **注意**：Domain 模型的解析相关字段已移至阶段〇 DomainPool 扩展

- [ ] **Domain 模型字段扩展（仅根域相关）** `Model/Domain.php`
  - [ ] 新增 `cdn_provider` 字段（VARCHAR(50)）
  - [ ] 新增 `cdn_account_id` 字段（INT）
  - [ ] 新增 `dns_provider` 字段（VARCHAR(50)）— NS 归属检测结果
  - [ ] 新增 `ns_checked_at` 字段（DATETIME）— NS 检测时间
  - [ ] 编写 upgrade() 方法（使用 hasField 检测）
  - [ ] 编写 Getter/Setter 方法
  - 注：resolve_status、https_status、site_ready 等字段已迁移到 DomainPool

- [ ] **DomainDnsRecord 模型创建** `Model/DomainDnsRecord.php`
  - [ ] 定义表结构和字段常量
  - [ ] 实现 install() 方法
  - [ ] 实现 Getter/Setter 方法
  - [ ] 实现 `getByDomainId()` 方法
  - [ ] 实现 `syncRecords()` 批量同步方法
  - [ ] 实现 `deleteByDomainId()` 方法

- [ ] **DomainConfig 模型创建** `Model/DomainConfig.php`
  - [ ] 定义配置表结构（key-value 形式）
  - [ ] 实现 `getValue()` / `setValue()` 方法
  - [ ] 预定义配置键常量：
    - `CONFIG_AUTO_RESOLVE_ENABLED`
    - `CONFIG_AUTO_RESOLVE_RECORD_TYPE`
    - `CONFIG_AUTO_RESOLVE_SUBDOMAINS`
    - `CONFIG_SERVER_PUBLIC_IP`
    - `CONFIG_SERVER_PUBLIC_IPV6`

### 1.2 DNS 服务商检测

- [ ] **DnsProviderDetector 服务** `Service/DnsProviderDetector.php`
  - [ ] 定义已知 NS 特征库（Cloudflare/GName/Aliyun/AWS/Azure/DNSPod）
  - [ ] 实现 `detectProvider(array $nameservers): string` 方法
  - [ ] 实现 `isOriginalProvider(string $detected, string $registrar): bool` 方法
  - [ ] 实现 `getProviderDisplayName(string $code): string` 方法
  - [ ] 实现 `getProviderColor(string $code, string $registrar): string` 方法

---

## 阶段二：DNS 记录管理

### 2.1 接口扩展

- [ ] **DomainRegistrarInterface 扩展** `Api/DomainRegistrarInterface.php`
  - [ ] 新增 `getDnsRecords(string $domain, array $credentials): array`
  - [ ] 新增 `addDnsRecord(string $domain, array $record, array $credentials): array`
  - [ ] 新增 `updateDnsRecord(string $domain, string $recordId, array $record, array $credentials): array`
  - [ ] 新增 `deleteDnsRecord(string $domain, string $recordId, array $credentials): array`

### 2.2 适配器实现

- [ ] **GnameRegistrar DNS 操作** `Adapter/GnameRegistrar.php`
  - [ ] 实现 `getDnsRecords()` - 调用 GName DNS 记录查询 API
  - [ ] 实现 `addDnsRecord()` - 调用 GName DNS 记录添加 API
  - [ ] 实现 `updateDnsRecord()` - 调用 GName DNS 记录修改 API
  - [ ] 实现 `deleteDnsRecord()` - 调用 GName DNS 记录删除 API
  - [ ] 查阅 GName API 文档确认接口路径和参数

- [ ] **AliyunDomainRegistrar DNS 操作** `Adapter/AliyunDomainRegistrar.php`（可选，后续实现）
  - [ ] 实现 `getDnsRecords()`
  - [ ] 实现 `addDnsRecord()`
  - [ ] 实现 `updateDnsRecord()`
  - [ ] 实现 `deleteDnsRecord()`

### 2.3 API 控制器

- [ ] **DnsRecord API 控制器** `Controller/Backend/Api/DnsRecord.php`
  - [ ] `GET list` - 获取域名 DNS 记录列表
  - [ ] `POST add` - 添加 DNS 记录
  - [ ] `POST update` - 修改 DNS 记录
  - [ ] `POST delete` - 删除 DNS 记录
  - [ ] `POST sync` - 同步 DNS 记录（从远程拉取）
  - [ ] `POST batchAdd` - 批量添加记录（用于自动解析）

### 2.4 前端实现

- [ ] **DNS 解析弹窗** `view/templates/Backend/Domain/dns-modal.phtml`
  - [ ] 弹窗 HTML 结构
  - [ ] 记录列表表格
  - [ ] 添加记录表单
  - [ ] 编辑记录表单
  - [ ] 删除确认
  - [ ] 状态图标显示（本地/外部/错误）
  - [ ] 刷新按钮
  - [ ] JavaScript 交互逻辑

---

## 阶段三：自动解析与检测

### 3.1 服务器 IP 服务

- [ ] **ServerIpService 服务** `Service/ServerIpService.php`
  - [ ] 实现 `getPublicIpv4(): ?string` 方法
    - 使用 `https://api.ipify.org`
    - 备用 `https://ifconfig.me/ip`
    - 备用 `https://icanhazip.com`
  - [ ] 实现 `getPublicIpv6(): ?string` 方法
  - [ ] 实现 `isLocalIp(string $ip): bool` 方法
  - [ ] 添加缓存（避免频繁请求）

### 3.2 自动解析定时任务

- [ ] **DomainAutoResolve 定时任务** `Cron/DomainAutoResolve.php`
  - [ ] 实现 `execute()` 方法
  - [ ] 检查自动解析开关
  - [ ] 获取服务器公网 IP
  - [ ] 遍历需要解析的域名
  - [ ] 按 DNS 服务商分组调用 API
  - [ ] 记录解析结果日志
  - [ ] 在 `cron.php` 中注册任务（每 5 分钟）

### 3.3 域名池解析检测定时任务（核心改造）

> ⚠️ **注意**：检测的是 **DomainPool（域名池）**，而非 Domain（根域）

- [ ] **DomainPoolResolveCheck 定时任务** `Cron/DomainPoolResolveCheck.php`（**新增**，替代原 DomainResolveCheck）
  - [ ] 实现 `execute()` 方法
  - [ ] 遍历所有 **DomainPool** 域名池（status=active）
  - [ ] 执行 DNS 查询（`dns_get_record` / `gethostbyname`）
  - [ ] 对比解析 IP 与本服务器 IP
  - [ ] 更新 `DomainPool.resolve_status`、`resolved_ip`、`is_local_server`
  - [ ] 计算并更新 `DomainPool.site_ready`
  - [ ] 记录检测结果日志
  - [ ] 在 `cron.php` 中注册任务（每 10 分钟）

### 3.4 根域 NS 归属检测定时任务

> ⚠️ **注意**：根域只检测 NS 归属，不检测解析状态

- [ ] **DomainNsCheck 定时任务** `Cron/DomainNsCheck.php`（**新增**）
  - [ ] 实现 `execute()` 方法
  - [ ] 遍历所有 **Domain** 根域（status=active）
  - [ ] 获取 nameservers
  - [ ] 调用 DnsProviderDetector 识别 NS 归属
  - [ ] 更新 `Domain.dns_provider`
  - [ ] 标记是否托管到外部
  - [ ] 记录检测结果日志
  - [ ] 在 `cron.php` 中注册任务（每小时）

### 3.5 废弃/重构原 DomainResolveCheck

- [ ] **废弃原任务** `Cron/DomainResolveCheck.php`
  - [ ] 移除或标记为 deprecated
  - [ ] 逻辑已迁移到 DomainPoolResolveCheck

### 3.6 域名解析服务

- [ ] **DomainResolveService 服务** `Service/DomainResolveService.php`
  - [ ] 修改为操作 **DomainPool** 模型
  - [ ] 实现 `resolveDomain(string $domain): array` 方法
  - [ ] 实现 `checkPoolResolveStatus(int $poolId): array` 方法（改为 pool）
  - [ ] 实现 `autoResolveToServer(int $poolId): array` 方法
  - [ ] 实现 `batchResolve(array $poolIds): array` 方法

### 3.7 子域名自动产生服务

- [ ] **SubdomainGeneratorService 服务** `Service/SubdomainGeneratorService.php`（**新增**）
  - [ ] 实现 `generateDefaultSubdomains(Domain $rootDomain): array` 方法
  - [ ] 读取配置的默认子域名前缀（@, www 等）
  - [ ] 为根域产生子域名并入 DomainPool
  - [ ] 设置 parent_domain_id 关联

### 3.8 API 控制器

- [ ] **DomainResolve API 控制器** `Controller/Backend/Api/DomainResolve.php`
  - [ ] `POST check` - 手动检测单个域名解析状态
  - [ ] `POST batchCheck` - 批量检测解析状态
  - [ ] `POST resolve` - 手动解析单个域名到本服务器
  - [ ] `POST batchResolve` - 批量解析到本服务器
  - [ ] `GET serverIp` - 获取服务器公网 IP

---

## 阶段四：证书申请与建站就绪

### 4.1 WLS 证书集成

- [ ] **调研 WLS 证书申请接口**
  - [ ] 确认 Weline_Server 模块是否有证书申请服务
  - [ ] 确认接口签名和参数
  - [ ] 如无，需要先在 Weline_Server 模块实现

- [ ] **证书申请定时任务** `Cron/DomainCertRequest.php`（**修改**，改为筛选 DomainPool）
  - [ ] 实现 `execute()` 方法
  - [ ] 筛选符合条件的 **DomainPool** 域名（resolved + is_local + 无证书/即将过期）
  - [ ] 调用 WLS 证书申请接口
  - [ ] 更新 `DomainPool.https_status`、`https_expires_at`、`cert_id`
  - [ ] 记录申请结果日志
  - [ ] 在 `cron.php` 中注册任务（每小时）

### 4.2 建站就绪状态

> ⚠️ **注意**：建站就绪状态在 **DomainPool** 上计算，而非 Domain

- [ ] **DomainPool 模型方法扩展**
  - [ ] 实现 `isSiteReady(): bool` 方法
  - [ ] 实现 `getSiteReadyStatus(): array` 方法（返回各条件状态）
  - [ ] 实现 `calculateSiteReady()` 方法（在 save_before 中调用）
  - [ ] 在 save_before 中自动计算 `site_ready` 字段

---

## 阶段五：Weline_Websites 前端整合与测试

### 5.1 新增域名列表 Tab

**背景**：当前 Weline_Websites 域名管理页面只有「域名商管理」和「域名购买」两个 Tab，缺少「域名列表」Tab 用于管理已同步的域名。

- [ ] **index.phtml Tab 导航更新** `view/templates/Admin/Domain/index.phtml`
  - [ ] 新增 Tab3 按钮：域名列表（icon: mdi-format-list-bulleted）
  - [ ] Tab3 内容区引入 `domain_list_tab.phtml`

- [ ] **域名列表 Tab 模板创建** `view/templates/Admin/Domain/domain_list_tab.phtml`
  - [ ] 顶部配置区：
    - [ ] 自动解析开关
    - [ ] 服务器 IP 显示/编辑/自动获取
    - [ ] 解析子域配置（@,www）
    - [ ] 记录类型选择（A/AAAA）
  - [ ] 筛选栏：
    - [ ] 域名商账户下拉
    - [ ] 状态筛选
    - [ ] 搜索框
    - [ ] 搜索按钮
    - [ ] 同步按钮
  - [ ] 批量操作栏：
    - [ ] 批量解析按钮
    - [ ] 批量切换 DNS 按钮
    - [ ] 批量检测状态按钮
    - [ ] 批量申请证书按钮
  - [ ] 域名列表表格：
    - [ ] 勾选框列
    - [ ] 域名列
    - [ ] DNS 服务商列（带颜色标记）
    - [ ] 解析状态列（带颜色和图标）
    - [ ] 解析 IP 列
    - [ ] HTTPS 状态列（带颜色和图标）
    - [ ] 建站状态列
    - [ ] 操作列：查看解析 / 建站 / 修复
  - [ ] 分页组件
  - [ ] JavaScript 交互逻辑

### 5.2 DNS 解析弹窗

- [ ] **DNS 解析弹窗** `view/templates/Backend/Domain/dns-modal.phtml`
  - [ ] 弹窗 HTML 结构
  - [ ] 记录列表表格
  - [ ] 添加记录表单
  - [ ] 编辑记录表单
  - [ ] 删除确认
  - [ ] 状态图标显示（本地/外部/错误）
  - [ ] 刷新按钮
  - [ ] JavaScript 交互逻辑

### 5.3 Controller API 扩展

- [ ] **Domain 控制器扩展** `Controller/Admin/Domain.php`
  - [ ] `getDomains` - 获取域名列表（分页、筛选）
  - [ ] `syncDomains` - 同步域名
  - [ ] `getDnsRecords` - 获取 DNS 记录
  - [ ] `addDnsRecord` - 添加 DNS 记录
  - [ ] `updateDnsRecord` - 更新 DNS 记录
  - [ ] `deleteDnsRecord` - 删除 DNS 记录
  - [ ] `checkResolve` - 检测解析状态
  - [ ] `batchCheckResolve` - 批量检测
  - [ ] `resolveToServer` - 解析到本服务器
  - [ ] `batchResolve` - 批量解析
  - [ ] `requestCert` - 申请证书
  - [ ] `batchRequestCert` - 批量申请证书
  - [ ] `getConfig` - 获取配置
  - [ ] `saveConfig` - 保存配置
  - [ ] `getServerIp` - 获取服务器 IP

### 5.4 批量操作功能

- [ ] **批量操作实现**
  - [ ] 批量解析按钮逻辑
  - [ ] 批量切换 DNS 按钮逻辑
  - [ ] 批量检测状态按钮逻辑
  - [ ] 批量申请证书按钮逻辑
  - [ ] 批量操作结果展示

### 5.2 i18n 国际化

- [ ] **翻译文件更新** `i18n/zh_Hans_CN.csv`, `i18n/en_US.csv`
  - [ ] DNS 相关文案
  - [ ] 解析状态相关文案
  - [ ] HTTPS 状态相关文案
  - [ ] 建站状态相关文案
  - [ ] 错误提示文案

### 5.3 测试

- [ ] **单元测试**
  - [ ] DnsProviderDetector 测试
  - [ ] ServerIpService 测试
  - [ ] DomainResolveService 测试

- [ ] **集成测试**
  - [ ] GnameRegistrar DNS 操作测试
  - [ ] 自动解析流程测试
  - [ ] 解析检测流程测试
  - [ ] 证书申请流程测试

- [ ] **前端测试**
  - [ ] DNS 解析弹窗功能测试
  - [ ] 批量操作功能测试
  - [ ] 状态显示测试

---

## 阶段六：GuoLaiRen_PageBuilder 前端同步

### 6.1 控制器扩展

- [ ] **DomainManagement 控制器扩展** `Controller/Backend/DomainManagement.php`
  - [ ] 新增 `postGetDnsRecords()` - 获取域名 DNS 记录
  - [ ] 新增 `postAddDnsRecord()` - 添加 DNS 记录
  - [ ] 新增 `postUpdateDnsRecord()` - 更新 DNS 记录
  - [ ] 新增 `postDeleteDnsRecord()` - 删除 DNS 记录
  - [ ] 新增 `postCheckResolve()` - 检测解析状态
  - [ ] 新增 `postBatchCheckResolve()` - 批量检测解析状态
  - [ ] 新增 `postResolveToServer()` - 解析到本服务器
  - [ ] 新增 `postBatchResolve()` - 批量解析到本服务器
  - [ ] 新增 `postRequestCert()` - 申请 HTTPS 证书
  - [ ] 新增 `postBatchRequestCert()` - 批量申请证书
  - [ ] 新增 `postGetConfig()` - 获取域名管理配置
  - [ ] 新增 `postSaveConfig()` - 保存域名管理配置
  - [ ] 新增 `postGetServerIp()` - 获取服务器公网 IP

### 6.2 前端页面改造

- [ ] **域名列表表格改造** `view/templates/Backend/DomainManagement/index.phtml`
  - [ ] 表格新增列：DNS 服务商（带颜色标记：绿=原供应商，橙=CF，红=其他）
  - [ ] 表格新增列：解析状态（绿=正常，黄=待解析，红=错误）
  - [ ] 表格新增列：HTTPS 状态（绿=有效，黄=申请中，红=无）
  - [ ] 表格新增列：建站状态（可建站/等待中/不可用）
  - [ ] 操作列新增：查看解析按钮
  - [ ] 操作列新增：建站按钮（仅 site_ready 时启用）
  - [ ] JavaScript：`renderDomainTable()` 函数更新

- [ ] **顶部配置区新增**
  - [ ] 自动解析 DNS 开关（带保存功能）
  - [ ] 服务器公网 IP 显示（可编辑/自动获取）
  - [ ] 解析子域配置（@, www）
  - [ ] 记录类型选择（A/AAAA）
  - [ ] JavaScript：配置加载与保存逻辑

- [ ] **批量操作工具栏扩展**
  - [ ] 新增：批量解析到本服务器按钮
  - [ ] 新增：批量检测解析状态按钮
  - [ ] 新增：批量申请证书按钮
  - [ ] JavaScript：批量操作 API 调用逻辑

### 6.3 DNS 解析弹窗

- [ ] **DNS 解析弹窗实现** `view/templates/Backend/DomainManagement/index.phtml`（内嵌）或独立文件
  - [ ] 弹窗 HTML 结构（复用 pb-dm-form-modal 样式）
  - [ ] DNS 记录列表表格
  - [ ] 记录状态图标（✓=本服务器，⚠=外部，✗=错误）
  - [ ] 添加记录表单（类型/主机/值/TTL）
  - [ ] 编辑记录功能
  - [ ] 删除记录确认
  - [ ] 刷新/同步按钮
  - [ ] JavaScript：弹窗打开/关闭/数据加载逻辑

### 6.4 证书管理 Tab（PageBuilder 新增）

**背景**：PageBuilder 的域名管理目前缺少证书管理功能，需要新增 Tab4。

- [ ] **Tab 导航更新** `view/templates/Backend/DomainManagement/index.phtml`
  - [ ] 新增 Tab4 按钮：证书管理（icon: mdi-certificate）
  - [ ] 新增 Tab4 内容区

- [ ] **证书管理 Tab 内容**
  - [ ] 筛选栏：
    - [ ] 状态筛选（全部/有效/即将过期/已过期/无证书/申请中/错误）
    - [ ] 搜索域名
  - [ ] 批量操作栏：
    - [ ] 批量申请证书
    - [ ] 批量续期
  - [ ] 证书列表表格：
    - [ ] 勾选框列
    - [ ] 域名列
    - [ ] 证书状态列（带颜色：绿=有效，黄=即将过期，橙=申请中，红=错误/过期，灰=无）
    - [ ] 颁发机构列（如 Let's Encrypt）
    - [ ] 有效期列（显示剩余天数）
    - [ ] 申请时间列
    - [ ] 操作列：申请/续期/查看详情
  - [ ] 分页组件

- [ ] **证书详情弹窗**
  - [ ] 弹窗 HTML 结构
  - [ ] 证书基本信息（域名、状态、颁发机构、有效期）
  - [ ] 证书文件路径（如 /path/to/fullchain.pem）
  - [ ] 申请历史记录
  - [ ] 操作按钮：续期 / 重新申请

- [ ] **Controller Action 扩展**
  - [ ] `postGetCertificates` - 获取证书列表
  - [ ] `postRequestCert` - 申请单个证书
  - [ ] `postBatchRequestCert` - 批量申请证书
  - [ ] `postRenewCert` - 续期单个证书
  - [ ] `postBatchRenewCert` - 批量续期
  - [ ] `postGetCertDetail` - 获取证书详情

- [ ] **JavaScript 函数新增**
  - [ ] `loadCertificates()` - 加载证书列表
  - [ ] `renderCertTable(items)` - 渲染证书表格
  - [ ] `renderCertStatusBadge(status, expiresAt)` - 证书状态徽章
  - [ ] `openCertDetailModal(domainId)` - 打开证书详情弹窗
  - [ ] `closeCertDetailModal()` - 关闭证书详情弹窗
  - [ ] `requestCert(domainId)` - 申请证书
  - [ ] `renewCert(domainId)` - 续期证书
  - [ ] `batchRequestCerts()` - 批量申请
  - [ ] `batchRenewCerts()` - 批量续期

### 6.5 JavaScript 函数新增

- [ ] **API 路由扩展** `index.phtml` JavaScript
  - [ ] `API.getDnsRecords`
  - [ ] `API.addDnsRecord`
  - [ ] `API.updateDnsRecord`
  - [ ] `API.deleteDnsRecord`
  - [ ] `API.checkResolve`
  - [ ] `API.batchCheckResolve`
  - [ ] `API.resolveToServer`
  - [ ] `API.batchResolve`
  - [ ] `API.requestCert`
  - [ ] `API.batchRequestCert`
  - [ ] `API.getConfig`
  - [ ] `API.saveConfig`
  - [ ] `API.getServerIp`

- [ ] **状态渲染函数新增**
  - [ ] `renderDnsProviderBadge(provider, registrar)` - DNS 服务商徽章
  - [ ] `renderResolveStatusBadge(status, ip)` - 解析状态徽章
  - [ ] `renderHttpsStatusBadge(status, expiresAt)` - HTTPS 状态徽章
  - [ ] `renderSiteReadyBadge(ready, reasons)` - 建站状态徽章

- [ ] **配置管理函数**
  - [ ] `loadDomainConfig()` - 加载配置
  - [ ] `saveDomainConfig()` - 保存配置
  - [ ] `fetchServerIp()` - 获取服务器 IP

- [ ] **DNS 弹窗函数**
  - [ ] `openDnsModal(domainId, domainName)` - 打开 DNS 弹窗
  - [ ] `closeDnsModal()` - 关闭 DNS 弹窗
  - [ ] `loadDnsRecords(domainId)` - 加载 DNS 记录
  - [ ] `renderDnsRecords(records)` - 渲染 DNS 记录表格
  - [ ] `addDnsRecord()` - 添加记录
  - [ ] `editDnsRecord(recordId)` - 编辑记录
  - [ ] `deleteDnsRecord(recordId)` - 删除记录

### 6.6 样式补充

- [ ] **CSS 样式新增** `index.phtml` <style> 内
  - [ ] DNS 弹窗样式（复用现有 pb-dm-form-modal 风格）
  - [ ] 配置区样式（顶部卡片内）
  - [ ] 状态徽章额外样式（如有需要）

### 6.7 i18n 国际化

- [ ] **翻译文件更新** `GuoLaiRen/PageBuilder/i18n/`
  - [ ] `zh_Hans_CN.csv` - 新增 DNS/解析/证书/建站相关中文翻译
  - [ ] `en_US.csv` - 新增对应英文翻译

### 6.8 测试

- [ ] **功能测试**
  - [ ] PageBuilder 域名列表页面加载正常
  - [ ] 新增列正确显示状态
  - [ ] 配置区功能正常（保存/获取）
  - [ ] 批量操作按钮功能正常
  - [ ] DNS 解析弹窗打开/关闭正常
  - [ ] DNS 记录增删改查正常
  - [ ] 建站按钮仅在就绪时启用

- [ ] **与 Weline_Websites 一致性测试**
  - [ ] 同一域名在两个模块显示状态一致
  - [ ] 在 PageBuilder 操作后 Weline_Websites 同步更新
  - [ ] 在 Weline_Websites 操作后 PageBuilder 同步更新

---

## 待定事项

- [ ] 与 WLS 模块协调证书申请接口
- [ ] GName DNS 记录 API 文档确认
- [ ] 其他域名商适配器 DNS 支持（Aliyun/AWS/Azure）
- [ ] DNS 传播延迟处理策略
- [ ] PageBuilder 是否需要独立的 dns-modal.phtml 文件还是内嵌

---

## 备注

- 所有新增字段使用 `hasField` 检测后再添加
- 所有 API 调用需添加适当的错误处理和日志记录
- 前端使用主题变量，禁止硬编码颜色
- 用户提示使用 BackendToast/BackendConfirm
- PageBuilder 复用 Weline_Websites 后端服务，通过 DomainManagement 的 FrameworkQueryService 查询入口或直接注入 DomainSyncService/DomainResolveService 等
- PageBuilder 前端与 Weline_Websites 前端保持一致的用户体验

