# WLS 实例隔离机制（核验版）

## 1. 核心概念

| 概念 | 说明 |
|---|---|
| BP (Base Path) | 项目根目录，例如 `E:\WelineFramework\DEV-workspace` |
| `Env::VAR_DIR` | `BP . 'var' . DS`，运行时文件根目录 |
| 实例名 (instance name) | 例如 `default`、`api`，用于区分同项目内多个 WLS 实例 |

> 结论：WLS 的实例管理和进程索引均以当前项目 BP 为根，天然具备跨项目目录隔离。

## 2. 单实例完整进程树

每个 WLS 实例的核心进程角色如下（命名来自 Provider 常量与启动参数）：

| 进程角色 | 进程名格式 | 端口规则（实现口径） | 说明 |
|---|---|---|---|
| Master | `weline-wls-master-{instanceName}` | `control_port` 默认 `main_port + 10000`（可配置覆盖） | 进程编排、健康检查、控制通道 |
| Session Server | `weline-wls-session-{instanceName}` 或共享模式 `weline-wls-session-shared-{port}` | 默认 `19970`，可配置覆盖 | 会话共享状态 |
| Memory Server | `weline-wls-memory-{instanceName}` 或共享模式 `weline-wls-memory-shared-{port}` | 默认 `19971`，可配置覆盖 | 内存共享状态 |
| Worker #N | `weline-wls-worker-{instanceName}-{N}` | 非 direct 模式：`worker_base_port + N`；direct 模式复用 `main_port` | HTTP 业务处理 |
| Dispatcher | `weline-wls-dispatcher-{instanceName}` | `main_port` | 对外流量分发 |
| HTTP Redirect | `weline-wls-redirect-{instanceName}` | `http_redirect_port`；若未设且 HTTPS 主端口为 443 则为 80，否则 0（不启动） | HTTP → HTTPS |

## 3. 进程管理信息隔离（关键）

进程索引目录（基于当前 BP）：

```text
var/process/pid/
├── name_index.json
├── pid_index.json
└── port_index.json
```

核心隔离点：

1. 索引路径由 `Processer` 通过 `Env::VAR_DIR` 计算，等价于“按项目根目录隔离”。
2. 同一台机器上，不同项目的 `var/process/pid` 相互独立。
3. 进程终止/清理均依赖当前项目的索引与实例文件，不会主动扫描其他项目目录。

## 4. 实例文件隔离与实例定位

实例文件路径：

```text
var/server/instances/{instanceName}.json
```

常见字段（启动后）：

```json
{
  "name": "default",
  "master_pid": 12345,
  "control_port": 10443,
  "worker_port": 10443,
  "worker_base_port": 10442,
  "shared_state": {
    "session": {
      "host": "127.0.0.1",
      "port": 19970,
      "instance_name": "shared-session-19970"
    },
    "memory": {
      "host": "127.0.0.1",
      "port": 19971,
      "instance_name": "shared-memory-19971"
    }
  }
}
```

说明：

- `Start::saveInstanceInfo()` 先写实例基线信息（含 `shared_state`）。
- `MasterProcess::saveMasterInfo()` 再补写 `master_pid`、`control_port` 等运行态信息。
- `server:stop` / `server:reload` 通过实例名查当前项目的该文件，读取 `master_pid` / `control_port` 发控制指令。

## 5. IPC 控制通道隔离

控制端口规则：

- 默认：`main_port + 10000`
- 覆盖：`app/etc/env.php` 中 `server.control_port`

冲突检查（启动阶段）：

1. 扫描当前项目 `var/server/instances/*.json`，查重 `control_port`（仅对“仍在运行”的实例判定冲突）。
2. 额外通过系统端口占用检查 `Processer::isPortInUse`，防止端口已被任意进程占用。

控制链路（逻辑）：

```text
CLI (server:stop / reload)
        |
        v
Master Control Server (127.0.0.1:control_port)
        |
        +--> Worker / Dispatcher / Redirect
```

## 6. 同项目多实例共享 Session/Memory

共享实例命名（实现已固定）：

- Session：`shared-session-{port}`
- Memory：`shared-memory-{port}`

共享进程名：

- Session：`weline-wls-session-shared-{port}`
- Memory：`weline-wls-memory-shared-{port}`

默认端口来源：

- Session 默认 `19970`
- Memory 默认 `19971`

运行时会写入 `shared_state`，并由 Master 注入 `wls.shared_state.runtime` 供编排器复用。

## 7. 跨项目隔离边界

| 维度 | 隔离机制 |
|---|---|
| 进程索引 | `var/process/pid/*.json` 以 BP 为根目录 |
| 实例文件 | `var/server/instances/{instance}.json` 以 BP 为根目录 |
| 控制端口 | 每实例独立 `control_port`，启动时做实例文件与端口占用校验 |
| Session/Memory | 默认端口可相同，但实际由各自项目进程持有 |
| 命令作用域 | `server:*` 在当前项目目录执行，仅作用于当前项目实例文件与进程索引 |

## 8. 误操作风险与防护

风险场景：在项目 A 目录下误以为在操作项目 B 的实例。

已有防护：

1. **目录隔离**：命令读取的是当前 BP 下 `var/server/instances` 与 `var/process/pid`。
2. **框架进程识别**：端口占用场景下通过 `isWelineServerProcess` 判定，避免误杀非框架进程。
3. **实例名隔离**：同项目内靠实例名区分不同进程组。

仍需注意：

- 进程名本身不包含项目路径；如果不同项目都使用 `default`，任务管理器中可能出现多个同名 `weline-wls-master-default`。
- 建议跨项目使用可识别实例名（如 `prod-default`、`dev-default`）。

## 9. 已核验实现差异（相对常见口述）

1. `Processer::destroy()` 当前实现为直接 `return self::kill($pname);`，并非在 `destroy()` 内显式执行 `weline-` 前缀拦截。
2. `weline-` 前缀检查主要体现在“识别是否框架进程/端口占用归属”路径（如 `isWelineServerProcess`），不是 `destroy()` 的直接判定条件。
3. Worker 端口应按运行模式描述：非 direct 模式使用 `worker_base_port + instanceId`；direct 模式可复用主端口。避免将其写成固定单一公式。
4. `control_port` 冲突判定是“实例文件扫描 + 本机端口占用检查”的组合。

## 10. 推荐对外描述（可用于文档/博客）

WLS 同时支持“同项目多实例”与“跨项目隔离”。

- 在同一项目（同 BP）下，可通过 `server:start [instanceName]` 启动多个实例。每个实例拥有独立的 Master/Worker/Dispatcher/Redirect 进程组，并可通过 `shared_state` 复用 Session/Memory 服务。
- 在不同项目（不同 BP）下，进程索引、实例文件、控制端口与运行态文件天然隔离。命令仅作用于当前项目目录，不会跨项目读取实例索引。
- 由于进程名不含项目路径，建议跨项目使用不同实例名，提升可观测性并降低误判风险。
