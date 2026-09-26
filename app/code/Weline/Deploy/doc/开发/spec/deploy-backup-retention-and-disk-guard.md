# 发布快照保留策略与磁盘/监控防护

**状态**：⬜ 待开发（status: pending）
**立项日期**：2026-09-26
**触发事件**：生产站 `changanhanfu.com` 2026-09-26 全站 502（约 75 分钟）
**影响面**：`Weline_Deploy`（整站快照）、`bin/server-monitor.sh`（看门狗）、生产运维基线
**优先级**：🔴 高 —— 这是本次事故的**直接原因**：磁盘被发布快照写满，
WLS Master 因无法写日志退出，整站 502。

---

## 一、事故时间线（生产实测，时区 CST）

| 时刻 | 事件 |
|------|------|
| 12:30 | 第 1 份整站快照开始打包，落盘 **2.31 GB**（12:33 写完） |
| 12:55 | 第 2 份开始，落盘 **4.61 GB**（13:00 写完） |
| 14:10 | 第 3 份开始，落盘 **5.13 GB**（14:17 写完） |
| ~14:11 | `var/backup` 累计 **12 GB**，`/dev/vda3`（40 G）达到 **100%**；WLS Master 写日志失败退出 |
| 之后 | 宝塔 nginx 上游 `127.0.0.1:9510` 不可达 → 全站 **502** |
| 15:0x | 清空 3 份快照后磁盘回到 `40G 26G 12G 70%` |

Master 退出时的直接证据（`var/log/` 侧）：

```
file_put_contents(...): Write of 1391261 bytes failed with errno=28 No space left on device
mkdir(): No space left on device
```

> 关键点：**故障不是「发布失败」，而是「发布成功地把磁盘写满了」**。
> 发布本身没有报错——`backupWithTar()` 用的是 `exec()` 且 **`2>/dev/null`**，
> tar 的 `No space left on device` 被静默丢弃，退出码也没检查。

---

## 二、根因

### R1 🔴 快照没有任何保留策略（无上限、无轮转、无清理）

- `DeployOrchestratorService::backupProject()`（`Service/DeployOrchestratorService.php:881-896`）
  在每次部署前写 `Env::backup_dir . 'deploy/' . 'backup_' . date('Y-m-d_H-i-s')`。
- `DeploySiteBackupService::createSiteBackup()`（`Service/DeploySiteBackupService.php:24-74`）
  写同样的命名规则 + `.manifest.json`。
- `Console/Deploy/Build.php:443` 还有一条同样的 tar 通路。

**两个文件里都搜不到 `unlink` / `glob(` 等清理逻辑**（已 grep 确认）⇒ 快照**只增不减**。
`doc/发布管理-需求说明.md:285` 明确写着 `var/backup/deploy/` 是
「正式站强制整站备份归档」——即**每次发布都必然新增一份 GB 级归档，永不回收**。
当天连续 3 次发布 = 12 GB，单日即可吃满 40 G 盘。

### R2 🔴 排除集不含 `var/backup`，快照自我放大

`DeployOrchestratorService::backupWithTar()`（`DeployOrchestratorService.php:911-918`）：

```php
'cd ' . escapeshellarg($deployRoot) .
" && tar --exclude='var/cache/*' --exclude='.git' --exclude='vendor' --exclude='node_modules' -czf " .
escapeshellarg($backupPath . '.tar.gz') . ' . 2>/dev/null'
```

从**部署根目录**打包，而排除集里**没有 `var/backup`**（也没有 `var/log`、`var/session`）
⇒ 第 N 份快照会把第 1…N-1 份快照**一起打进去**。

观测到的体积序列 `2.31 → 4.61` GB 与「#2 ≈ 基础 + #1」的自我放大完全吻合。
`DeploySiteBackupService::backupWithTar()`（`:100-110`）排除了 `var/log`、`var/session`，
但**同样没排除 `var/backup`**；`Deploy/Build.php:443` 亦然。三条通路都有同一个洞。

> ⚠️ 第 3 份 **5.13 GB** 不严格符合「基础 + 前两份」的简单模型，
> 说明**实际被纳入的目录集需要实测确认**（见验收 A2）。
> 但「未排除 `var/backup`」这一点是**代码层面确定的事实**，不依赖体积推算。

### R3 🔴 备份前后没有任何磁盘空间预检

