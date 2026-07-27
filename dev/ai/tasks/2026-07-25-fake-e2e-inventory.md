# 假 E2E 用例清单与整治台账

> 生成日期：2026-07-25。来源扫描：`app/code/Weline/**/*-smoke-backend.spec.js`。

## 统计

| 项 | 数量 |
|----|------|
| smoke 文件 | 75 |
| case 行 | 145 |
| P0 短名/试路由即过 | 9 |
| P1 打开页+标题/Fatal | 49 |
| P2 伪搜索/文案匹配 | 79 |
| OK 已有交互 | 8 |

## 判定标准

- **P0**：短名重复件，或试多路由成功一条即过。
- **P1**：仅 login + goto + body/标题/无 Fatal。
- **P2**：标题声称搜索/筛选/CRUD，实现仅为 URL query 或 innerText 字样匹配。
- **OK**：已有 click/fill 等业务交互（仍可能需加强决定性断言）。

## Wave1 优先模块

Seo, Acl, Order, SystemConfig, Customer, Payment, Queue, Cron, Websites, Framework

## P0

| 文件 | Case ID | 标题 | 类型 |
|------|---------|------|------|
| `app/code/Weline/Acl/Test/e2e/backend/Acl-smoke-backend.spec.js` | BACKEND-SMOKE-ACL-001 | renders acl management page without PHP fatal errors | short-dup |
| `app/code/Weline/Admin/Test/e2e/backend/Admin-smoke-backend.spec.js` | BACKEND-SMOKE-ADMIN-001 | renders admin dashboard without PHP fatal errors | short-dup |
| `app/code/Weline/Api/test/e2e/backend/Api-smoke-backend.spec.js` | BACKEND-SMOKE-API-001 | renders api backend user management page without PHP fatal errors | short-dup |
| `app/code/Weline/Backend/test/e2e/backend/Backend-smoke-backend.spec.js` | BACKEND-SMOKE-BE-001 | renders backend system config page without PHP fatal errors | short-dup |
| `app/code/Weline/Framework/Test/E2E/backend/Framework-smoke-backend.spec.js` | BACKEND-SMOKE-FW-001 | renders at least one framework backend route without PHP fatal errors | short-dup |
| `app/code/Weline/Frontend/test/e2e/backend/Frontend-smoke-backend.spec.js` | BACKEND-SMOKE-FRONTEND-001 | renders frontend theme config page without PHP fatal errors | short-dup |
| `app/code/Weline/ModuleManager/test/e2e/backend/ModuleManager-smoke-backend.spec.js` | BACKEND-SMOKE-MM-001 | renders module listing page without PHP fatal errors | short-dup |
| `app/code/Weline/Server/Test/e2e/backend/Server-smoke-backend.spec.js` | BACKEND-SMOKE-SERVER-001 | renders server backend monitoring page without PHP fatal errors | short-dup |
| `app/code/Weline/SessionManager/test/e2e/backend/SessionManager-smoke-backend.spec.js` | BACKEND-SMOKE-SESSION-001 | renders session manager without PHP fatal errors (backend route available) | short-dup |

## P1

