# WLS 2.0 Gateway 使用指南

> 状态：实施中。edge 决策、稳定项目身份、纯 WLS 降级、Native Platform Broker、
> 双通道鉴权、enrollment、证书快照、事务发布、A/B/LKG/熔断和 Controller/Nginx
> 恢复链均已有实现与专项验证；Linux systemd、真实 80/443、宿主 reboot 和完整
> fallback/rejoin 与 auto native→gateway 300 秒排空时序已在隔离 VM 通过。
> macOS/Windows 系统服务与 ACL、首次 ACME、
> legacy promote 和剩余发布门禁尚未全部完成。

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
- Nginx 连续三次核心探针失败后只恢复任务自有进程。
- Linux 冷重启且引导项目源码暂时移走时，宿主包独立启动并以原证书完成 TLS、后端
  返回 503；项目恢复后路由重新 ACTIVE。
- 网关数据面持续故障时，同一 Master 在 90 秒后开放稳定高端口；网关 ACTIVE 连续
  30 秒后排空，按持久化时间戳 301 秒释放端口和租约。
- auto 从原生高端口发现网关后，8/8 独立 Gateway Backend 先 READY；项目端点原子
  投影网关 epoch 与 80/443，原入口保持 300 秒排空，到期后关闭且不复活。状态命令
  在排空期显示原生入口，完成后只显示共享网关，不再把已关闭高端口伪报为 fallback。
- H3 运行时激活失败时只关闭 UDP/443，H2 与 H1.1 的真实请求继续返回 200。
- 真实 TLS 1.3 检出旧设计可跨路由复用会话后，v1 已改为全局禁用恢复；最新源码
  数据面集成验收为 1 test / 1395 assertions。
- 纯 WLS H2/H1.1 与网关 H2/H1.1 已完成精确百万请求且错误为 0；多项目混合百万、
  可比性能中位数和 H3 百万仍是 TASK-014 的剩余门禁。
- 修复后的 `server:benchmark --instance` 会按 target surface 选择普通 Worker 或
  Gateway Backend 的 PID/IPC lease/generation 指纹。Linux 隔离 VM 在原生入口
  `DRAINED` 后完成网关 H2 精确 1,000,000/1,000,000、0 错误、质量门禁 PASS：
  6182.44 QPS，P95 101.23ms，P99 198.54ms，8 个 Gateway Backend 全部命中；
  161.748 秒压测跨过两个以上内存压力扩容周期，普通 Worker 仍保持 0。
- 网关在项目启动前已健康时不需要 join backend，正常使用普通 Worker/Dispatcher
  作为私网回源；同一 VM 的 10,000 次 H2 门禁为 10,000/10,000、0 错误、8 个
  普通 Worker 全命中、质量门禁 PASS。

这组证据仍不覆盖 macOS/Windows 系统服务与 ACL/reboot、首次 ACME、真实 legacy
80/443 promote、Session 运行时亲和和完整百万请求
发布门禁。Linux v38 生产候选已经完成来源、依赖、SBOM、自检与签名门禁，但不会绕过
A/B 旧槽至少保留 24 小时的策略去替换在线 v36。

完整需求、缺陷映射和验收矩阵见
`dev/ai/plans/2026-07-27-WLS-2.0-多项目共享网关与自动恢复.md`。
