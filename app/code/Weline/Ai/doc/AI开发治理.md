# AI 开发治理

## 权威顺序

知识冲突按以下顺序裁决：

1. 当前任务中用户已确认的需求与授权；
2. 当前源码、配置、测试和真实运行证据；
3. 归属模块 `doc/` 的当前有效需求与专题合同；
4. `Weline_Ai` 全局治理与 Framework 开发标准；
5. 客户端短适配说明。

同级冲突无法由证据证明时，`prepare_project` 或迁移检查必须阻断并返回一次性报告，不能自行选择方便的一侧。

## 模块文档契约

每个 `app/code/{Vendor}/{Module}` 知识单元至少维护：

- `doc/README.md`：模块定位、公开入口、边界和专题文档导航；
- `doc/需求.md`：当前有效需求、目标版本、验收标准和待确认项；
- `doc/开发日志.md`：按版本记录实现范围、审查、测试、运行验收和发布状态；
- 必要专题文档：API、架构、运营、迁移和故障处理。

文档只记录可证明事实。源码入口存在不等于产品已验收；没有证据的历史必须写“待确认”，不能由 AI 补造。任务临时记录、原始聊天、命令日志、Archive 和客户端规则不进入长期知识。

## 范围与安全

- 保留无关工作区改动，只修改任务授权范围。
- MCP 自愈、宿主重载、插件代次刷新与崩溃恢复必须保留任务开始前已存在的 tracked、staged、untracked 与 ignored 脏改；**宿主 Agent Shell 禁止**为「清场 / 对齐 HEAD」执行 `git checkout --` / `restore` / `clean` / `stash` 擦脏。MCP 内部只允许只读 Git 检查，禁止包括分支切换在内的全部 Git 写操作，也禁止 config/helper/pager 命令注入及任何 force/discard 变体。权威：`preserve_dirty_workspace` / [AI硬规则索引](./AI硬规则索引.md)。
- 不使用假数据、隐藏开关、静默降级、弱化断言或切换 Provider/账号来制造成功。
- 不在文档、会话指令或索引反馈中保存凭据和个人/生产数据。
- 本地可回退实现和隔离验证可作为正常开发步骤；提交、推送、发布、部署、外部消息及生产数据变更仍需要用户明确授权。
- 生成物由源文件或生成器更新，不能直接编辑为长期修复。

## 框架仓交付契约

- WelineFramework 核心的规范 macOS 仓库是 `/Users/weline/Project/Official/框架`；`app/code/Weline/**` 的持久核心变更归属该仓。
- 本地 Git 只使用 `dev` 与 `master`；代码修改只在 `dev`，不创建其他分支或 worktree。工程任务须运行 `ensure-project-guidance.php` 挂载 MCP（写出冷启动 `.mdc`），再 `prepare_project`；**编码仍用宿主原生编辑**。MCP 自行修复宿主注册与代次，不得让用户先去 Settings 配 MCP，但 MCP 不自动切换分支。`prepare_project` 在检测到 `dev` 分支存在时会阻断非 `dev` 当前分支（含 `master`），由工作区所有者显式决定是否执行 `git switch dev`；在 `dev` 完成修改并提交后，再合并到 `master` 并推送。
- MCP 提供索引、代码地图、技能与**领域硬规则下发**；**不是**写码工具。工程任务在 MCP 已挂载/可挂载时**必须**读 `prepare_project.agent_guidance.hard_constraints`（`hard-constraints.v1`）；权威由 [AI硬规则索引](./AI硬规则索引.md) 编译；宿主引导与 `session_startup_notices` 只指路。MCP 未挂载或检索失败时，宿主 Read 权威文档继续开发；不得假装已遵守 MCP，不得借此绕过 `blocked`、`dev` 分支、文档/响应式/真实运行验收或部署权限。
- 提交信息使用简体中文；提交前必须保留并分离用户的无关工作区变更。
- 核心仓修改不代表自动分发到消费站点；只能在用户明确要求的分项、回灌、部署或指定发布流程中执行。
- 不存在与已退役站点仓的默认双仓合并或对齐流程。

## 实现与验收

- 需求先进入归属模块 `需求.md`，再修改代码；结果同步 `开发日志.md`。
- **每个功能收口前**必须对照归属模块 `doc/`（README / 需求 / 开发日志 / 专题）与实现：有差异则改文档或代码，禁止功能已交付而文档未跟上。
- **每个功能交付时**须在汇报列出前台、后台与 API/Query 地址；主验收必须是 **可直接打开的 http(s) Markdown 链接**（`[名称](http(s)://…)`），禁止把 `command:simpleBrowser.api.open` 等宿主私有伪协议当作唯一/主链；禁止仅写变色「打开」文字；无 UI 标注 N/A。本机默认 Host 为 `{project_hash}.test.weline.com`（例 `http://p05113ef3.test.weline.com:9555/...`）；**禁止**主验收使用 `*.weline.test`；仅无 `*.test.weline.com` 时才用 `127.0.0.1`。验收 Browser **每次打开/导航前须禁用 HTTP 缓存**（`browser_cache_disabled_on_open`）。写完「交付地址」后须**立即关闭**本回合验收 Browser（`browser_release_after_delivery`）。见 [WebUI浏览器验收与交付地址门禁.md](../Framework/doc/3-开发/WebUI浏览器验收与交付地址门禁.md) 与 MCP `feature_delivery_urls`。
- Web/UI 变更在设计阶段就要纳入平板（≈768）与 PC（≥1024）响应式（兼顾 375），验收收集多断点证据。
- AI 客户端执行开发任务时必须遵循 [AI工程交付流程](./AI工程交付流程.md) 的阶段顺序（引导 → 定位 → 扩展点选型 → 设计 → 原生实现 → 分层验收 → 收口）；MCP 通过 `workflow_contract.v1` 附带流程摘要；工程任务须先读 `agent_guidance.hard_constraints`（`session_startup_notices` 只指路）。
- 新增功能按“实现 → 架构/缺陷/安全复审 → 整改并复审 → 分层测试 → 真实运行路径 → 可重复回归”串行完成。
- 局部纯逻辑至少通过聚焦测试；用户可见页面和整体产品流程必须在真实 WLS 中按操作员等价 WebUI 路径验证，CLI、curl、数据库或日志不能替代。
- 未完成相应层级测试时，只能报告“代码已改，测试未完成”或“WebUI 验收未完成”。

框架代码规范、模块边界、模板和运行时约束见 `Weline_Framework/doc/3-开发/开发标准与验收.md`；模块专项规则由 MCP 按任务从归属文档返回。