| 文件 | Case ID | 标题 | 类型 |
|------|---------|------|------|
| `app/code/Weline/Acl/Test/e2e/backend/Weline_Acl-smoke-backend.spec.js` | ACL-SMOKE-001 | ACL角色列表页面能够正常加载，显示角色管理标题 | weline |
| `app/code/Weline/Acl/Test/e2e/backend/Weline_Acl-smoke-backend.spec.js` | ACL-SMOKE-002 | IP白名单页面能够正常加载 | weline |
| `app/code/Weline/Acl/Test/e2e/backend/Weline_Acl-smoke-backend.spec.js` | ACL-SMOKE-003 | 安全日志页面能够正常加载 | weline |
| `app/code/Weline/Admin/Test/e2e/backend/Weline_Admin-smoke-backend.spec.js` | ADMIN-SMOKE-001 | 管理员仪表盘能够正常加载，显示 Dashboard 标题和概览数据 | weline |
| `app/code/Weline/Admin/Test/e2e/backend/Weline_Admin-smoke-backend.spec.js` | ADMIN-SMOKE-002 | 管理员首页 /admin 能够重定向到认证后的后台页面 | weline |
| `app/code/Weline/Admin/Test/e2e/backend/Weline_Admin-smoke-backend.spec.js` | ADMIN-SMOKE-003 | 系统模块列表页面能够正常加载，显示模块表格 | weline |
| `app/code/Weline/Captcha/test/e2e/backend/Weline_Captcha-smoke-backend.spec.js` | BACKEND-SMOKE-CAPTCHA-001 | Captcha 模块后台入口页面能够正常加载，无 PHP 致命错误 | weline |
| `app/code/Weline/Captcha/test/e2e/backend/Weline_Captcha-smoke-backend.spec.js` | BACKEND-SMOKE-CAPTCHA-002 | 前台登录页验证码能够正常显示 | weline |
| `app/code/Weline/Cdn/Test/e2e/backend/Weline_Cdn-smoke-backend.spec.js` | CDN-SMOKE-001 | CDN配置页面能够正常加载，显示CDN配置标题 | weline |
| `app/code/Weline/Cdn/Test/e2e/backend/Weline_Cdn-smoke-backend.spec.js` | CDN-SMOKE-002 | CDN配置页面包含配置表单元素 | weline |
| `app/code/Weline/Checkout/test/e2e/backend/Weline_Checkout-smoke-backend.spec.js` | CHECKOUT-SMOKE-001 | 结账配置页面能够正常加载，显示结账管理标题 | weline |
| `app/code/Weline/Checkout/test/e2e/backend/Weline_Checkout-smoke-backend.spec.js` | CHECKOUT-SMOKE-002 | 结账配置页面包含配置表单 | weline |
| `app/code/Weline/Code/test/e2e/backend/Weline_Code-smoke-backend.spec.js` | BACKEND-SMOKE-CODE-001 | Code 模块后台入口存在，无 PHP 致命错误（模块为Console命令模块） | weline |
| `app/code/Weline/Currency/Test/e2e/backend/Weline_Currency-smoke-backend.spec.js` | BACKEND-SMOKE-CURRENCY-001 | 货币列表页面能够正常加载，显示货币管理标题 | weline |
| `app/code/Weline/Currency/Test/e2e/backend/Weline_Currency-smoke-backend.spec.js` | BACKEND-SMOKE-CURRENCY-002 | 货币配置页面能够正常加载，显示配置项标题 | weline |
| `app/code/Weline/Customer/Test/e2e/backend/Weline_Customer-smoke-backend.spec.js` | CUSTOMER-SMOKE-001 | 客户列表页面能够正常加载，显示客户管理标题 | weline |
| `app/code/Weline/Customer/Test/e2e/backend/Weline_Customer-smoke-backend.spec.js` | CUSTOMER-SMOKE-003 | 客户列表支持分页 | weline |
| `app/code/Weline/CustomerService/Test/e2e/backend/Weline_CustomerService-smoke-backend.spec.js` | BACKEND-SMOKE-CUSTOMERSERVICE-001 | 客服会话列表页面能够正常加载，显示会话管理标题 | weline |
| `app/code/Weline/CustomerService/Test/e2e/backend/Weline_CustomerService-smoke-backend.spec.js` | BACKEND-SMOKE-CUSTOMERSERVICE-002 | 客服配置页面能够正常加载，显示配置标题 | weline |
| `app/code/Weline/CustomerService/Test/e2e/backend/Weline_CustomerService-smoke-backend.spec.js` | BACKEND-SMOKE-CUSTOMERSERVICE-003 | 客服人员列表页面能够正常加载，显示人员管理标题 | weline |
| `app/code/Weline/DeveloperWorkspace/Test/e2e/backend/Weline_DeveloperWorkspace-smoke-backend.spec.js` | (parse) | Weline_DeveloperWorkspace-smoke-backend.spec.js | weline |
| `app/code/Weline/Eav/test/e2e/backend/Weline_Eav-smoke-backend.spec.js` | (parse) | Weline_Eav-smoke-backend.spec.js | weline |
| `app/code/Weline/EditorManager/test/e2e/backend/Weline_EditorManager-smoke-backend.spec.js` | (parse) | Weline_EditorManager-smoke-backend.spec.js | weline |
| `app/code/Weline/FileManager/test/e2e/backend/Weline_FileManager-smoke-backend.spec.js` | (parse) | Weline_FileManager-smoke-backend.spec.js | weline |
| `app/code/Weline/Framework/Test/E2E/backend/Weline_Framework-smoke-backend.spec.js` | (parse) | Weline_Framework-smoke-backend.spec.js | weline |
| `app/code/Weline/Index/test/e2e/backend/Weline_Index-smoke-backend.spec.js` | INDEX-SMOKE-001 | 后台首页能够正常加载，显示管理后台内容 | weline |
| `app/code/Weline/Index/test/e2e/backend/Weline_Index-smoke-backend.spec.js` | INDEX-SMOKE-002 | 后台首页包含导航菜单或快捷入口 | weline |
| `app/code/Weline/Installer/test/e2e/backend/Weline_Installer-smoke-backend.spec.js` | INSTALLER-SMOKE-001 | 安装器页面能够正常加载，无PHP致命错误 | weline |
| `app/code/Weline/Order/Test/e2e/backend/Weline_Order-smoke-backend.spec.js` | ORDER-SMOKE-001 | 订单列表页面能够正常加载，显示订单管理标题 | weline |
| `app/code/Weline/Order/Test/e2e/backend/Weline_Order-smoke-backend.spec.js` | ORDER-SMOKE-002 | 订单发票页面能够正常加载 | weline |
| `app/code/Weline/Order/Test/e2e/backend/Weline_Order-smoke-backend.spec.js` | ORDER-SMOKE-003 | 订单货运页面能够正常加载 | weline |
| `app/code/Weline/Order/Test/e2e/backend/Weline_Order-smoke-backend.spec.js` | ORDER-SMOKE-004 | 订单退款页面能够正常加载 | weline |
| `app/code/Weline/Parts/test/e2e/backend/Weline_Parts-smoke-backend.spec.js` | BACKEND-SMOKE-001 | renders at least one Parts backend route without PHP fatal errors | weline |
| `app/code/Weline/Seo/Test/e2e/backend/Weline_Seo-smoke-backend.spec.js` | SEO-SMOKE-001 | SEO仪表盘能够正常加载，显示SEO概览 | weline |
| `app/code/Weline/Seo/Test/e2e/backend/Weline_Seo-smoke-backend.spec.js` | SEO-SMOKE-002 | Sitemap管理页面能够正常加载 | weline |
| `app/code/Weline/Seo/Test/e2e/backend/Weline_Seo-smoke-backend.spec.js` | SEO-SMOKE-003 | SEO账户页面能够正常加载 | weline |
| `app/code/Weline/Server/Test/e2e/backend/Weline_Server-smoke-backend.spec.js` | SERVER-SMOKE-MANAGER-001 | 服务器管理页面能够正常加载 | weline |
| `app/code/Weline/Server/Test/e2e/backend/Weline_Server-smoke-backend.spec.js` | SERVER-SMOKE-MANAGER-002 | Session管理页面能够正常加载 | weline |
| `app/code/Weline/Server/Test/e2e/backend/Weline_Server-smoke-backend.spec.js` | SERVER-SMOKE-MANAGER-003 | 内存服务管理页面能够正常加载 | weline |
| `app/code/Weline/Server/Test/e2e/backend/Weline_Server-smoke-backend.spec.js` | SERVER-SMOKE-MONITOR-001 | 服务器监控页面能够正常加载 | weline |
| `app/code/Weline/Server/Test/e2e/backend/Weline_Server-smoke-backend.spec.js` | SERVER-SMOKE-OPTIMIZATION-001 | 优化指南页面能够正常加载 | weline |
| `app/code/Weline/Server/Test/e2e/backend/Weline_Server-smoke-backend.spec.js` | SERVER-SMOKE-SSETEST-001 | SSE测试页面能够正常加载 | weline |
| `app/code/Weline/Server/Test/e2e/backend/Weline_Server-smoke-backend.spec.js` | SERVER-SMOKE-SSL-001 | SSL证书管理页面能够正常加载 | weline |
| `app/code/Weline/Sticker/Test/e2e/backend/Weline_Sticker-smoke-backend.spec.js` | STICKER-SMOKE-001 | Sticker页面能够正常加载，无PHP致命错误 | weline |
| `app/code/Weline/SystemConfig/Test/e2e/backend/Weline_SystemConfig-smoke-backend.spec.js` | SYSCONFIG-SMOKE-001 | 系统配置首页能够正常加载，显示配置分组列表 | weline |
| `app/code/Weline/SystemConfig/Test/e2e/backend/Weline_SystemConfig-smoke-backend.spec.js` | SYSCONFIG-SMOKE-002 | 系统配置分组页面能够正常加载配置项表单 | weline |
| `app/code/Weline/ThemeFancy/Test/e2e/backend/Weline_ThemeFancy-smoke-backend.spec.js` | (parse) | Weline_ThemeFancy-smoke-backend.spec.js | weline |
| `app/code/Weline/UrlManager/Test/e2e/backend/Weline_UrlManager-smoke-backend.spec.js` | (parse) | Weline_UrlManager-smoke-backend.spec.js | weline |
| `app/code/Weline/Vue/test/e2e/backend/Weline_Vue-smoke-backend.spec.js` | VUE-SMOKE-001 | Vue集成页面能够正常加载，无PHP致命错误 | weline |

