# changanhanfu 503 / facet 无限 URL —— 可发布收敛方案（2026-10-29 收口）

> 本文件是交付物：列出**已上线但尚未入 release 的改动**、**生产环境配置**、**启动/排水 runbook**、**验收命令**、**未闭环项**。
> 代码权威在本地仓库（`dev` 分支），生产为 scp 热修；本文件用于把它们收敛成一次正式发布。

## 一、需要进 release 的代码改动（本地已提交）

| 提交 | 文件 | 作用 |
|---|---|---|
| `21b2c1a2f2` / `94095b4d64` / `f80e4eec64` / `527a475cde` | `Product/Controller/Frontend/Category.php`、`Catalog.php` | 零命中 facet → 301 收口到 www；候选截断守卫；零命中页 `X-Robots-Tag: noindex`；**形状闸**（af_* 维度>4 / 单值>64 字符 / 总长>160 → 加载数据前 301） |
| `21b2c1a2f2` / `c08b1d883e` | `Server/bin/worker.php`、`worker_ssl.php` | 内存压力回收闸：仅热态生效（`WorkerReadinessState::snapshot()['warmup_state']==='hot'`）；收到内存类 DRAIN 先关监听、等在途归零再起排水时钟（上限 120s）；`await_master 5→30s`、自排水 `10→30s`、`defer_ceiling 20s`、`force 0.97` |
| `0339a8390d` | `Server/Service/WorkerResponseMemoryGuard.php` | 空壳退役线可配置 `wls.memory_guard.zend_shell_drain_bytes`（默认行为不变） |
| `f66295ebd3` | `Server/bin/wls_gateway_controller.php` | 状态文件稳定读 3×50ms 有界重试（止住 ledger 自杀回声） |
| `a65baeb0ab` | `Theme/Cron/LayoutSolidifyDrain.php` | 固化物排水增加生产开关 `theme.layout_solidify.drain_in_production`（默认 false） |
| （本轮） | `Theme/Service/LayoutEntity/ThemeLayoutEntityRequestSolidifyGate.php` | **本地版本比线上新**：含 pending 预算（`pendingMaxSeconds`/`pendingBounded`）与 `circuitFailCount`；已 scp 上线 |
| `21b2c1a2f2` | `Frontend/Observer/ResponseRedirectBefore.php` | 请求级 host opt-out（仅对已锁定规范 host 的重定向不改写，全站行为不变） |
| `21b2c1a2f2` | `Server/Service/MasterChildCredentialStore.php`、`IPC/ChildControl/ChildMasterGuard.php` | 凭据断言 3×50ms 重试；只容忍租约/凭据瞬时态，`Master PID missing` 立即退出 |

**一致性核对命令**（发布前后都要跑）：

```bash
for f in app/code/Weline/Product/Controller/Frontend/Category.php \
         app/code/Weline/Product/Controller/Frontend/Catalog.php \
         app/code/Weline/Server/bin/worker.php \
         app/code/Weline/Server/bin/worker_ssl.php \
         app/code/Weline/Server/bin/wls_gateway_controller.php \
         app/code/Weline/Server/Service/WorkerResponseMemoryGuard.php \
         app/code/Weline/Frontend/Observer/ResponseRedirectBefore.php \
         app/code/Weline/Server/Service/MasterChildCredentialStore.php \
         app/code/Weline/Server/IPC/ChildControl/ChildMasterGuard.php \
         app/code/Weline/Theme/Cron/LayoutSolidifyDrain.php; do
  l=$(md5 -q "$f"); p=$(ssh changanhanfu "md5sum /www/wwwroot/changanhanfu.com/$f | cut -d' ' -f1")
  [ "$l" = "$p" ] && echo "MATCH $f" || echo "DIFF  $f local=$l prod=$p"
done
```

## 二、生产环境配置（`app/etc/env.php`，不在 git）

