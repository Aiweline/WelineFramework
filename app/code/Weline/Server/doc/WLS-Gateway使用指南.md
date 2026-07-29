# WLS 2.0 Gateway 使用指南

> 状态：实施中。edge 决策、稳定项目身份、纯 WLS 降级、Native Platform Broker、
> 双通道鉴权、enrollment、证书快照、事务发布、A/B/LKG/熔断和 Controller/Nginx
> 恢复链均已有实现与专项验证；Linux systemd、真实 80/443、宿主 reboot、legacy
> 显式提升、fallback/rejoin、双租户 H2/H3 百万和撤销墓碑不变量已在隔离 VM 通过。
> macOS 原生 Broker/Launcher/数据面已在随机端口实测；macOS/Windows 系统服务 ACL
> 与 reboot、Windows 实机、首次 ACME 和剩余发布门禁尚未全部完成。

## 1. 角色边界

```text
Client
  -> 宿主级 Weline Gateway（唯一 80/443 owner）
  -> 项目 loopback WLS backend

或

Client / 本机运维探针
  -> 项目纯 WLS TLS 高端口
```

- Gateway 是宿主基础设施，不属于引导它的首个项目。
- 普通 `server:start` 只能 discover/join；不能隐式 install、upgrade 或 repair。
- 项目持有域名、证书源、续签记录、UUID 和 generation。
- 宿主保存的 enrollment、路由、租约、证书快照和端口声明都是可重建派生状态。
- 未知 80/443 owner 不受 WLS 管理；WLS 不询问、不停止、不修改该进程。

## 2. 启动模式

| 命令 | 行为 |
|---|---|
| `php bin/w server:start app --edge=auto` | 加入既有受信网关；不可用时自动启动 loopback 高端口纯 WLS |
| `php bin/w server:start app --edge=gateway` | 必须加入既有网关；失败非零退出且不创建实例残留 |
| `php bin/w server:start app -p 9986 --edge=wls` | 完全绕过网关，纯 WLS 直接提供 TLS |
| `php bin/w server:start app -p 9986 --no-nginx` | `--edge=wls` 兼容别名 |

优先级为 CLI > 实例配置 > 环境配置 > `auto`。gateway/legacy scope 中 `-p` 是
只绑定 loopback 的项目 backend；显式纯 WLS 中 `-p` 是 public port，冲突时非零
退出，不会静默改端口。只有未显式指定端口的 auto fallback 才分配 20000–29999。

auto fallback 默认只监听 `127.0.0.1`，输出会同时给出项目公开主机与高端口。该地址
不是 80/443 的透明替代；防火墙、DNS 或外部负载均衡是否可达必须单独确认。

## 3. 项目身份

首次启动会在项目中原子创建：

```text
app/etc/wls-project.json
```

文件包含随机 UUIDv4、desired/certificate digest 和单调 generation。相同摘要的并发
提交取得同一 generation；摘要变化只递增一次。旧 prototype 的
`var/server/gateway-v2/desired-generation.json` 只在首次创建时迁移，避免 generation
回退。

- 移动项目目录：只要旧路径已消失，UUID 保持不变并更新宿主声明。
- 复制项目：原路径仍存在时，同宿主 clone 会被拒绝。
- clone 需要新身份：

```bash
php bin/w server:gateway:enroll --rotate-project-id --confirm
```

rotate 会生成新 UUID 并把两个 generation 重置为新项目事实；它不会替复制品撤销原
项目的宿主路由。

## 4. endpoint 与 Agent

每次启动生成随机 `launch_id`，endpoint 保留：

- project UUID、instance ID；
- master epoch、launch ID；
- immutable edge decision：adapter、requested/effective mode、scope、source、
  fallback reason；
- gateway epoch/public ports（仅 gateway mode）。

