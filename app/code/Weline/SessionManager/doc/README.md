# Weline_SessionManager 模块文档

`Weline_SessionManager` 是 Weline 的存储无关 Session 管理模块。`1.1.0` 起，它统一实现前台 Customer 与后台管理员的认证设备登记、验证、单设备撤销及每设备记住登录，不枚举 File、Redis 或 WLS 的底层 Session。

## 当前入口

开发前先读：

1. `AI-ENTRY.md` 与 `dev/ai/global-constraints.md`
2. `app/code/Weline/SessionManager/doc/AI-INDEX.md`
3. `app/code/Weline/SessionManager/doc/需求.md`
4. `app/code/Weline/SessionManager/doc/设备管理架构.md`
5. 运营或故障处理再读 `app/code/Weline/SessionManager/doc/运营/设备管理.md`

## 模块定位

- Framework 只发布中立设备/凭证契约，具体持久化和策略归本模块。
- 前台入口由 Customer 个人中心 Hook 注入；后台入口为“系统管理 → 用户与权限 → 设备管理”。
- QueryProvider 名为 `session_manager`，提供前后台各自的列表和单设备撤销操作。
- 前台/后台身份、Session、Cookie 和设备记录严格按认证区域隔离。

## 代码面概览

- `Model/AuthenticatedDevice.php`：设备、Session 摘要、元数据、活动和撤销审计。
- `Model/RememberedDeviceCredential.php`：每设备一个有效记住凭证，仅保存 Token 摘要。
- `Service/`：注册表、凭证、列表/撤销、元数据和清理业务。
- `extends/module/Weline_Framework/`：Framework Provider 与 QueryProvider 接入。
- `Controller/`、`etc/backend/menu.xml`、`view/`：前后台设备管理页面和交互。
- `test/Unit/`、`test/e2e/`：生命周期、权限、迁移、并发、Schema 和真实用户路径覆盖。

## 开发关注点

- 原始 Session ID 和 remember Token 只在运行时使用；数据库仅保存 SHA-256 摘要，接口和日志均不得输出原值或摘要。
- Provider 未声明时兼容旧认证；Provider 已声明但服务/数据库异常时认证失败关闭，不得绕回旧 Token。
- 模型字段/索引变更使用 ORM attribute 并执行定向 `setup:upgrade`；不得手改生成物。
- 页面业务请求只能使用 `Weline.Api.resource('session_manager')`，不得直接 fetch/XHR 或浏览器原生 confirm。
- 用户可见文案同步 `zh_Hans_CN.csv` 与 `en_US.csv`；菜单、Controller、Query 操作使用同一专用 ACL。

## 本模块文档资产

- `需求.md`：有效业务规则与验收标准。
- `设备管理架构.md`：分层职责、生命周期、数据模型、Query 契约和故障策略。
- `运营/设备管理.md`：入口、下线语义、升级、排障、隐私与日志要求。
- `开发日志.md`：版本门禁、验证证据和剩余验收状态。

## 维护规则

- 不直接修改 `generated/`、`view/tpl/` 或 `routes.xml`。
- 设备页只管理当前身份自己的当前认证区域；浏览器请求不得提交主体 ID 或区域。
- 当前设备不得远端下线；使用正常退出流程撤销本设备和凭证。
- 撤销/过期审计保留 30 天，设备活动最多每分钟落库一次。
- 代码、Schema、权限或页面变更完成后，按仓库门禁复审，再运行聚焦测试、独立 WLS E2E 和内置 Browser 验收。
