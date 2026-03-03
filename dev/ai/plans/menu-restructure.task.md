# 后台菜单结构重组 - 任务清单

**关联计划**：[menu-restructure.plan.md](./menu-restructure.plan.md)
**状态**：🟢 已完成

---

## 阶段 1：修改核心顶层菜单

- [ ] 1.1 修改 `Weline_Backend/etc/backend/menu.xml` 定义 6 个新顶层菜单
- [ ] 1.2 调整二级分组菜单结构

## 阶段 2：调整各模块菜单归属

### 内容管理 (content_management)
- [ ] 2.1 Weline_Cms - 归入内容管理
- [ ] 2.2 GuoLaiRen_PageBuilder - 归入内容管理
- [ ] 2.3 WeShop_Cms - 取消顶层，归入内容管理
- [ ] 2.4 GuoLaiRen_Blog - 归入内容管理
- [ ] 2.5 Weline_MediaManager - 归入内容管理

### 业务运营 (business_operations)
- [ ] 2.6 Weline_Order - 归入业务运营
- [ ] 2.7 Weline_Customer - 归入业务运营
- [ ] 2.8 Weline_Marketing - 归入业务运营
- [ ] 2.9 Weline_Shipping - 归入业务运营
- [ ] 2.10 Weline_Payment - 归入业务运营
- [ ] 2.11 Weline_Currency - 归入业务运营
- [ ] 2.12 WeShop_Store/Product/Customer - 归入业务运营

### 系统管理 (system_management)
- [ ] 2.13 Weline_Admin - parent 改为 system_management
- [ ] 2.14 Weline_Acl - parent 改为 system_management
- [ ] 2.15 Weline_CacheManager - 归入系统管理
- [ ] 2.16 Weline_Queue - 归入系统管理
- [ ] 2.17 Weline_Cron - 归入系统管理
- [ ] 2.18 Weline_I18n - 归入系统管理
- [ ] 2.19 Weline_Websites - 归入系统管理

### 应用工具 (apps_tools)
- [ ] 2.20 Weline_Ai - 归入应用工具
- [ ] 2.21 GuoLaiRen_Desensitization - 归入应用工具
- [ ] 2.22 Weline_Seo - 归入应用工具

### 开发者 (developer)
- [ ] 2.23 Weline_ModuleManager - 归入开发者
- [ ] 2.24 Weline_DeveloperWorkspace - 归入开发者
- [ ] 2.25 Weline_Theme - 归入开发者
- [ ] 2.26 Weline_Event - 归入开发者
- [ ] 2.27 Weline_Hook - 归入开发者
- [ ] 2.28 Weline_Extends - 归入开发者
- [ ] 2.29 Weline_Eav - 归入开发者
- [ ] 2.30 Weline_Widget - 归入开发者
- [ ] 2.31 Weline_Taglib - 归入开发者
- [ ] 2.32 Weline_EditorManager - 归入开发者
- [ ] 2.33 Weline_DataTable - 归入开发者

## 阶段 3：验证与升级

- [ ] 3.1 执行 `php bin/w setup:upgrade --route`
- [ ] 3.2 验证菜单正常显示
- [ ] 3.3 验证权限正常
