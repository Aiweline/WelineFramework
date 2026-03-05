# AI 中心人性化精简与改造计划

**状态**：🟢 已完成（status: completed）
**创建时间**：2026-03-03
**完成度**：100%

---

## 〇、不可动范围（硬性约束）

以下模块/逻辑**禁止修改**，改造仅涉及菜单、Manager 聚合页、模型表单等，不得影响核心能力：

| 范围 | 说明 |
|------|------|
| **智能体（Agent）** | Agent 相关逻辑、控制器、服务、模型全部不动 |
| **流式输出（Streaming）** | 流式输出、SSE 等逻辑不修改 |
| **模型收集能力** | `Model::collect()`、`ModelCollector::collectAllModels()`、CLI `ai:model:collect`、`SetupUpgradeAfter` Observer、Cron `ModelSync` 保留，仅移除 UI 入口「收集模型」按钮 |
| **清空能力** | `Model::clearAll()` 控制器方法保留，仅移除 UI 入口「清空模型表」按钮 |
| **路由** | `ai/backend/model`、`ai/backend/adapter`、`ai/backend/provider`、`ai/backend/defaultmodel` 等路由保留，供直接访问、iframe 嵌入、ErrorMessageHelper、AiService、Assistant 表单、Blog AiPublish 等引用 |

---

## 一、风险点与影响范围

### 1.1 外部模块依赖

| 依赖方 | 路径 | 用途 | 影响 |
|--------|------|------|------|
| **Aws_Domains** | `app/code/Aws/Domains/etc/backend/menu.xml` 第 32 行 | 域名供应商菜单 `action="ai/backend/provider"` | 需确认：域名供应商为何指向 AI 供应商账户？若为误配需修正；若为业务集成则保持，路由仍存在 |
| **GuoLaiRen/Blog** | `Cron/AiPublish.php` 349-351 行 | 配置检查时生成 defaultmodel、model、provider 链接 | 无影响，直接 URL 保留 |
| **AiService / ErrorMessageHelper** | `Service/AiService.php`、`Helper/ErrorMessageHelper.php` | 错误提示中链接到 model/provider/defaultmodel | 无影响 |
| **Assistant 表单** | `Backend/Assistant/form.phtml` 375 行 | 「配置模型」跳转 `ai/backend/model` | 无影响 |

### 1.2 「返回 AI 管理」链接失效风险

多处模板使用 `getBackendUrl('ai/backend/index')` 作为「返回 AI 管理」：

- TrainingData、SecurityScan、ModelDeployment、ModelVersioning、DeveloperTools、AbTesting、ContentSafety、CustomerSupport、ThirdPartyIntegration、Rating 等

**问题**：`ai/backend` 可能无 Index 控制器，`ai/backend/index` 可能 404。

**建议**：新建 `Index.php` 控制器，`index` action 重定向到 `ai/backend/manager`；或批量将上述链接改为 `ai/backend/manager`。实施时二选一落实。

### 1.3 E2E 测试失败

`app/code/Weline/Ai/Test/e2e/backend/ai-model-sync.spec.js` 第 25 行断言「收集模型」按钮存在。移除该按钮后用例必然失败。

**建议**：将断言改为「新增模型」按钮或其它保留按钮，或删除/重写该用例。

### 1.4 ACL 与菜单同步

删除 `menu.xml` 中的菜单项后，执行 `setup:upgrade` 以同步 `weline_acl`。实施后需验证权限与菜单显示是否一致。

### 1.5 iframe 嵌入与 embed 参数

Manager 计划用 iframe 加载 `ai/backend/model?embed=1` 等。需确认 Model / Adapter / Provider 是否支持 `embed=1`（如隐藏标题、面包屑、独立布局），否则需在开发任务中明确实现。

---

## 二、概念澄清（实施前需明确）

| 概念 | 待明确 | 说明 |
|------|--------|------|
| **场景配置** | 是否 = 场景适配器 `ai/backend/adapter`？ | 若不同，需单独定义入口与路由 |
| **默认模型** | 入口放在 Manager「模型」Tab 内，还是独立？ | 若独立，是否保留菜单入口 |
| **助手管理** | 菜单删除后入口策略？ | 方案 A：仅保留直接访问 `ai/backend/assistant`；方案 B：Manager 增加「助手」Tab。当前计划仅保留 AI 管理、场景配置，需明确用户如何进入助手 |

---

## 三、ACL 与权限设计

- **Manager 页**：新建 `Weline_Ai::ai_manager`（或等价 source_id），`parent_source` 为 `Weline_Ai::ai`。
- **iframe 嵌入**：子页面 model/adapter/provider 仍独立做 ACL 校验；Manager 权限与各 Tab 内容权限关系需设计清晰。
- **删除菜单的 ACL**：`setup:upgrade` 会同步，实施后需验证是否还有代码引用已删的 source_id。

---

## 四、开发规范约束

- **theme-development**：新 Manager 及 Tab 内容必须使用 `var(--backend-color-*)`，禁止硬编码颜色，兼容暗色模式。
- **friendly-notifications**：使用 `BackendToast` / `BackendConfirm`，禁止 `alert` / `confirm` / `prompt`。
- **JS**：IIFE 闭包，禁止全局变量污染。
- **WLS 状态管理**：若 Manager 引入请求级 static 变量，须在 StateManager 注册并在请求结束时重置；实施时做一次排查。
- **文档**：`doc/用户/AI模块使用手册.md`、`doc/开发/` 中的菜单路径与页面结构需同步更新。

---

## 五、实施顺序建议

1. Manager 控制器 + 模板，Tab 聚合 + URL 持久化
2. model/adapter/provider 支持 `embed=1`
3. 精简 menu.xml，处理 `ai/backend/index` 重定向
4. 模型表单改造、getProviderInfo、Provider Offcanvas
5. 更新 E2E、文档、验证 ACL 与缓存

---

## 六、改造目标（概要）

1. 精简菜单：仅保留「AI 管理」「场景配置」
2. 新建 Manager 聚合页：模型 | 适配器 | 供应商账户 三个 Tab，URL 持久化 `?tab=model/adapter/account`
3. 模型新建：支持 `id=0`、供应商/账户可搜索选择、表单旁快速新建账户、移除「收集模型」「清空模型表」，增加「新增模型」
4. Provider 支持 Offcanvas 编辑与 `embed=1` 嵌入
5. 新增 `getProviderInfo` 接口，选择供应商后自动填充
6. 智能体、流式输出逻辑不修改（见〇、不可动范围）

---

## 七、任务清单（待细化）

- [x] 精简 menu.xml，仅保留 AI 管理、场景配置
- [x] 新建 Manager 控制器和 index 模板，Tab 聚合 + URL 持久化
- [x] Model postSave 支持 id=0 新建逻辑
- [x] 模型表单：供应商 theme:search-select + 旁侧新建账户按钮
- [x] 模型表单：账户可搜索选择，随供应商联动
- [x] 模型列表：移除收集/清空，增加新增模型按钮
- [x] 新增 getProviderInfo 接口
- [x] Provider 支持 editOffcanvas 与 embed
- [x] 处理 ai/backend/index 重定向或链接替换
- [x] 更新 E2E 测试 ai-model-sync.spec.js
- [x] 更新 doc/用户/AI模块使用手册.md 及 doc/开发/ 中菜单路径
- [x] Manager ACL 注册（Weline_Ai::ai_manager）
- [x] 执行 setup:upgrade --route
