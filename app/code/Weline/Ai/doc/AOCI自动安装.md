# Weline MCP 准备阶段的 AOCI 自动安装

## 需求与入口

**硬要求（`aoci_complements_weline_mcp`）**：本机没有可用 AOCI 程序时，Weline **必须自动安装**，禁止把「没装 AOCI」当成可跳过事项。

入口有两处（同一 `AociInstaller`）：

1. **`ensure-project-guidance.php`**：冷启动即处理，结果在 bootstrap JSON 的 `aoci_installation`。
2. **`prepare_project`**：知识准备之前再确保一次，结果在 `agent_guidance.aoci_installation`。

安装逻辑由公共服务执行，不依赖编辑器提示词或某个编辑器的命令行。AOCI 安装失败会返回 `status=failed` 与具体 `error`，须可见汇报；失败本身不单独增加 Weline 开发阻断条件。

## 首次处理

1. 读取本机安装成功标志；没有有效标志才开始检测。
2. 在共享安装目录、用户 `~/.local/bin` 和宿主进程的 `PATH` 中查找 AOCI，运行一次 `--version` 验证。已安装的有效版本直接复用。
3. 未发现可用安装时，下载官方 `aoci-spec/aoci-code` 的 `v0.1.0-rc17` Release 与 `SHA256SUMS`，按操作系统和架构选择安装包。
4. 校验 SHA-256，解压程序，验证程序身份与 Release 版本后保存程序。
5. 仅成功后原子写入 `~/.learning-mcp/tools/aoci/installed.json`。

下载采用官方基本验证等级（包校验和与程序身份），不声称完成签名或构建来源验证。官方说明：[AOCI Installation](https://github.com/aoci-spec/aoci-code/blob/main/docs/install.md)。

下载需要宿主提供 `curl`、`tar` 和 HTTPS 网络；支持官方提供安装包的 macOS/Linux/Windows，架构为 amd64/arm64。Weline MCP 自身仍需可用的 PHP 运行环境。

## 本机成功标志

标志包含程序绝对路径、版本、安装来源、验证等级及时间。它位于用户本机共享状态目录，跨项目、跨编辑器复用，不写入项目或 Git。

有效标志存在时，服务直接返回 `cached=true`。后续准备不查找程序、不调用 `--version`、不联网，也不自动检测更新。安装程序后来被移动或删除时不会自动发现；确需重新处理时，将标志文件改名备份后再次调用 `prepare_project`。

并发首次准备通过本机安装锁串行化，取得锁后再次读取标志，避免重复安装。失败不写成功标志，下次准备会重新处理。已有的安装目标文件不会被强制覆盖。

## 返回值与宿主边界

成功结果包含 `status=ready`、`binary`、`version`、`source`、`verification`、`cached` 和 `marker_path`。`mcp_registration` 提供绝对程序路径与当前项目对应的 `--repo <项目根> mcp` 参数。

安装 AOCI 程序不等于编辑器已经挂载 AOCI MCP。`status=ready` 时返回 `mcp_registration`（绝对程序路径 + `--repo <项目根> mcp`）；ensure 的 `host_mcp_install` 文档在 AOCI 已就绪时也会带上 `aoci` 条目。Agent 发现会话无 AOCI 工具时，必须按该登记写入宿主 MCP 配置并刷新会话；已有会话可能需要新开 Agent 回合或 Reload Window。

此安装流程不修改项目 AOCI 规则、不执行 `init` 或 `scan`，不自动构建代码语义索引。Weline 继续提供硬规则、技能和准备流程；AOCI 的项目认知索引与漂移维护仍为独立能力。

**项目认知资产不进 Git**：`aoci.txt` / `aoci.code.txt` / `aoci.meta.txt` / `.aoci/baseline.json` / `.aoci/config.json` 等由本机 `aoci` 生成与维护；新环境靠自动安装程序后再本地 bootstrap/maintain 重建，禁止把大索引当仓库正文提交。

## 验证

针对性用例：`php app/code/Weline/Ai/Mcp/tests/aoci-installation.php`。

用例覆盖已有程序复用、缺失程序下载安装、校验失败与后续重试、网络失败不写标志、跨实例跳过程序检测、公共准备入口返回安装结果、当前项目参数绑定，以及安装失败不改变 Weline readiness。
