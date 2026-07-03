# 模块间通信规则改造计划

**状态**：🟡 进行中（status: in_progress）  
**当前阶段**：阶段一 - 计划与识别  
**完成度**：20%  
**最后更新**：2025-03-03

## 一、背景与目标

### 1.1 新规则

- **禁止**：模块间直接调用其他模块的类（Model、Service、Controller 等）
- **推荐**：使用框架 `w_query()` / `FrameworkQueryService` 及 QueryProvider 进行模块间通信
- **例外**：模块可直接调用 `Weline\Framework` 命名空间下的类（框架核心）

### 1.2 规则范围说明

| 类型 | 是否违反规则 | 说明 |
|------|-------------|------|
| `use Weline\Framework\*` | ❌ 不违反 | 框架核心，可随意调用 |
| 直接 new / 注入其他模块的 Model/Service | ✅ 违反 | 应改为 w_query |
| 实现其他模块的接口（implements TaglibInterface 等） | ❌ 待定 | 框架扩展点，通常保留 |
| 继承其他模块的基类（extends EavModel） | ⚠️ 待评估 | 结构性依赖，改造成本高 |

### 1.3 命中技能

| 技能 | 路径 | 说明 |
|------|------|------|
| unified-query-provider | `dev/ai/skills/unified-query-provider/SKILL.md` | QueryProvider 实现与调用规范 |
| create-plan | `dev/ai/skills/create-plan/SKILL.md` | 计划编写规范 |
| module-development | `dev/ai/skills/module-development/SKILL.md` | 模块结构与 extends 路径 |

---

## 二、跨模块调用全景（已识别）

### 2.1 调用方 → 被调用方矩阵

| 调用方模块 | 被调用方模块 | 调用类型 | 涉及文件（示例） | 优先级 |
|-----------|-------------|---------|-----------------|--------|
| Weline_Backend | Weline_SystemConfig | 直接注入 Model | Config.php | P0 |


| WeShop_Store | Weline_Websites | 直接使用 Model | LeadSearchSourceTypeCollector.php | P0 |
| WeShop_Frontend | Weline_Theme | 直接使用 Helper/Model | BaseController.php | P0 |
| WeShop_Product | Weline_Theme | 直接使用 Helper/Model | ProductLayoutScanner.php | P0 |
| Weline_Frontend | Weline_Backend | 直接使用 Model | FrontendUser.php | P0 |
| Weline_DeveloperWorkspace | Weline_Api | 直接使用 Service | ApiDocImporter.php | P1 |
| Weline_CKEditorEditorManager | Weline_Backend | 直接使用 Model | Install.php | P1 |
| Weline_Seo | WeShop_Store | 直接使用 Model | StoreSaveAfter.php | P0 |
| Weline_AutoLeadAgent | WeShop_Store | 直接使用 Model | StoreProfileService, Index.php | P0 |
| WeShop_* 多子模块 | Weline_Eav | 继承/直接使用 Model/Service | Product, Filters, Catalog, Queue 等 | P2 |
| WeShop_* 子模块间 | WeShop_* 其他子模块 | 直接调用 | Filters↔Product, Search↔Product 等 | P1 |


| Weline_FileManager | Weline_Eav | 直接使用 Model | Install.php, File.php (Ui) | P2 |
| Weline_Queue | Weline_Eav | 直接使用 Model | Queue.php, Type.php 等 | P2 |
| Weline_ModuleManager | Weline_Eav | 直接使用 Model | ModelUpdateAfter.php | P2 |

### 2.2 现有 QueryProvider（可复用）

| Provider | 模块 | 说明 |
|----------|------|------|
| websites | Weline_Websites | 域名、注册商、账号等 |
| widget | Weline_Widget | Widget 列表等 |
| saas | Weline_Saas | 租户等 |
| i18n | Weline_I18n | 国际化 |
| cdn | Weline_Cdn | CDN 相关 |
| crud | Weline_Framework | 通用 CRUD（DefaultCrudProvider） |

### 2.3 需新增的 QueryProvider

| Provider 名 | 模块 | 主要 operations | 优先级 |
|------------|------|-----------------|--------|
| system_config | Weline_SystemConfig | getConfig, setConfig | P0 |
| theme | Weline_Theme | getThemeConfig, getCurrentTheme 等 | P0 |
| backend | Weline_Backend | getConfig（包装 system_config 或独立） | P1 |
| store | WeShop_Store | getStoreById, getStoreList 等 | P0 |
| eav | Weline_Eav | getAttributes, getEntity, getAttributeSet 等 | P2 |
| product | WeShop_Product | getProductById, getProductList 等 | P1 |
| catalog | WeShop_Catalog | getCategoryById, getCategoryTree 等 | P1 |

| blog | GuoLaiRen_Blog | getPostById, getCategoryList 等 | P1 |
| api | Weline_Api | getApiDoc 等（如需要） | P1 |

---

## 三、改造任务拆分

### 阶段一：P0 - 核心配置与基础模块 ✅

