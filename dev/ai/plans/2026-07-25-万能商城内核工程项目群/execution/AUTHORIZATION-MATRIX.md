# 商城内核授权矩阵

## 当前授权

| 动作 | 状态 | 边界 |
|---|---|---|
| 读取计划、源码、文档、任务记录 | AUTHORIZED | 只读、有界、保护敏感信息 |
| 新增/修改商城计划、治理台账、任务记录 | AUTHORIZED | 仅商城项目群和本任务工作区 |
| 运行只读 Git/路径/CLI help/静态检查 | AUTHORIZED | 不生成或覆盖源码/配置；避开已知 `command:upgrade --help` 写副作用 |
| 运行现有测试 | AUTHORIZED_BY_GOAL | 聚焦目标模块；不得修改测试产物；必须清理数据/进程 |
| 启动专用 WLS 验证 | CONDITIONAL | 仅实际整数端口 `>=9502`、唯一实例、自动验证后 stop；当前 P0 不需要启动 |
| 修改 P1a 独占新文件 | AUTHORIZED_WITH_LOCK | 仅 `0946` 任务和 `PRJ-SCOPE-10-L4` 明确列出的独占路径；修改前 impact/定点 caller 核对 |
| 修改上游混写/未知 Owner 源码 | NOT_AUTHORIZED | `Website.php`、`DetectWebsite.php`、module/doc 及 Framework Runtime/Cache/FPC 等保持锁，直至 Owner manifest/受控基线 |
| 修改后续商城业务/框架源码 | CONDITIONAL | 对应 Project/TASK/L4 READY 且隔离 worktree 建立后实施 |
| 本地隔离数据库写入/迁移 dry-run | CONDITIONAL | 仅明确 clone/fingerprint/checkpoint/cleanup；禁止当前共享库和生产 |
| 新增/修改单元测试、E2E、fixture、测试数据/脚本 | NOT_AUTHORIZED | 用户需明确要求“写/补/改测试产物” |
| Git stage/commit/push | NOT_AUTHORIZED | 用户需明确提交；提交时按仓库双端推送规则 |
| 部署、生产写入、不可逆迁移 | NOT_AUTHORIZED | 需要具体环境与用户明确授权 |
| 真实支付/真实 Provider/真实资产/真实分账 | NOT_AUTHORIZED | 仅 sandbox/fake/隔离账户 |
| 修改/清理其他任务 dirty 文件 | NOT_AUTHORIZED | 必须由其 Owner 完成或明确移交 |

## 授权解释

- “并测试”授权运行与设计现有验证，不自动等价于新增持久测试文件。
- “完成计划”授权按已冻结项目群逐 Gate 实施，但不授权跳过 P0、覆盖他人改动、提交、部署或生产操作。
- 任何 C2 架构变化或 C3 外部/不可逆操作仍需单独批准。