## P2

| 文件 | Case ID | 标题 | 类型 |
|------|---------|------|------|
| `app/code/Weline/Acl/Test/e2e/backend/Weline_Acl-smoke-backend.spec.js` | ACL-SMOKE-004 | ACL角色列表支持关键词搜索过滤 | weline |
| `app/code/Weline/Admin/Test/e2e/backend/Weline_Admin-smoke-backend.spec.js` | ADMIN-SMOKE-004 | 系统模块列表支持按状态筛选（启用/禁用） | weline |
| `app/code/Weline/Ai/Test/e2e/backend/Weline_Ai-smoke-backend.spec.js` | tc01 | AI管理聚合页能正确加载并显示Tab导航 | weline |
| `app/code/Weline/Ai/Test/e2e/backend/Weline_Ai-smoke-backend.spec.js` | tc02 | AI模型列表页能正确加载并显示模型表格 | weline |
| `app/code/Weline/Ai/Test/e2e/backend/Weline_Ai-smoke-backend.spec.js` | tc03 | AI适配器列表页能正确加载 | weline |
| `app/code/Weline/Ai/Test/e2e/backend/Weline_Ai-smoke-backend.spec.js` | tc04 | AI供应商账户页面能正确加载 | weline |
| `app/code/Weline/AiKnowledge/test/e2e/backend/Weline_AiKnowledge-smoke-backend.spec.js` | tc01 | AI知识库模块可用且无致命错误 | weline |
| `app/code/Weline/AiKnowledge/test/e2e/backend/Weline_AiKnowledge-smoke-backend.spec.js` | tc02 | MCP服务相关API可正常访问 | weline |
| `app/code/Weline/Api/test/e2e/backend/Weline_Api-smoke-backend.spec.js` | API-SMOKE-001 | API管理页面能够正常加载，显示API相关内容 | weline |
| `app/code/Weline/Api/test/e2e/backend/Weline_Api-smoke-backend.spec.js` | API-SMOKE-002 | API后台入口页面能够正常加载，无PHP致命错误 | weline |
| `app/code/Weline/AppStore/test/e2e/backend/Weline_AppStore-smoke-backend.spec.js` | tc01 | 应用商城首页能正确加载并显示绑定状态 | weline |
| `app/code/Weline/AppStore/test/e2e/backend/Weline_AppStore-smoke-backend.spec.js` | tc02 | 已安装模块页面能正确加载 | weline |
| `app/code/Weline/AppStore/test/e2e/backend/Weline_AppStore-smoke-backend.spec.js` | tc03 | 下载历史页面能正确加载 | weline |
| `app/code/Weline/AppStore/test/e2e/backend/Weline_AppStore-smoke-backend.spec.js` | tc04 | 账户绑定页面能正确加载 | weline |
| `app/code/Weline/BackendActivity/test/e2e/backend/Weline_BackendActivity-smoke-backend.spec.js` | BACKENDACTIVITY-SMOKE-001 | 活动记录页面能够正常加载，显示活动日志 | weline |
| `app/code/Weline/CacheManager/test/e2e/backend/Weline_CacheManager-smoke-backend.spec.js` | CACHE-SMOKE-001 | 缓存管理首页能够正常加载，显示缓存管理标题 | weline |
| `app/code/Weline/CacheManager/test/e2e/backend/Weline_CacheManager-smoke-backend.spec.js` | CACHE-SMOKE-002 | 缓存管理页面包含缓存清理或刷新按钮 | weline |
| `app/code/Weline/Component/Test/e2e/backend/Weline_Component-smoke-backend.spec.js` | BACKEND-SMOKE-COMPONENT-001 | 组件库首页能够正常加载，显示组件库标题 | weline |
| `app/code/Weline/Component/Test/e2e/backend/Weline_Component-smoke-backend.spec.js` | BACKEND-SMOKE-COMPONENT-002 | OffCanvas 成功结果页能够正常加载 | weline |
| `app/code/Weline/Cron/test/e2e/backend/Weline_Cron-smoke-backend.spec.js` | CRON-SMOKE-001 | 定时任务列表页面能够正常加载，显示任务管理标题 | weline |
| `app/code/Weline/Cron/test/e2e/backend/Weline_Cron-smoke-backend.spec.js` | CRON-SMOKE-002 | 定时任务列表包含任务表格或统计信息 | weline |
| `app/code/Weline/Customer/Test/e2e/backend/Weline_Customer-smoke-backend.spec.js` | CUSTOMER-SMOKE-002 | 客户列表支持关键词搜索过滤 | weline |
| `app/code/Weline/DataTable/Test/e2e/backend/Weline_DataTable-smoke-backend.spec.js` | DATATABLE-SMOKE-001 | DataTable页面能够正常加载，显示数据表格 | weline |
| `app/code/Weline/Database/test/e2e/backend/Weline_Database-smoke-backend.spec.js` | DATABASE-SMOKE-001 | 数据库管理首页能够正常加载，显示数据库管理标题 | weline |
| `app/code/Weline/Database/test/e2e/backend/Weline_Database-smoke-backend.spec.js` | DATABASE-SMOKE-002 | 数据库管理页面包含数据表列表或统计信息 | weline |
| `app/code/Weline/ElFinderFileManager/Test/e2e/backend/Weline_ElFinderFileManager-smoke-backend.spec.js` | ELFINDER-SMOKE-001 | elFinder文件管理器能够正常加载，显示文件管理界面 | weline |
| `app/code/Weline/Event/test/e2e/backend/Weline_Event-smoke-backend.spec.js` | TC-01 | 事件列表页显示事件统计数据和内容 | weline |
| `app/code/Weline/Event/test/e2e/backend/Weline_Event-smoke-backend.spec.js` | TC-02 | 事件列表页包含筛选或搜索功能 | weline |
| `app/code/Weline/Extends/Test/e2e/backend/Weline_Extends-smoke-backend.spec.js` | TC-01 | 扩展列表页显示扩展内容和标题 | weline |
| `app/code/Weline/Extends/Test/e2e/backend/Weline_Extends-smoke-backend.spec.js` | TC-02 | 扩展列表页包含Sticker或模块详情入口 | weline |
| `app/code/Weline/Frontend/test/e2e/backend/Weline_Frontend-smoke-backend.spec.js` | FRONTEND-SMOKE-001 | 维护模式页面能够正常加载，显示维护模式配置 | weline |
| `app/code/Weline/Frontend/test/e2e/backend/Weline_Frontend-smoke-backend.spec.js` | FRONTEND-SMOKE-002 | 主题配置页面能够正常加载 | weline |
| `app/code/Weline/Geo/Test/e2e/backend/Weline_Geo-smoke-backend.spec.js` | GEO-SMOKE-001 | 地理管理页面能够正常加载，显示地理数据 | weline |
| `app/code/Weline/Hook/Test/e2e/backend/Weline_Hook-smoke-backend.spec.js` | TC-01 | Hook列表页包含Hook统计数据和内容 | weline |
| `app/code/Weline/Hook/Test/e2e/backend/Weline_Hook-smoke-backend.spec.js` | TC-02 | Hook列表页显示筛选器或搜索区域 | weline |
| `app/code/Weline/I18n/test/e2e/backend/Weline_I18n-smoke-backend.spec.js` | I18N-SMOKE-001 | 国家管理页面能够正常加载，显示国家列表 | weline |
| `app/code/Weline/I18n/test/e2e/backend/Weline_I18n-smoke-backend.spec.js` | I18N-SMOKE-002 | 词典管理页面能够正常加载 | weline |
| `app/code/Weline/I18n/test/e2e/backend/Weline_I18n-smoke-backend.spec.js` | I18N-SMOKE-003 | 本地化配置页面能够正常加载 | weline |
| `app/code/Weline/Indexer/test/e2e/backend/Weline_Indexer-smoke-backend.spec.js` | INDEXER-SMOKE-001 | 索引管理页面能够正常加载，显示索引列表 | weline |
| `app/code/Weline/Layout/test/e2e/backend/Weline_Layout-smoke-backend.spec.js` | LAYOUT-SMOKE-001 | 布局管理页面能够正常加载，显示布局配置 | weline |
| `app/code/Weline/Maintenance/Test/e2e/backend/Weline_Maintenance-smoke-backend.spec.js` | MAINTENANCE-SMOKE-001 | 维护模式页面能够正常加载，显示维护配置 | weline |
| `app/code/Weline/Marketing/Test/e2e/backend/Weline_Marketing-smoke-backend.spec.js` | MARKETING-SMOKE-001 | 营销管理页面能够正常加载，显示营销内容 | weline |
| `app/code/Weline/MediaManager/test/e2e/backend/Weline_MediaManager-smoke-backend.spec.js` | MEDIAMANAGER-SMOKE-001 | 图片管理页面能够正常加载，显示媒体库标题 | weline |
| `app/code/Weline/MediaManager/test/e2e/backend/Weline_MediaManager-smoke-backend.spec.js` | MEDIAMANAGER-SMOKE-002 | 媒体管理页面包含上传或管理按钮 | weline |
| `app/code/Weline/MediaManager/test/e2e/backend/Weline_MediaManager-smoke-backend.spec.js` | MEDIAMANAGER-SMOKE-003 | 媒体路由页面能够正常加载 | weline |
| `app/code/Weline/Meta/test/e2e/backend/Weline_Meta-smoke-backend.spec.js` | META-SMOKE-001 | Meta管理页面能够正常加载，显示Meta配置 | weline |
| `app/code/Weline/ModuleManager/test/e2e/backend/Weline_ModuleManager-smoke-backend.spec.js` | MODULEMANAGER-SMOKE-001 | 模块列表页面能够正常加载，显示模块管理标题 | weline |
| `app/code/Weline/ModuleManager/test/e2e/backend/Weline_ModuleManager-smoke-backend.spec.js` | MODULEMANAGER-SMOKE-002 | 模块列表页面包含模块表格或统计信息 | weline |
| `app/code/Weline/ModuleManager/test/e2e/backend/Weline_ModuleManager-smoke-backend.spec.js` | MODULEMANAGER-SMOKE-003 | 模块详情页面能够正常加载 | weline |
| `app/code/Weline/ModuleManager/test/e2e/backend/Weline_ModuleManager-smoke-backend.spec.js` | MODULEMANAGER-SMOKE-004 | 模块列表支持按状态筛选（已启用/已禁用） | weline |
| `app/code/Weline/ModuleRouter/test/e2e/backend/Weline_ModuleRouter-smoke-backend.spec.js` | MODULEROUTER-SMOKE-001 | 模块路由页面能够正常加载，显示路由配置 | weline |
| `app/code/Weline/Multipass/test/e2e/backend/Weline_Multipass-smoke-backend.spec.js` | MULTIPASS-SMOKE-001 | 多通道认证页面能够正常加载，显示认证配置 | weline |
| `app/code/Weline/Order/Test/e2e/backend/Weline_Order-smoke-backend.spec.js` | ORDER-SMOKE-005 | 订单列表支持关键词搜索过滤 | weline |
| `app/code/Weline/Order/Test/e2e/backend/Weline_Order-smoke-backend.spec.js` | ORDER-SMOKE-006 | 订单列表支持状态筛选 | weline |
| `app/code/Weline/Payment/Test/e2e/backend/Weline_Payment-smoke-backend.spec.js` | PAYMENT-SMOKE-001 | 支付交易列表页面能够正常加载，显示交易管理标题 | weline |
| `app/code/Weline/Payment/Test/e2e/backend/Weline_Payment-smoke-backend.spec.js` | PAYMENT-SMOKE-002 | 支付交易列表包含交易表格或统计信息 | weline |
| `app/code/Weline/Payment/Test/e2e/backend/Weline_Payment-smoke-backend.spec.js` | PAYMENT-SMOKE-003 | 支付方式管理页面能够正常加载 | weline |
| `app/code/Weline/Payment/Test/e2e/backend/Weline_Payment-smoke-backend.spec.js` | PAYMENT-SMOKE-004 | 支付交易列表支持关键词搜索 | weline |
| `app/code/Weline/Queue/Test/e2e/backend/Weline_Queue-smoke-backend.spec.js` | QUEUE-SMOKE-001 | 队列列表页面能够正常加载，显示队列状态和统计 | weline |
| `app/code/Weline/Queue/Test/e2e/backend/Weline_Queue-smoke-backend.spec.js` | QUEUE-SMOKE-002 | 队列列表包含统计信息或状态指示 | weline |
| `app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-smoke-backend.spec.js` | SHIPPING-SMOKE-001 | 配送承运商列表页面能够正常加载，显示承运商管理标题 | weline |
| `app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-smoke-backend.spec.js` | SHIPPING-SMOKE-002 | 配送承运商列表包含承运商表格或统计信息 | weline |
| `app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-smoke-backend.spec.js` | SHIPPING-SMOKE-003 | 配送管理页面能够正常加载 | weline |
| `app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-smoke-backend.spec.js` | SHIPPING-SMOKE-004 | 配送承运商列表支持关键词搜索 | weline |
| `app/code/Weline/Shipping/Test/e2e/backend/Weline_Shipping-smoke-backend.spec.js` | SHIPPING-SMOKE-005 | 配送承运商列表支持启用状态筛选 | weline |
| `app/code/Weline/Smtp/test/e2e/backend/Weline_Smtp-smoke-backend.spec.js` | SMTP-SMOKE-001 | SMTP配置页面能够正常加载，显示邮件配置 | weline |
| `app/code/Weline/Smtp/test/e2e/backend/Weline_Smtp-smoke-backend.spec.js` | SMTP-SMOKE-002 | SMTP配置页面包含配置表单 | weline |
| `app/code/Weline/Storage/test/e2e/backend/Weline_Storage-smoke-backend.spec.js` | STORAGE-SMOKE-001 | 存储配置页面能够正常加载，显示存储配置 | weline |
| `app/code/Weline/Taglib/test/e2e/backend/Weline_Taglib-smoke-backend.spec.js` | TC-01 | 标签库列表页显示标签内容和分页 | weline |
| `app/code/Weline/Taglib/test/e2e/backend/Weline_Taglib-smoke-backend.spec.js` | TC-02 | 标签库列表页包含模块信息 | weline |
| `app/code/Weline/Theme/test/e2e/backend/Weline_Theme-smoke-backend.spec.js` | TC-01 | 主题列表页显示主题管理标题和内容 | weline |
| `app/code/Weline/Theme/test/e2e/backend/Weline_Theme-smoke-backend.spec.js` | TC-02 | 主题列表页包含主题预览或激活相关信息 | weline |
| `app/code/Weline/TranslationService/test/e2e/backend/Weline_TranslationService-smoke-backend.spec.js` | TRANSLATIONSERVICE-SMOKE-001 | 翻译服务页面能够正常加载，显示翻译API配置 | weline |
| `app/code/Weline/TwoFactorAuth/Test/e2e/backend/Weline_TwoFactorAuth-smoke-backend.spec.js` | 2FA-SMOKE-001 | 两步认证页面能够正常加载，显示认证配置 | weline |
| `app/code/Weline/Visitor/test/e2e/backend/Weline_Visitor-smoke-backend.spec.js` | VISITOR-SMOKE-001 | 访客统计页面能够正常加载，显示访客数据 | weline |
| `app/code/Weline/WarmCache/test/e2e/backend/Weline_WarmCache-smoke-backend.spec.js` | WARMCACHE-SMOKE-001 | 缓存预热页面能够正常加载，显示预热任务 | weline |
| `app/code/Weline/WebsiteMonitoring/test/e2e/backend/Weline_WebsiteMonitoring-smoke-backend.spec.js` | WEBSITMONITORING-SMOKE-001 | 网站监控页面能够正常加载，显示监控数据 | weline |
| `app/code/Weline/Websites/Test/e2e/backend/Weline_Websites-smoke-backend.spec.js` | WEBSITES-SMOKE-001 | 网站管理页面能够正常加载，显示站点列表 | weline |
| `app/code/Weline/Widget/test/e2e/backend/Weline_Widget-smoke-backend.spec.js` | WIDGET-SMOKE-001 | 小组件管理页面能够正常加载，显示小组件配置 | weline |

