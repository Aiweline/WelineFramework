# GuoLaiRen_PageBuilder 域名管理界面改造 - 任务清单

> 最后更新：2026-02-27  
> 关联计划：[plan.md](./plan.md)

## 任务状态说明

- `[ ]` 待办 / pending
- `[-]` 进行中 / in_progress
- `[x]` 已完成 / done

---

## 阶段〇：依赖确认（阻塞）

> ⚠️ **PageBuilder 改造依赖 Weline_Websites 模块完成以下任务**

### 0.1 Weline_Websites 依赖项

- [ ] **DomainPool 模型扩展完成**
  - 新增字段：`resolve_status`、`https_status`、`site_ready`、`parent_domain_id`、`cert_id` 等
  - 提供 `getSelectOptions()` 方法返回新字段

- [ ] **DomainPool API 修正完成**
  - `Controller/Backend/Api/DomainPool.php` 正确注入 `DomainPool` 模型
  - 返回字段包含状态信息

- [ ] **域名池查询服务可用**
  - `DomainSyncService` 新增域名池查询方法，或
  - 新建 `DomainPoolService` 提供查询能力

### 0.2 代码现状确认

> 2026-02-27 审计结果：

| 文件 | 当前状态 | 问题 |
|------|---------|------|
| `Controller/Backend/DomainManagement.php` | 使用 `Domain::getStatusOptions()` | ❌ 查询 Domain 而非 DomainPool |
| `DomainSyncService::getDomains()` | 使用 `Domain` 模型（line 257） | ❌ 依赖 Weline_Websites 改造 |

---

## 阶段一：控制器改造

### 1.1 DomainManagement 控制器

- [ ] **确认域名列表数据源** `Controller/Backend/DomainManagement.php`
  - [ ] 检查 `postGetDomains` 或类似方法是否查询 `DomainPool`
  - [ ] 如仍查询 `Domain`，改为查询 `DomainPool`
  - [ ] 返回字段新增：`pool_id`、`resolve_status`、`https_status`、`site_ready`
  - [ ] 新增参数：`site_ready` 筛选（默认 null，可选 1/0）

### 1.2 WebsiteManagement 控制器

- [ ] **确认使用 DomainPool 新增字段** `Controller/Backend/WebsiteManagement.php`
  - [ ] 检查 `getIndex()` 和 `getForm()` 方法
  - [ ] 确认 `domain_options` 包含 `pool_id`、`resolve_status`、`https_status`、`site_ready`
  - [ ] 修改保存逻辑，使用 `pool_id` 关联 WebsiteDomain

---

## 阶段二：前端页面改造

### 2.1 域名列表页面

- [ ] **表格新增状态列** `view/templates/Backend/DomainManagement/index.phtml`
  - [ ] 新增「解析状态」列
    - ✅ 绿色"解析正常"（resolve_status=resolved, is_local_server=1）
    - ⚠️ 橙色"指向外部"（resolve_status=resolved, is_local_server=0）
    - 🔴 红色"解析失败"（resolve_status=error）
    - 🟡 黄色"等待解析"（resolve_status=pending）
  - [ ] 新增「HTTPS」列
    - ✅ 绿色"有效"（https_status=valid）
    - ⚠️ 橙色"申请中"（https_status=pending）
    - 🔴 红色"无"（https_status=none/error/expired）
  - [ ] 新增「建站就绪」列
    - ✅ 绿色"可建站"（site_ready=1）
    - ⚠️ 黄色"等待中"（resolve_status!=resolved 或 https_status!=valid）
    - 🔴 红色"不可用"（status!=active）
  - [ ] JavaScript 渲染函数更新

### 2.2 网站表单页面

- [ ] **域名选择器改造** `view/templates/Backend/WebsiteManagement/form.phtml`
  - [ ] DomainSelect 组件添加 `site-ready-only="true"` 属性
  - [ ] 显示域名状态信息（tooltip 或副标题）
  - [ ] 表单提交使用 `pool_id[]` 字段名

### 2.3 QuickBuild 向导

- [ ] **域名选择步骤改造** `view/templates/Backend/QuickBuild/wizard.phtml`
  - [ ] 确认域名列表来源为 DomainPool
  - [ ] 添加 `site_ready=1` 筛选
  - [ ] 显示域名状态

---

## 阶段三：JavaScript 函数更新

### 3.1 状态渲染函数

- [ ] **新增渲染函数** `view/templates/Backend/DomainManagement/index.phtml`
  - [ ] `renderResolveStatusBadge(status, isLocal, resolvedIp)`
  - [ ] `renderHttpsStatusBadge(status, expiresAt)`
  - [ ] `renderSiteReadyBadge(ready)`

### 3.2 域名列表渲染

- [ ] **更新域名表格渲染** 
  - [ ] 修改 `renderDomainTable()` 或类似函数
  - [ ] 使用新增的状态渲染函数

---

## 阶段四：i18n 国际化

- [ ] **翻译文件更新**
  - [ ] `i18n/zh_Hans_CN.csv` - 新增状态相关中文翻译
  - [ ] `i18n/en_US.csv` - 新增对应英文翻译

新增翻译项：
- 解析正常 / Resolved
- 指向外部 / External IP
- 解析失败 / Resolve Failed
- 等待解析 / Pending
- HTTPS 有效 / HTTPS Valid
- HTTPS 申请中 / HTTPS Pending
- 无 HTTPS / No HTTPS
- 可建站 / Site Ready
- 等待中 / Waiting
- 不可用 / Not Available

---

## 阶段五：测试

### 5.1 功能测试

- [ ] **域名列表页面测试**
  - [ ] 页面加载正常
  - [ ] 表格显示域名池数据（非根域）
  - [ ] 状态列正确显示
  - [ ] 筛选功能正常

### 5.2 建站流程测试

- [ ] **网站表单测试**
  - [ ] 域名选择器只显示 site_ready=1 的域名
  - [ ] 选择后使用 pool_id 关联
  - [ ] 保存后 WebsiteDomain 使用 pool_id

### 5.3 一致性测试

- [ ] **与 Weline_Websites 一致性测试**
  - [ ] 同一域名在两个模块显示状态一致
  - [ ] 在 PageBuilder 操作后 Weline_Websites 同步更新
  - [ ] 在 Weline_Websites 操作后 PageBuilder 同步更新

---

## 待定事项

- [ ] 确认 DomainManagement 当前的数据源（Domain 还是 DomainPool）
- [ ] 确认是否需要新增证书管理 Tab（原计划中有）

---

## 备注

- 所有颜色使用主题变量（var(--backend-color-success) 等）
- 用户提示使用 BackendToast/BackendConfirm
- 复用 Weline_Websites 的后端服务，不重复实现
