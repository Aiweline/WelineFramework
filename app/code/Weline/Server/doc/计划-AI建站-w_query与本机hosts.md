# AI 建站本地基建 — WLS（`w_query` / 本机 hosts）

> 模块：`Weline_Server`（WLS）  
> 范围：**仅本文档描述 WLS 侧**职责；域名订单/供应商/业务状态机见 [`Weline_Websites` 计划](../../Websites/doc/计划-AI建站工作台-Websites侧.md)；页面生成与 `ai_html` 见 [`GuoLaiRen_PageBuilder` 计划](../../../GuoLaiRen/PageBuilder/doc/计划-新AI建站工作台-页面区块与智能体.md)。

## 目标

- 开发/E2E 使用 **`*.weline.local`** 等本地 FQDN 时，**解析必须在本机生效**，否则 HTTPS 与 Playwright 验收无法稳定通过。
- 提供 **`w_query`（实现定名可调整）** 类能力：将域名写入 **hosts** 或与现有逻辑等价的一键流程。
- **Linux、Windows、Linux CI** 行为一致可测；**仅 dev/test** 启用，生产禁用或 noop。

## 已确认决策（WLS 子集）

1. **优先复用**：在实现前 **溯源** 仓库内已有「**临时分配域名 → 自动写本机 hosts**」逻辑（代码入口待标到本文「实现索引」）。
2. **双轨入口（首版）**：
   - **`php bin/w` 控制台子命令**（薄封装或直接调用内部服务）；
   - **Playwright `globalSetup`** 可 **直接调系统层**（PowerShell / Node 等）写 hosts，与 bin/w **并存**；两者行为与格式约定需一致或文档说明差异。
3. **跨 OS**：须覆盖 **Windows**（路径、管理员权限、`hosts` 编码）与 **Linux**（含 **CI 容器** 内 `/etc/hosts` 写入或等价策略）。
4. **CI**：合并门槛要求 **CI 内 Playwright** 跑通时，hosts/解析须与 **本条同源能力** 一致，不得仅能在 Windows 本机通过。
5. **本地通配证书（与 WLS/Worker SSL 衔接）**：对 **`*.weline.local`** 的 **单一通配证书** 在 **申请/续期前** 检查是否 **已有可用通配**，避免重复签发；**仅针对 `weline.local` 后缀**；具体签发与 Worker SSL 管线对接见 [`证书管理Hook集成.md`](./证书管理Hook集成.md) 及 `worker_ssl` 相关实现。非 `weline.local` 域名 **不按本文特殊处理**，走业务参数与既有分支。

## 实现索引（代码溯源）

| 环节 | 位置 |
|------|------|
| hosts 读写（标记块、权限检测） | [`HostsFileManager`](../Service/HostsFileManager.php) |
| `server:start` 启动时自动写入 `.local` | [`Start::ensureHostsFileConfigured()`](../Console/Server/Start.php)（约 2030 行） |
| **计划 w_query 首版入口（CLI）** | [`server:hosts:add`](../Console/Server/Hosts/Add.php) — `php bin/w server:hosts:add <域名> [--ip=127.0.0.1]`，仅 `system.env` ∈ local/dev/test |

## 交付物

- [x] 溯源：`HostsFileManager` + `Start::ensureHostsFileConfigured`（见上表）
- [x] bin/w 命令：`server:hosts:add`（参数 FQDN、`--ip`，与 `HostsFileManager` 同源）
- [ ] `w_query` 别名/文档别名统一（可选与 command:upgrade 生成名对齐）
- [ ] Linux / Windows / CI 验证说明与已知限制（权限、UAC、只读文件系统）。
- [ ] 与 Playwright 项目的 globalSetup 示例或文档链接（推荐调用 `php bin/w server:hosts:add <fqdn>`）。
- [x] 单元测试：`HostsAddCommandTest`（域名合法性 `isEligibleLocalHostname`）；hosts 文件 IO 仍以手工/CI 验证为主。

## 非目标

- 不定义 **PageBuilder** 页面结构、**Websites** 订单字段；不在此写 **Agent** 剧本全文。

## 关联文档

- [GuoLaiRen PageBuilder — 新 AI 建站（页面侧）](../../../GuoLaiRen/PageBuilder/doc/计划-新AI建站工作台-页面区块与智能体.md)
- [Weline Websites — AI 建站（工作台与域名业务）](../../Websites/doc/计划-AI建站工作台-Websites侧.md)