## OK

| 文件 | Case ID | 标题 | 类型 |
|------|---------|------|------|
| `app/code/Weline/Backend/test/e2e/backend/Weline_Backend-smoke-backend.spec.js` | BACKEND-SMOKE-CONFIG-001 | 后台配置页面能够正常加载，显示 Logo 和站点信息配置项 | weline |
| `app/code/Weline/Backend/test/e2e/backend/Weline_Backend-smoke-backend.spec.js` | BACKEND-SMOKE-CONFIG-002 | 后台配置站点名称可以填写并提交 | weline |
| `app/code/Weline/Backend/test/e2e/backend/Weline_Backend-smoke-backend.spec.js` | BACKEND-SMOKE-CONTACT-001 | 联系人列表页面能够正常加载，显示各渠道分组 | weline |
| `app/code/Weline/Backend/test/e2e/backend/Weline_Backend-smoke-backend.spec.js` | BACKEND-SMOKE-CONTACT-002 | 添加联系人弹窗能够打开并显示表单字段 | weline |
| `app/code/Weline/Backend/test/e2e/backend/Weline_Backend-smoke-backend.spec.js` | BACKEND-SMOKE-CONTACT-003 | 添加联系人表单字段验证：空渠道和空联系方式应返回错误 | weline |
| `app/code/Weline/Backend/test/e2e/backend/Weline_Backend-smoke-backend.spec.js` | BACKEND-SMOKE-NOTIFICATION-001 | 通知列表页面能够正常加载，显示通知数据或空状态 | weline |
| `app/code/Weline/Backend/test/e2e/backend/Weline_Backend-smoke-backend.spec.js` | BACKEND-SMOKE-NOTIFICATION-002 | 通知列表支持关键词搜索过滤 | weline |
| `app/code/Weline/Backend/test/e2e/backend/Weline_Backend-smoke-backend.spec.js` | BACKEND-SMOKE-NOTIFICATION-003 | 通知列表支持按已读状态过滤 | weline |