```php
'wls' => [
    'worker_memory_limit' => '1024M',       // 512M 时 real 481MB 即溢出
    'memory_guard' => [
        'worker_memory_drain_threshold' => 0.85,
        'zend_shell_drain_bytes' => 805306368,   // 768MiB；默认 153MiB 会让 worker 20-30s 退役
    ],
    'fiber' => [
        'max_active' => 12,
        'pin_max_active' => false,
        'admission_memory_headroom_bytes' => 201326592,  // 192MiB；默认 410MB → 满站 x-wls-admission 503
    ],
],
'theme' => ['layout_solidify' => ['drain_in_production' => true]],
```

`var/server/config/default.json`：`ssl_enabled=false`、`edge_mode=wls|auto`、`worker_count=4`（CPU 受限期）。

## 三、启动 / 排水 runbook（本机唯一可用拓扑）

```bash
ROOT=/www/wwwroot/changanhanfu.com
# 0) 清脏 nginx 身份（手工 nginx / 失败发布留下的，会让托管 Nginx 发布 fail-closed）
pgrep -f "nginx: master process $ROOT" >/dev/null || {
  mkdir -p $ROOT/var/backup/config/$(date +%Y%m%d-%H%M%S)-nginx-litter
  mv $ROOT/var/server/nginx-linux-x86_64/run/nginx.pid \
     $ROOT/var/server/nginx-linux-x86_64/run/nginx.process-identity.json \
     $ROOT/var/backup/config/last-nginx-litter/ 2>/dev/null; }
# 1) 清 holder 已死的锁
for f in $ROOT/var/server/locks/*.lock; do ... # ps -p holder 死了就 mv 到备份目录
# 2) 编译产物（改了代码后必须先跑，否则 start 预检拒绝）
su -s /bin/sh weline -c "cd $ROOT && HOME=/home/weline ./extend/server/php/bin/php -d memory_limit=1024M bin/w framework:compile"
# 3) 启动（已固化进 /usr/local/sbin/changanhanfu-wls-autostart.sh，自动取有证书文件的最新快照 + test 档）
bash /usr/local/sbin/changanhanfu-wls-autostart.sh
# 4) 验活
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Host: www.changanhanfu.com' https://127.0.0.1/
curl -sk -D - -o /dev/null -H 'Host: www.changanhanfu.com' https://127.0.0.1/ | grep -i x-weline-maintenance   # 有 1 就 maintenance:disable
# 5) 固化物排水（主题布局 job 长期 pending 时）
su -s /bin/sh weline -c "cd $ROOT && HOME=/home/weline ./extend/server/php/bin/php -d memory_limit=1024M /tmp/drain_solidify.php"
```

## 四、验收命令（每次发布后照抄）

```bash
# 行为矩阵（origin）
for U in "/" "/products" "/category/women/" "/category/hanfu" "/blog" \
         "/products?af_hanfu_gong_yi=Printing%20and%20dyeing" \
         "/products?af_brand=A&af_color=B&af_size=C&af_material=D&af_style_type=E"; do
  printf '%-60s ' "$U"
  curl -sk -o /dev/null -D /tmp/h -w '%{http_code}/%{time_total} ' --max-time 120 \
    -H 'Host: www.changanhanfu.com' "https://127.0.0.1$U"
  grep -io 'x-weline-fpc: [A-Z]*' /tmp/h | tr -d '\r'
  grep -io '^location:.*' /tmp/h | tr -d '\r'; echo
done
# 期望：/ 与 /products 与 /blog = 200 且 x-weline-fpc: HIT；5 维假 facet = 301 → https://www.changanhanfu.com/products/
# 反向探测：x-weline-maintenance 不能出现；x-wls-admission: memory_pressure 不能出现
```

## 五、未闭环项（明确的移交清单）