后台 Master 从 endpoint 恢复同一 decision，并把唯一运行时 mode 投影到
`wls.edge.mode`。显式 `edge=wls` 与 legacy 不启动 Gateway Agent；请求为 `auto`
但因网关暂不可用而降级的实例会保留同一 Master 内 Agent，低频发现可信网关。加入时
先建立独立 loopback Gateway Backend 池并完成真实公网探针，连续健康 30 秒后对原生
WLS TLS 入口执行固定 300 秒排空。Agent 每 10 秒重试推进事务但不重置 deadline；
到期后有界关闭原生 Worker/Dispatcher，项目 endpoint 才从
`NATIVE_EDGE_DRAINING` 提升为 `GATEWAY_ACTIVE/DRAINED`。`desired=0` 的原生槽位
不会被健康检查或 IPC 断线恢复逻辑复活。

### 4.1 多实例分流能力

多实例默认始终是确定性首选实例加健康热备，不能仅凭 endpoint 字段开启轮询。分流需要
管理员 enrollment 与每个活动实例的运行时证明同时成立：

- 无状态项目必须在运行配置中设置 `gateway.backend_capability=stateless`，并由管理员使用
  `server:gateway:enroll --stateless --confirm` 授权。启动时会把声明绑定到当前
  `instance_generation`；控制器逐实例核对，不能复用另一实例或旧启动的证明。
- 共享 Session 项目不能静态声明 `shared_session`。WLS 会读取实际 Session 配置，只接受
  已注册的 loopback `session_server`，使用项目 token 完成认证健康探针，再由管理员使用
  `server:gateway:enroll --shared-session --confirm` 授权。任一活动实例缺证明时，全项目保持
  单实例首选/热备。
- Session 故障立即降为 `isolated`；恢复后必须连续健康 30 秒才重新提交
  `shared_session`。派生资格保存在项目 `var/server/gateway-v2`，不属于可迁移事实源；项目
  移到新宿主时会安全地重新经过观察窗。
- 项目 generation 只描述共享路由、证书和稳定的 `runtime_attested` 协议策略，不混入
  任一实例当前的能力模式或证明。完整证明只进入 instance digest；heartbeat 发现摘要变化时
  要求 Agent 重放完整 register，同一 master/launch 只能更新能力字段，不能夹带后端、域名、
  证书或路由变更。stateless 不写项目恢复状态；`isolated` 诊断原因也不改变实例路由身份。
- Controller 只在每个活动实例都保留完整且摘要匹配的能力证明时启用多后端；
  `shared_session` 还要求所有实例的证据摘要完全一致。不同 Session 服务、旧状态缺证明或
  证明损坏都会立即退回确定性单实例，不把请求分发到会话不相容的后端。
- `--edge=wls` 不需要 enrollment，也不依赖宿主派生资格；证书仍从项目事实源读取。

### 4.2 ACME HTTP-01 事实源与网关发布

- 项目在 `generated/acme-http01/.desired.json` 原子持久化 challenge generation、排序摘要和明确过期时间；每域名兼容投影仍由同一事实源生成，供纯 WLS Worker 读取。旧单文件会在已授权域名范围内迁移，符号链接、通配符 HTTP-01、非法 token/proof 一律拒绝。
- 目标域名属于共享网关时，证书服务必须先把本代 challenge 同步发布成功，再通知 CA 发起验证。发布失败不会丢弃项目待确认状态，也不会提前通知 CA；Gateway Agent 每 10 秒观察 generation/digest 并重放，30 秒无变化也会续同步。
- Controller 只接受 enrollment 与当前路由同时授权的精确域名，统一按 IDNA ASCII 规范化；低 generation 拒绝，同 generation 同摘要幂等对账、异摘要拒绝。同代同摘要会在活动 lease 漂移时恢复数据面；内容相同而 generation 前进时只持久化栅栏，不触发 Nginx reload。到期清扫产生的新 generation 同步落盘。
- 若宿主存在网关但不承载目标域名，项目仍按纯 WLS 路径发布；只有匹配目标域名的网关注册失败才 fail-closed。未指定单域名的全量重放要求所有网关注册视图完整，绝不提交部分子集。清理 challenge 同样推进 generation 并同步空集合，失败可由 Agent 恢复。
- 以上闭合的是项目到本机网关的数据面可达性与恢复链；外部 CA/DNS 的公网首次签发仍必须在具备真实域名、DNS 和 CA 网络的隔离环境单独验收。