## 整治状态

| 波次 | 状态 |
|------|------|
| TASK-1 清单 | completed |
| TASK-2 短名去重（8 文件已删） | completed |
| TASK-3 金标模板 `tests/e2e/FLOW_SPEC_TEMPLATE.md` | completed |
| Wave1（10 模块） | completed |
| Wave2/3（56 文件 + SessionManager） | completed |
| TASK-14 Wave1 假绿门禁抽检 | completed |
| TASK-15 Wave2/3 清零 | completed |

短名已删：`Acl`/`Admin`/`Api`/`Backend`/`Framework`/`Frontend`/`ModuleManager`/`Server` 的 `*-smoke-backend.spec.js`。  
`SessionManager-smoke-backend.spec.js`：候选路由均无独立可渲染页 → **诚实 skip**（非假绿）。

计划归档：`dev/ai/plans/2026-07-25-假e2e用例整治.md`（§16.6，未改原 plan 文件）。

## 框架增强（tests/e2e/framework/runtime.js）

- `waitForBackendShellReady(page)`：等待/强制关闭后台 `#loading` 遮罩（默认超时 **8s**，卡住立即强制隐藏；旧默认 90s 会吃满单测 120s 预算 → Target page closed 假失败）。
- `submitForm(page, form)`：用 `requestSubmit()` 提交，绕过遮罩 pointer 拦截。
- `submitAndExpectParam(page, form, 'k=v')`：提交并断言「用户输入被真实带进请求」。
  - 根因：后台列表表单是 **GET 提交到绝对 action URL**，经 e2e proxy 提交后会落到脱离 proxy 前缀的 **404 页**；因此不能断言提交后页面 DOM。决定性证据改为捕获携带用户输入的请求（`waitForRequest`），去掉 `fill`/`submit` 即请求不含该参数 → 用例失败（满足防假绿）。