| # | 项 | 证据 / 复现 | 建议 |
|---|---|---|---|
| 1 | 分类页仍 `x-weline-fpc: BYPASS` | `x-weline-solidify-decision: pending_coalesce_fallback_original`，job `expected_fp` 稳定但每次请求重新入队；排水 `processed=2/pending→0` 后一个请求又变 pending | 改 solidify stamp 写入/校验链路（闸门读的 stamp 与排水产物不同源） |
| 2 | 宿主 CPU 容量 | `vmstat st 12~70%` 波动、`us 40~73%`、`id 0~38%`；冷渲染 2.7–20s | 向服务商提单或换机；冷渲染 <5s 在现机器上不可达 |
| 3 | 托管 Nginx 发布偶发 fail-closed | `WLS process PID namespace identity is unavailable` / `last-known-good config could not be proven live` | 需要框架侧修 PID 身份校验（容器/命名空间场景）或长期用手工 nginx + 明文后端拓扑 |
| 4 | 生产固化物排水靠 cron 开关兜底 | 已加 `theme.layout_solidify.drain_in_production`，但 cron 包装脚本未稳定跑到该任务；目前靠 CLI 手动排水 | 让 WLS 响应后排水真正生效，或把该任务固定进 cron 计划 |


---

# 第二轮（2026-10-29 22:39 — 10-30 00:10）：团队根因攻坚

队形：`arch2`（架构师全程评审+裁决）、`solidifier`（主题固化）、`capacity`（容量取证）、`nginx`（边缘/Nginx）。证据文档：`dev/team/changanhanfu-rootcause/`。

## 新增根因与处置（全部有 文件:行号 + 命令原文）

| # | 根因（真） | 处置 | 状态 |
|---|---|---|---|
| R1 | `ThemeLayoutEntityRequestSolidifyGate::filterInventoryBakeBroken()` 对 theme=2 的 category/search **恒真**（category 设计模板全文无 `<w:slot>`；search 槽位 id 是 `search-filters` 不在词表、61 条注入声明无 search Filters 声明）→ 固化 job 永久 pending、每请求重固、每次排水 53–273s | 收敛为 `filtersInventoryVerdict()` 纯函数 + `sourceTemplateMountsFiltersSlot()` 精确锚点（`data-slot-id/data-wslot/data-placeholder/(?<![\w:-])id`；`accept=` 载荷不算；`data-widget-id` 不误判）；无槽位→判据不适用，有槽位丢 widget→仍 broken；request 级 memo；reason 顺序修复 | **已修 + 已验证**：`reason=fresh`、`jobs 0→0`、`status 0×2`、3 次带章 MISS 200 无 fallback 头、products 反向断言 0→0 |
| R2 | 托管 nginx 经 `proc_open` 继承**所有**未 CLOEXEC 的 fd（lifecycle 锁 + `lifecycle_default`/`start_default` 锁 + socket + 日志）→ flock 被常驻 nginx 永久持有 → 之后任何 reload/start/stop 在拿锁阶段超时 | **F12**：`runNginx()` 在 exec 前以 `/bin/sh` 关闭 fd>2（未打开 fd 需先探测，否则 dash 退出 126） | **已修 + 已验证**：框架起的 master `1135906` 的 `/proc/<pid>/fd` 中锁 fd = 0、`lsof` = 0、`fdinfo` 无 FLOCK |
| R3 | `MasterLeaseRuntimeIdentity::pidNamespaceId()` 用 `readlink(/proc/<pid>/ns/pid)`，而托管 nginx 二进制被安装器 `setcap cap_net_bind_service` → non-dumpable → `/proc/<pid>/ns` 仅 root 可读 → identity 恒 null → 拒绝回收 → fail-closed | **NSpid 等价证明**：readlink 失败时用 `/proc/<pid>/status` 的 `NSpid`（世界可读）证明同命名空间；多 NSpid/格式异常/不可证明仍 fail-closed | **已修 + 已验证**：`captureProcessIdentity` THROW→OK、`observeProcessIdentity=match` |
| R4 | `prepareAndStartUnlocked`（start/ownerless 收养）对排水观测**硬失败**，而 `reloadUnlocked` 是软失败（`isReloadWorkerDrainSoftFailure` 已存在）→ 长连接场景 reload 直接回滚 + fail-closed 停站（实测停站 ≈49s） | **B1–B4**：只对 `$wasRunning===true` 套软失败；结构化诊断日志；**upstream diff guard**（上流目标/端口变了仍硬失败）；9 条新单测 | **代码已上线 + 只读验证通过**；真实 reload 验收**未验证**（外部并发 actor 未清） |
| R5 | CPU steal 62–69%（8 vCPU，非 10）、有效核 ≈3.04；cgroup 无限流、KVM guest；冷渲染中位 5.0s、重编译 21–34s、缓存 HIT 0.14–0.51s；要稳定 ≤5s 需有效核 ≈6（st 归零或 16 vCPU） | 证据包 `capacity-evidence.md`（120 点时序 + 停 cron 对照 + 记账膨胀 1.9× 分析） | **已定稿（宿主侧，非本机可修）** |
| R6 | **外部并发 actor（P1）**：23:40–23:47 error.log 有 7 次 `nginx -s`，≥4 次非本团队；23:47 另一路成功提交发布（owner.json + `config_generation=3bdca464`）；`server:reload -r`(pid 1135497) 非本团队 | 站点柜加 HARD「静默窗口纪律」（4 条取证命令：`ps` 在飞 / `lsof` 锁 / error.log signal / `run/` 残留；单路、第 2 次失败即停、超窗立刻兜底） | **已记录，未根除**（跨会话，不可控） |