`Weline_Deploy` 与 `Weline_Server` 全库**搜不到 `disk_free_space`**（已 grep 确认）。
一份 GB 级快照在写入前不检查剩余空间，于是：
写满盘 → 顺带**拖垮同机的 WLS**（日志写入失败 → Master 退出）→ 全站 502。
**备份这种「非关键路径」的操作，有能力打死「关键路径」的服务。**

### R4 🔴 看门狗 `bin/server-monitor.sh` 在本部署形态下完全不可用

`bin/server-monitor.sh` 的 `install_cron()` 会装
`*/10 * * * * bash <script> --run  # WELINE_QIPAISAAS_WATCHDOG`，
在 `wls.host` 不可达时执行 `php bin/w s:start -r`。生产实况：

1. **从未安装**。生产 root crontab 只有：
   `12 4 * * *`（宝塔）、`0 4 * * *`（宝塔）、
   `*/30 * * * * /www/server/cron/wls_log_cleanup.sh`、
   `*/1 * * * * …Weline_Cron…cron.sh`。**没有 `WELINE_QIPAISAAS_WATCHDOG` 条目。**
2. **即使装上也会立刻失效**。实跑 `bin/server-monitor.sh --run`：

   ```
   missing wls.host
   [2026-09-26 15:35:01] 无法从 /www/wwwroot/changanhanfu.com/app/etc/env.php 解析检测地址。
   ```

   `resolve_target_url()` 只读 `wls.host` + `cli_server.port`，
   而本生产实例 `wls.host = NULL`、`cli_server.port = NULL`
   （实际拓扑是 `wls.edge = {"mode":"wls"}` + `wls.servers[0] = {host, port, public_host, …}`）。

⇒ 生产**当前没有任何可用的可用性监控**：看门狗既没装、装了也读不到目标地址。
本次 502 持续 75 分钟，全程无人/无机制发现。

> 附带缺陷：`resolve_target_url()` 在 `wls.host` 不带 scheme 时**硬编码补 `https://`**，
> 而纯 WLS 的 `9510` 是**明文 HTTP**。若哪天补上 `wls.host`，
> 该脚本会用 https 去探 http 端口 → 恒判「不可访问」→ **每 10 分钟无脑重启一次**。
> 这个坑必须在装 cron 之前先修掉。

### R5 🟡 次级无界增长

清空快照后实测：`var/cache` **1.1 G**、`var/cron.log` **178 M**、`var/process` 72 M、
`var/log` 57 M。`wls_log_cleanup.sh` 只覆盖日志类，`var/cache` 与 `var/cron.log`
没有可见的回收策略。

---

## 三、修复方案

### P1（必须）给快照加保留策略

- 在 `backupProject()` / `createSiteBackup()` 落盘**成功之后**执行轮转：
  按 `backup_*.tar.gz` 的 mtime 排序，保留最近 **N** 份（默认 2）、
  或保留**最近 N 天**（默认 3 天），并**同时清理对应的 `.manifest.json`**。
- N 必须可配置（`env.php` 的 deploy 段），不能硬编码；默认值要按「单份 GB 级」定，
  而不是按「份数看起来不多」定。
- 轮转失败**不得**让发布失败（降级为告警日志），但必须在发布结果里显式体现。

### P2（必须）排除集补 `var/backup`（及 `var/log`、`var/session`）

三条 tar 通路统一收口到一个共享的排除集常量，至少包含：
`var/backup`、`var/cache`、`var/log`、`var/session`、`var/tmp`、
`.git`、`vendor`、`node_modules`。
**排除 `var/backup` 是止血关键**——否则 P1 的轮转只能减缓、不能终止自我放大。

> 顺带修 `backupWithTar()` 的 `2>/dev/null` + 不检查退出码：
> 静默吞掉 tar 错误是本次「发布看起来成功、磁盘已被写满」的成因之一。
> 应改为捕获输出、检查退出码，失败即抛出并保留诊断。

### P3（必须）快照前磁盘预检 + 快速失败

写盘前用 `disk_free_space()`（或 `df`）判断：

- 剩余空间 < 预估归档体积 × 安全系数（如 1.5） ⇒ **拒绝创建快照并明确报错**，
  而不是让 tar 写到一半把盘吃干净。
- 阈值与安全系数可配置。

**理由**：宁可「发布被拒绝」，也不能「发布成功但站点挂了」。
备份是可选路径，服务是必须路径，两者绝不能共享同一份「写满就一起死」的风险。