- [x] 1. Weline_SystemConfig 实现 SystemConfigQueryProvider
- [x] 2. Weline_Backend 改造 Config.php，改用 w_query('system_config', ...)

- [x] 4. Weline_Websites 扩展 WebsitesQueryProvider：getWebsiteById, getWebsiteList, getWebsiteLanguageCodes

- [x] 6. WeShop_Store 实现 StoreQueryProvider
- [x] 7. WeShop_Store LeadSearchSourceTypeCollector 改用 w_query('websites', 'i18n', ...)
- [x] 8. Weline_Theme 实现 ThemeQueryProvider
- [x] 9. WeShop_Frontend BaseController 改用 w_query('theme', ...)
- [x] 10. WeShop_Product ProductLayoutScanner 改用 w_query('theme', 'scanThemeLayoutsByType')
- [ ] 11. Weline_Backend 实现 BackendQueryProvider（或通过 system_config 间接）
- [ ] 12. Weline_Frontend FrontendUser 改用 w_query('backend', ...) 或 system_config
- [x] 13. Weline_Seo StoreSaveAfter 改用鸭式类型检查（去除 Store import）
- [x] 14. Weline_AutoLeadAgent 改用 w_query('store', ...)

### 阶段二：P1 - 业务模块间

- [x] 10b. WeShop_Product ProductLayoutScanner 改用 w_query('theme', 'scanThemeLayoutsByType')


- [x] 15. WeShop_Product 实现 ProductQueryProvider（getProductById, getPriceStats, filterByPriceRange, countByPriceRange）
- [x] 17a. WeShop_Filters PriceFilterProvider 改用 w_query('product', ...)
- [x] 16. WeShop_Catalog CatalogQueryProvider 扩展 getCategoryNames, getAllDescendantCategoryIds
- [x] 17. WeShop_Filters EAV Filter（Color/Size/Brand/Material）改用 w_query
- [ ] 19. GuoLaiRen_Blog 实现 BlogQueryProvider

- [ ] 21. Weline_DeveloperWorkspace ApiDocImporter 改用 w_query('api', ...) 或保留（若 Api 为框架级）
- [ ] 22. Weline_CKEditorEditorManager Install 改用 w_query('backend', ...)

### 阶段三：P2 - Eav 与深度依赖（可选/分步）

- [ ] 23. Weline_Eav 实现 EavQueryProvider（getAttributes, getEntity 等）
- [ ] 24. WeShop_Product 等对 Eav 的**查询类**调用改为 w_query('eav', ...)
- [ ] 25. 继承 EavModel 的改造：评估是否保留（结构性依赖，或通过接口抽象）

---

## 四、决策与自审

### 决策 1：EavModel 继承是否改造

| 问题 | 分析 |
|------|------|
| 为什么？ | Product、Category 等继承 EavModel 是结构性设计 |
| 收益 | 完全解耦可减少模块依赖 |
| 风险 | 改造量极大，需重构 Eav 架构 |
| 影响 | WeShop_Product、WeShop_Catalog、Weline_Queue 等 |
| 结论 | **本计划暂不改造继承关系**，仅改造**直接调用其他模块 Service/Model 的代码** |

### 决策 2：TaglibInterface 等接口依赖

| 问题 | 分析 |
|------|------|
| 结论 | **不改造**。implements TaglibInterface 属于框架扩展点，非业务类直接调用 |

---

## 五、执行顺序建议

1. 先实现 P0 的 QueryProvider，再改调用方
2. 每个 Provider 实现后执行 `php bin/w extends:rebuild` 或 `setup:upgrade`
3. 单元测试或 HTTP 测试验证改造正确性

---

## 六、涉及文件清单（P0 详细）

| 序号 | 文件路径 | 改造内容 |
|------|---------|---------|
| 1 | app/code/Weline/SystemConfig/extends/module/Weline_Framework/Query/SystemConfigQueryProvider.php | 新建 |
| 2 | app/code/Weline/Backend/Model/Config.php | 改用 w_query |

| 4 | app/code/Weline/Websites/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php | 新增 getWebsiteById, getWebsiteList |

| 6 | app/code/WeShop/Store/extends/module/Weline_Framework/Query/StoreQueryProvider.php | 新建 |
| 7 | app/code/WeShop/Store/Observer/LeadSearchSourceTypeCollector.php | 改用 w_query |
| 8 | app/code/Weline/Theme/extends/module/Weline_Framework/Query/ThemeQueryProvider.php | 新建 |
| 9 | app/code/WeShop/Frontend/Controller/BaseController.php | 改用 w_query |
| 10 | app/code/WeShop/Product/Helper/ProductLayoutScanner.php | 改用 w_query |
| 11 | app/code/Weline/Frontend/Model/FrontendUser.php | 改用 w_query |
| 12 | app/code/Weline/Seo/Observer/StoreSaveAfter.php | 改用 w_query |
| 13 | app/code/Weline/AutoLeadAgent/Service/StoreProfileService.php | 改用 w_query |
| 14 | app/code/Weline/AutoLeadAgent/Controller/Backend/Index.php | 改用 w_query |