## 本轮上线（均为只读区，用户逐项放行）

```
ThemeLayoutEntityRequestSolidifyGate.php   e706dd88…   （放行：Theme 固化链路）
ManagedNginxProcessManager.php             927cfc91…   （F12；放行：Edge/Gateway 结构根治）
MasterLeaseRuntimeIdentity.php             0faaab2f…   （放行：MasterLeaseRuntimeIdentity）
ManagedNginxService.php                    31a34fc4…   （B；同一 Edge/Gateway 放行）
新增测试：MasterLeaseRuntimeIdentityNamespaceProofTest 8/34、ManagedNginxServiceDrainSoftFailureTest 9/25
本地提交：6dd81b84bc、e0ad380e36（+ 首轮六个提交）
测试：ManagedNginx 6 套 54 tests/613 assertions 全绿；ThemeLayoutEntityContractsTest 1010 断言（基线 997，8 条既有失败无新增）
```

## 本轮新增 P1 立项（未修）

1. **B5 回滚路径硬化**：`restorePublishedConfig()` 失败 + `stopManagedNginxFailClosed()` 预算不足（`ProcessManager:1358` 23s 门槛、`:2460` owner 在 reload 前取）→ **回滚失败导致停站**。目标：stop 预留预算 + 回滚失败不停站（保留 running + 报警）。
2. **外部发布/signal 检测 guard**：检测 owner/generation 被第三方改动 → 中止且不 rollback。
3. **F8 结构性锁**：lifecycle 锁改原子目录互斥（`f8-design.md` 已含 6 条边界阈值 + 420 行锁测试要改的 4 条）；前置依赖是 R3 的 NSpid 补丁（已具备）。
4. **solidify drain 终态**：`bake 成功但判据仍真` 无终态 → 应 `moveToFailed('solidify_unfulfilled:<reason>')` + 3 次 circuit。
5. **CapacityAdviceNotifier 在 DB 池耗尽时自激**（`max_size=24/in_use=24/leases=59`）：池饱和时应跳过通知；`exception.log` 已限流（实测 0 B/s 增长），立项不急救。

## 未验证清单（不计入已完成）

| 项 | 原因 | 下一步 |
|---|---|---|
| 连续两次 `server:nginx:reload` rc=0 | 外部并发 actor 未清 + B 首上 | 单写者静默窗口做 1 次 reload，再等 ≥30 分钟做第 2 次 |
| 4 次非本团队 `nginx -s` 来源 | 指向本机其他 DSH 会话，不可控 | 需 root shell 历史/journal/auditd |
| 第 2 段 49s 停站归因 | 我方 stop 因预算未执行，主因更可能是外部 signal（arch2 修正） | 同上 |
| B 在真实长连接 reload 下的效果 | 只做了反射级 + 源码契约验证 | 下次真 reload 时 grep `drain-soft publication decision` |
| upstream diff guard 真 reload 生效 | 同上 | 站点柜已加复查条目 |