### P4（必须）修好并装上可用性看门狗

1. 修 `resolve_target_url()`：按 `wls.edge.mode` / `wls.servers[]` 解析目标，
   纯 WLS 用 `http://`，托管 Nginx 用 `https://`；`wls.host` 仅作**可选覆盖**。
2. 增加**告警**能力。当前脚本只会「重启」，没有任何通知通道；
   本次事故里「站点已挂」这件事本身就没有出口。
3. 装 cron 之前先在**健康状态下**实跑一次，必须输出「可访问，跳过重启」；
   若输出「不可访问」，说明探测地址仍然错，**不得**安装（否则会每 10 分钟无脑重启）。
4. 显式记录「已安装」的事实到仓库文档，避免「以为装了、其实没装」。

### P5（建议）磁盘水位告警

对 `/` 使用率设阈值（如 85% / 95% 两级），到阈值即告警。
本次事故在 100% 之前有约 3 小时的窗口期（12:30 起体积就已异常），**全程无信号**。

### P6（建议）`var/cache` / `var/cron.log` 回收

纳入与日志同级的清理策略，并设上限。

---

## 四、验收标准

| # | 验收项 | 判据 |
|---|--------|------|
| A1 | 连续发布 3 次后快照数量受限 | `ls var/backup/deploy/backup_*.tar.gz \| wc -l` ≤ 配置的 N |
| A2 | 快照内不含 `var/backup` | `tar -tzf <新快照> \| grep -c '^\./var/backup'` == 0（同时确认 `var/log`、`var/session`） |
| A3 | 空间不足时拒绝备份且报错可读 | 造小盘/低水位场景 → 发布中止，错误含「剩余空间」与所需值 |
| A4 | tar 失败不再静默 | 人为让 tar 失败 → 发布流程显式失败并带 tar 输出 |
| A5 | 看门狗在健康态判定「可访问」 | `bash bin/server-monitor.sh --run` 输出「可访问，跳过重启」 |
| A6 | 看门狗在故障态能恢复 | 停掉实例 → 单次 `--run` 后站点恢复 |
| A7 | 看门狗已装且有告警出口 | `crontab -l \| grep WELINE_QIPAISAAS_WATCHDOG` 命中；告警可观测 |
| A8 | 磁盘水位告警可触发 | 阈值以上产生一次告警记录 |

---

## 五、事故处置记录（2026-09-26 已执行）

| 动作 | 结果 |
|------|------|
| 记录 3 份快照的 `stat`（大小/mtime）后清空 | `var/backup` 12 G → 0，磁盘 `40G 26G 12G 70%` |
| 确认无 deploy/tar 进程在跑、无 deploy 锁 | 是（清理安全） |
| 确认磁盘恢复后 WLS 可启动 | 是（见 Server 侧 spec 的 E6） |

> ⚠️ 清空快照是**止血**，不是修复。若不落地 P1/P2，
> 下一次连续发布仍会以同样的方式吃满磁盘。

---

## 六、进度跟踪

| # | 任务 | 状态 |
|---|------|------|
| 1 | P2 三条 tar 通路统一排除集（含 `var/backup`） | ⬜ |
| 2 | P2 `backupWithTar()` 去掉 `2>/dev/null` 并检查退出码 | ⬜ |
| 3 | P1 快照保留/轮转策略（份数或天数，可配置） | ⬜ |
| 4 | P3 快照前磁盘预检 + 快速失败 | ⬜ |
| 5 | P4.1 `resolve_target_url()` 按 `wls.edge.mode` / `wls.servers[]` 解析 | ⬜ |
| 6 | P4.2 看门狗告警通道 | ⬜ |
| 7 | P4.3 健康态实跑验证后安装 cron | ⬜ |
| 8 | P5 磁盘水位告警 | ⬜ |
| 9 | P6 `var/cache` / `var/cron.log` 回收策略 | ⬜ |
| 10 | A1–A8 验收 | ⬜ |
| 11 | 文档同步（`doc/发布管理-需求说明.md`、`doc/开发日志.md`） | ⬜ |

---

## 七、关联

- 同期启动链路缺陷：`Weline_Server/doc/开发/spec/wls-startup-handoff-capability-gate.md`
- 既有能力说明：`doc/发布管理-需求说明.md`（`var/backup/deploy/` 整站归档）
- 既有清理脚本：`/www/server/cron/wls_log_cleanup.sh`（`*/30 * * * *`，仅日志）
