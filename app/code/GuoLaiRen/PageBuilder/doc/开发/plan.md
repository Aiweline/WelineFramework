# GuoLaiRen_PageBuilder 域名管理界面改造计划

> 最后更新：2026-03-07  
> 状态：🔵 测试中（status: testing）  
> 当前阶段：阶段五 - 测试验证  
> 完成度：90%（4/5 阶段完成，阶段五进行中）  
> 总计划链接：[.cursor/plans/域名模型重构计划_db9c4f72.plan.md](../../../../../../.cursor/plans/域名模型重构计划_db9c4f72.plan.md)

## 阶段状态总览

| 阶段 | 状态 | 说明 |
|------|------|------|
| 阶段一：控制器改造 | 🟢 已完成 | DomainPool 字段与接口返回已打通 |
| 阶段二：前端页面改造 | 🟢 已完成 | 域名池状态展示、站点域名选择已切换 |
| 阶段三：JS 函数更新 | 🟢 已完成 | 状态徽章渲染函数已统一 |
| 阶段四：i18n 国际化 | 🟢 已完成 | 全仓 i18n 已收集并保留 |
| 阶段五：测试 | 🔵 测试中 | 自动化已执行，待补后台会话端到端验证 |

---

## 一、概述

PageBuilder 模块的域名管理界面需要与 Weline_Websites 同步改造，确保：

1. **域名列表展示的是域名池数据**，而非根域
2. **建站选择从域名池**，只显示 `site_ready=1` 的域名
3. **界面显示域名池状态**：`resolve_status`、`https_status`、`site_ready`

### 核心改造要点

| 改造项 | 说明 |
|--------|------|
| 域名列表 | 改为查询 `DomainPool`，显示域名池状态 |
| 建站选择 | 从 `DomainPool` 选择 `site_ready=1` 的域名 |
| 网站表单 | 使用 `pool_id` 关联，而非域名字符串 |

---

## 二、当前状态分析

### 2.1 已使用 DomainPool 的部分

PageBuilder 的 `WebsiteManagement` 控制器已经使用 `DomainPool` 获取域名选项：

```php
// Controller/Backend/WebsiteManagement.php:247-249
$domainPool = $this->objectManager->getInstance(\Weline\Websites\Model\DomainPool::class);
$this->assign('domain_options', $domainPool->getSelectOptions());
```

### 2.2 代码现状问题（2026-02-27 审计）

| 优先级 | 文件 | 问题 | 改造 |
|-------|------|------|------|
| 🔴 高 | `Controller/Backend/DomainManagement.php` | 使用 `Domain::getStatusOptions()` 和 `DomainSyncService::getDomains()`，实际查询 `Domain` 表 | 改为查询 DomainPool |
| 🔴 高 | `Weline\Websites\Service\DomainSyncService::getDomains()` | 内部使用 `Domain` 模型（line 257） | 依赖 Weline_Websites 改造 |
| 🟡 中 | `view/templates/Backend/DomainManagement/index.phtml` | 表格未显示域名池状态列 | 添加 resolve_status、https_status、site_ready 列 |
| 🟡 中 | `view/templates/Backend/WebsiteManagement/form.phtml` | 域名选择未显示状态、未筛选 site_ready | 添加状态显示，默认只显示就绪域名 |

### 2.3 依赖关系

PageBuilder 依赖 Weline_Websites 模块以下改造完成：

1. `DomainPool` 模型字段扩展（resolve_status, https_status, site_ready 等）
2. `Controller/Backend/Api/DomainPool.php` 修正（注入 DomainPool 而非 Domain）
3. `DomainSyncService` 新增域名池查询方法或新建 `DomainPoolService`

---

## 三、改造内容

### 3.1 DomainManagement 控制器改造

**文件**: `Controller/Backend/DomainManagement.php`

1. 确认域名列表 API 是否查询 `DomainPool`
2. 返回字段需包含：`pool_id`、`resolve_status`、`https_status`、`site_ready`
3. 新增按 `site_ready` 筛选的参数

### 3.2 域名列表页面改造

**文件**: `view/templates/Backend/DomainManagement/index.phtml`

域名列表表格新增列：

| 列 | 数据来源 | 显示 |
|----|---------|------|
| 解析状态 | `DomainPool.resolve_status` | ✅ 解析正常 / ⚠️ 指向外部 / 🔴 解析失败 |
| HTTPS | `DomainPool.https_status` | ✅ 有效 / ⚠️ 申请中 / 🔴 无 |
| 建站就绪 | `DomainPool.site_ready` | ✅ 可建站 / ⚠️ 等待 / 🔴 不可用 |

### 3.3 WebsiteManagement 表单改造

**文件**: `view/templates/Backend/WebsiteManagement/form.phtml`

1. DomainSelect 组件默认筛选 `site_ready=1`
2. 显示域名状态信息（解析/HTTPS）
3. 提交时使用 `pool_id` 列表

## 四、依赖 Weline_Websites 模块

以下功能由 Weline_Websites 模块提供，PageBuilder 直接复用：

| 服务/模型 | 说明 |
|-----------|------|
| `Model\DomainPool` | 域名池模型（包含 resolve_status、https_status、site_ready） |
| `Service\DomainResolveService` | 域名解析服务 |
| `Taglib\DomainSelect` | 域名选择器组件（支持 site-ready-only 属性） |
| `Controller\Backend\Api\DomainPool` | 域名池 API |

---

## 五、文件变更清单

| 操作 | 文件 | 说明 |
|-----|------|------|
| 修改 | `Controller/Backend/DomainManagement.php` | 域名列表改为查询 DomainPool，返回状态字段 |
| 修改 | `Controller/Backend/WebsiteManagement.php` | 确保读取 DomainPool 新增字段 |
| 修改 | `view/templates/Backend/DomainManagement/index.phtml` | 表格新增状态列 |
| 修改 | `view/templates/Backend/WebsiteManagement/form.phtml` | 域名选择显示状态，使用 pool_id |

---

## 六、验收标准

1. **域名列表显示域名池数据**：而非根域，包含 resolve_status、https_status、site_ready 状态
2. **建站选择从域名池**：只显示 `site_ready=1` 的域名
3. **网站关联使用 pool_id**：而非域名字符串
4. **与 Weline_Websites 功能一致**：两个模块的域名管理体验一致

---

## 七、进度记录

| 日期 | 进度 | 说明 |
|------|------|------|
| 2026-02-27 | 计划创建 | 完成计划文档 |
| 2026-02-27 | 代码审计 | 补充代码现状问题分析 |