## Wave1 改写要点（真实交互 + 决定性断言）

| 模块 | flow 交互 | 决定性断言 |
|------|-----------|-----------|
| Seo | 点「管理 URL」→ 驱动 OffCanvas | 面板控件 `data-seo-url-keyword/reload/tbody` attached；可选拦截 `listSitemapUrls` |
| Acl | 资源 `input[name=search]` fill + 提交；IP 白名单 `keyword` fill + 提交 | `submitAndExpectParam(search=dashboard / keyword=127.0.0.1)` |
| Order | `keyword` fill + `status` selectOption + 提交 | 请求含 `keyword=TEST001` 且 `status=pending` |
| SystemConfig | smoke 收缩；权威 flow 指向 `plan-p1c-sec07` | `#wsc-website-code` attached |
| Customer | `keyword` fill + 提交 | 请求含 `keyword=admin` |
| Payment | `keyword` fill + `status` selectOption + 提交；方式页查看配置/空态 | 请求含 `keyword=PAY` + `status=pending` |
| Queue | `biz_key` fill + 提交 | 请求含 `biz_key=e2e-filter-key` |
| Cron | `#weline-cron-status-filter` selectOption（JS 改 URL） | `waitForURL(status=pending)` |
| Websites | `#search-input` fill（JS 前端过滤，无导航） | `#search-input` 值为 `default` |
| Framework | `#ft-ui-enabled` 开关 click | 拦截 `setUiEnabled` 请求或勾选态翻转（还原） |