## 5. 当前安全约束

- legacy 与后续 host gateway 共用 Nginx runtime 原语：进程操作要求 PID、精确 argv、
  binary digest、runtime generation 和 PID-bound identity 全匹配；配置支持候选预检、
  active/rollback、完整多文件反向回滚和真实 HTTP generation 探针。
- 不可变 runtime artifact 在候选目录逐组件复制/复核摘要后整槽切入，拒绝覆盖既有槽、
  相对路径穿越、符号链接和组件摘要变化。宿主包已有签名、SBOM、provenance、
  Native Broker 与平台服务定义门禁；Linux/macOS/Windows 的系统服务、ACL 和 reboot
  仍需隔离 host/VM 实证。
- 控制协议标识为 `wls-edge/2`；管理/项目双通道、OS peer identity、项目能力凭据、
  nonce 防重放、请求/响应认证、generation 和 fencing 均已接通。
- `repair`、`revoke`、`transfer`、`upgrade` 属于长管理事务，客户端和 Windows
  Native Broker 为候选验证、发布及稳定观察保留 90 秒响应窗；普通状态和项目通道仍
  使用短超时。项目撤销会建立不可逆墓碑；后续租约扫描、后端探测和迟到消息只能保持
  `REMOVED`，并清空全部后端路由身份，不能复活路由。
- 项目证书仍是唯一源；Native Broker 只从 enrollment 授权目录 no-follow 读取，
  校验私钥/SAN/权限和复制前后摘要后生成内容寻址快照。无效新证书不会替换 current。
- WLS 2.0 v1 对全部租户关闭 Nginx TLS session cache、session ticket 和 0-RTT。
  实测证明仅使用不同的 per-route ticket key 不能可靠隔离 TLS 1.3 恢复会话，因此
  v1 采用 fail-closed 策略；同一域名和跨域名恢复都必须重新握手。
- 宿主 A/B、LKG、熔断、隔离 503 和 Controller/Nginx 恢复逻辑已有专项覆盖；
  Linux 隔离 VM 已证明宿主 reboot 后先恢复 TLS/503、项目恢复后逐路由 ACTIVE，
  以及 90 秒故障触发、30 秒健康回切、301 秒排空释放。每个发布持久化边界的 kill
  恢复已在任务隔离 Controller/Nginx 中覆盖。
- 当前宿主网关可用于开发联调，不等同于计划完成后的生产可用声明。

## 6. 当前本机验收边界

macOS opt-in 验收只在随机高端口启动任务自有 Broker、Controller、Nginx 和 backend，
不会安装平台服务、绑定 80/443 或操作既存网关；Linux 验收只在隔离 Lima VM 使用
真实 systemd 与 80/443。当前已证明：

- 两个项目并发注册，域名、body 和证书 fingerprint 隔离；
- 四轮 heartbeat 后路由保持 ACTIVE，同项目双实例停一仍返回正确 200；
- 实际 H1/H2/H3、未知域名 404、SNI/Host 不一致 421、重复 Host 与 CL/TE 400；
- Controller 故障期间持续流量无错误且 Nginx 不 reload；
- Nginx 连续三次核心探针失败后只恢复任务自有进程；
- Linux 冷重启且引导项目源码暂时移走时，宿主包独立启动并以原证书完成 TLS、后端
  返回 503；项目恢复后路由重新 ACTIVE；
- 网关数据面持续故障时，同一 Master 在 90 秒后开放稳定高端口；网关 ACTIVE 连续
  30 秒后排空，按持久化时间戳 301 秒释放端口和租约；
- auto 从原生高端口发现网关后，8/8 独立 Gateway Backend 先 READY；项目端点原子
  投影网关 epoch 与 80/443，原入口保持 300 秒排空，到期后关闭且不复活；