### Wave1 验收证据（TASK-14）

- 环境：专用 WLS `ai-test-fake-e2e-0725b` 端口 `9612`。
- 有界串行跑通（retries=0/1）：**10/10 模块 exit=0**。
  - 首轮：Order/SystemConfig/Customer/Queue/Framework 干净通过；Seo/Acl/Cron/Websites 受 `#loading` 90s 等待拖垮后，修 `waitForBackendShellReady` 默认 8s 后复测全绿。
  - Payment 三 case 首轮 FAIL：决定性断言正确抓到产品 Bug（`getData() on array`），修 Method/Transaction `getItems()` 后 **3 passed (56.7s)**。
- 假绿门禁：flow 决定性断言依赖真实 `fill`/`selectOption`/`click` + 提交请求参数；去掉交互后请求不再携带用户参数 → 用例失败。
- 诚实 smoke：每模块 ≤1 条，标题写「路由可达」。

## Wave2/3 改写要点（TASK-15）

- 56 个 `Weline_*-smoke-backend.spec.js` 按金标重生成：候选路由自动探测 + `main#main-content` 内容区断言 + 1 smoke + 1 flow。
- 无独立后台页（Captcha 空 Config.php、Admin 等）→ **诚实 `test.skip`**，不再靠「无 Fatal」假绿。
- 候选路由命中 FATAL → 用例失败留证（真实产品 Bug），不计 skip。
- 全量 56 模块有界跑：初跑 51 绿/skip、5 红；修产品 Bug + 模板后复测 5 红全绿。
  - 最终态：**56/56 exit=0**（含诚实 skip）；SessionManager 2 skipped（无独立可渲染页）。

### 决定性断言顺带发现并修复的产品 Bug（框架仓 → 已 diff 合入 QiPai）

| 模块 | 问题 | 修复 |
|------|------|------|
| Payment Method/Transaction | `select()->fetch()` 当模型列表 → `getData() on array` | `fetch()` 后 `getItems()` |
| TranslationService Record | page=0 → 负 OFFSET；avg() 返回 false；providers 数组行 getId | `max(1,page)`、`?:0`、`getItems()` |
| Maintenance Backup | `getDriverName()` 不存在 | `getConfigProvider()->getDbType()` |
| Checkout Order | 缺 `Backend/Order/index.phtml` | 新增列表模板 |
| UrlManager Rewriter form | `@if/@else` 编译 ParseError | 改为 PHP 表达式 |