- H3 运行时激活失败时只关闭 UDP/443，H2 与 H1.1 的真实请求继续返回 200；
- WLS 2.0 v1 全局关闭 TLS session cache、ticket 与 0-RTT，跨租户和证书轮换后均不
  复用旧 TLS 1.3 会话；
- macOS 显式启用原生集成后，Native Broker、Launcher、签名槽、崩溃回滚和真实数据面
  恢复通过 `12 tests / 1985 assertions`；该证据不等于 launchd 安装/reboot；
- `Gateway|Windows|Nginx` 跨平台门禁通过
  `257 tests / 2673 assertions / 15 capability skips`，含 Windows Native Broker
  的长管理事务 I/O 窗口；实际 Windows VM 因 Parallels 授权过期挂起，仍为
  `BLOCKED_ENVIRONMENT`；
- legacy 80/443 显式提升已在 Linux VM 成功：promotion v50 的维护窗 69.897 秒，
  连续公网稳定观察 12.216 秒，提升后项目和宿主网关生命周期相互独立；
- 提升后修复了 Worker 私有端口落入 Linux 临时端口范围造成的延迟
  `EADDRINUSE`。当前私有 Worker 端口在候选触及 32768 时稳定归一化到
  `10000–16999`，并统一避让主端口、控制端口、maintenance/surge 端口；真实 v53
  的 8 个 Worker 稳定监听 16217–16224；
- 修复后网关 H2 百万报告
  `benchmark_report_20260729_091823_496626_wls-health_pid131366.json` 为
  1,000,000/1,000,000 成功、0 失败、HTTP/2 命中 100%、8 个 Worker 全命中、
  质量门禁 PASS；2892.78 QPS，P95 187.865ms，P99 301.984ms，压测后公网仍为
  HTTP/2 200、网关 `HEALTHY`、恢复计数 0；
- 当前 v54 双租户 H2 混合百万由两个独立项目各完成 500,000 请求，均 0 失败、实际
  HTTP/2 与 tenant marker 100% 精确；QPS 8268.25/10433，P95
  3.137/3.043ms。双租户 H3 混合百万同样各 500,000 请求、0 失败，ALPN `h3`、
  tenant 和 UUID 全量精确；QPS 3166.78/3262.12，P95 44.218/39.847ms；
- v54 独立项目撤销在 30.67403 秒返回成功，约 3 分钟和三次后续采样均保持同一
  `removed_at`、空后端身份和 `REMOVED`；撤销域名中性 421，幸存租户 HTTP/2 200，
  网关保持 `HEALTHY`。多项目故障隔离夹具必须为每个项目使用独立受管 runtime；
- 使用正式框架 bootstrap 的全量 Weline_Server 回归为
  `1327 tests / 6699 assertions / 17 platform skips`，零错误、零失败。

本轮已补齐 Session/无状态能力的运行时写入、认证证明、30 秒恢复观察、实例摘要心跳
重放与多实例 generation 防抖；ACME/协议专项通过 `101 tests / 1269 assertions`，聚焦
真实数据面恢复通过 `1 test / 1476 assertions`，Native Broker/Launcher 聚焦门禁通过
`9 tests / 334 assertions`。当前仍未覆盖 macOS/Windows 系统服务 ACL/reboot、
Windows 实机、外部 CA/DNS 公网首次实签、完整
Session daemon 实机亲和和同条件三轮性能中位数。Linux 宿主包已经完成来源、依赖、SBOM、
自检与签名门禁，但不会绕过 A/B 旧槽至少保留 24 小时的策略替换在线槽位。现有 v54
双租户 H2/H3 百万仍证明未改动的数据面；本轮控制面提交没有在新签名宿主包上重跑百万，
因为旧 Lima 性能宿主已被时钟故障注入锁定为 `CLOCK_UNTRUSTED`。该安全状态未被清除或
降级，当前源码百万复测仍属于环境边界，不得写成已通过。

完整需求、缺陷映射和验收矩阵见
`dev/ai/plans/2026-07-27-WLS-2.0-多项目共享网关与自动恢复.md`。