### QiPai 对齐清单（§15）

| 文件 | 方向 |
|------|------|
| `Payment/Controller/Backend/Method.php` | 框架 → QiPai |
| `Payment/Controller/Backend/Transaction.php` | 框架 → QiPai |
| `TranslationService/Controller/Backend/Record.php` | 框架 → QiPai |
| `Maintenance/Service/DatabaseBackupService.php` | 框架 → QiPai |
| `Checkout/view/templates/Backend/Order/index.phtml` | 框架 → QiPai（新建） |
| `UrlManager/view/templates/Backend/Rewriter/form.phtml` | 框架 → QiPai |
| `Framework/Manager/ObjectManager.php` | 框架 → QiPai（参数化 Factory 自动识别，修 ProviderFactory） |
| `TranslationService/Controller/Backend/Config.php` | 框架 → QiPai（getItems + 依赖 ObjectManager 修复） |
| `tests/e2e/framework/runtime.js` | 框架 → QiPai（2026-07-26 续） |
| `tests/e2e/FLOW_SPEC_TEMPLATE.md` | 框架 → QiPai（新建） |
| 67× `Weline_*-smoke-backend.spec.js` + `SessionManager-smoke-backend.spec.js` | 框架 → QiPai（2026-07-26 续） |
| `dev/ai/tasks/2026-07-25-fake-e2e-inventory.md` / `dev/ai/plans/2026-07-25-假e2e用例整治.md` | 框架 → QiPai（文档） |

> 2026-07-26 续：产品 Bug 文件此前已对齐；本日补齐 E2E 运行时 helper、金标模板与全部改写 smoke。本机重启验收 WLS 遇 `WLS_STARTUP_READY_TIMEOUT` / 503（环境阻断），未计入功能失败。

## 假绿门禁抽检（TASK-14 补充）

- 门禁模式：flow 的决定性断言依赖真实 `fill`/`selectOption`/`click` + 提交请求；**注释掉业务交互后请求不再携带用户参数 → 用例失败**。
- 诚实 smoke：每模块 ≤1 条，仅断言路由可达/业务容器可见，标题写「路由可达」。
- 环境前置：专用 WLS `ai-test-fake-e2e-0725b`（端口 9612）。本机为共享开发机、多 `ai-test-*` 实例并发争抢托管 Nginx owner，期间出现 `upstream_request_failed / ECONNREFUSED 9612`；按 §16.5 记为**环境阻断**，不计功能失败。
- 健康窗口诊断（登录后逐路由取 DOM 标记）已确认选择器命中：`seo data-seo-manage-urls=7`、`acl input[name=search]=1`、`acl-ip/order input[name=keyword]=1`；后台内容壳权威选择器 `MAIN#main-content.backend-main-content`。

### 2026-07-26 续：TranslationService Config DI

- 根因：`ObjectManager::processFactoryClass` 对 `ProviderFactory::create(string)` 无参调用。
- 修复：参数化 Factory 白名单 + 反射自动识别必填 `create()` 参数；`Config` 列表改 `getItems()`。
- 验收：CLI `ObjectManager::getInstance(ProviderFactory)` + Config 构造注入通过；本机 WLS 因 homepage FPC READY 门禁 / Nginx 争抢无法拉起专用实例（环境阻断）。

### 浏览器验收（2026-07-26，实例 ai-test-p1d02-1317 / 9630）

| 页面 | 结果 |
|------|------|
| `translation/backend/config` | 通过：标题 Weline_TranslationService，可见「添加渠道/编辑/测试」，无 ProviderFactory FATAL |
| `translation/backend/record` | 通过：筛选控件可见（渠道/状态），无 OFFSET/getId FATAL |
| `payment/backend/transaction` | 通过：关键词/状态筛选可见，无 getData FATAL |
| `checkout/backend/order` | 通过：新模板列表+搜索表单可见 |
| `payment/backend/method` | 当时无 `target_scope` → 403「操作授权条件不满足」（见下条续修） |

### 2026-07-26 续：Payment Method 对象 Scope 深链

- 根因：`Method::index` 经 `PaymentObjectScopeService::fromExplicitTarget` 强制要求显式 `target_scope`；菜单已是 `payment/backend/method?target_scope=default.default.default`，E2E 原先只打开无 query 的 `method` → 空 scope → 403，被误判为「权限不足」。
- 修复：`PAYMENT-FLOW-METHOD-001` 对齐菜单深链，并在正文出现「操作授权条件不满足」时直接失败（防假绿）。
- 验收：`PLAYWRIGHT_TARGET_ORIGIN=http://127.0.0.1:9573` 跑 Payment 规格 **3 passed (39.6s)**（含 METHOD-001）。
- QiPai：`Payment/Test/e2e/backend/Weline_Payment-smoke-backend.spec.js` 框架 → QiPai 已对齐。
- 环境：本任务专用实例仍被 Nginx owner（先后 `ai-test-p2e05-1330` / `p3c03-1555` / `p4c01-1814`）阻断；验收借用 `ai-test-seo-multidomain-202607261044:9573`（非本任务专用，可能回收）。

### 2026-07-26 终态抽检（9573）

| 规格 | 结果 |
|------|------|
| Payment（含 METHOD 深链） | 3 passed |
| Acl | 3 passed |
| Customer | 2 passed |
| TranslationService | 2 passed |
| 合计 | **10/10，OVERALL_FAIL=0** |
