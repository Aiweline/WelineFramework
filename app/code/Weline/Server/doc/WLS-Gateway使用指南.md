# WLS 2.0 Gateway 使用指南

> 当前状态：**LOCAL_RUNTIME_VERIFIED / GA_BLOCKED_EXTERNAL**。当前源码的纯 WLS
> 直接数据面已在 macOS、Linux 和 Windows QEMU x64 兼容环境完成各 100 万次
> HTTP/2 请求且 0 错误；这不是 managed Gateway 公网 80/443、物理 Windows/MSVC/SCM、
> 系统冷重启或公网 CA/DNS 首签通过。纯 WLS HTTP/3/QUIC 与 HTTP/2 SSE DATA-frame
> 流式尚未实现。完整状态、报告摘要和待验证项见
> [WLS 当前能力与验收状态](WLS当前能力与验收状态.md)。

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
- 项目发布合同要求最终发行物在 `extend/server/wls-gateway/<target-profile>` 携带完整、
  签名且按平台锁定的 Gateway/Nginx 包；发布后必须满足。开发树或尚未被最终组装消费的
  overlay 不算已携带。该包是首次建立的受信来源，不是运行中宿主服务目录。
- 普通 `server:start --edge=auto|gateway` 可以在无既有网关时执行一次 fail-closed 的
  初始 install；不能借此 upgrade、repair、rebootstrap、promote 或接管未知服务。
- 项目持有域名、证书源、续签记录、UUID 和 generation。
- 宿主保存的 enrollment、路由、租约、证书快照和端口声明都是可重建派生状态。
- Linux systemd Controller 与项目共享宿主文件系统命名空间，不启用 `PrivateTmp`；因此
  enrollment 明确授权的 `/tmp`、`/var/tmp` 项目根或证书源不会在注册成功后变为不可见。
  该可见性不扩大读取权限：路径仍受 enrollment ACL、no-follow、普通文件/权限检查、
  复制前后摘要校验和内容寻址快照约束。
- 未知 80/443 owner 不受 WLS 管理；WLS 不询问、不停止、不修改该进程。

## 2. 启动模式

| 命令 | 行为 |
|---|---|
| `php bin/w server:start app --edge=auto` | 加入既有受信网关；若不存在且安全前置齐全，由首项目自动建立宿主独立网关并加入；其余情况启动 loopback 高端口纯 WLS |
| `php bin/w server:start app --edge=gateway` | 必须加入既有网关，或在安全前置齐全时完成首次建立；失败非零退出且不创建实例残留 |
| `php bin/w server:start app -p 9986 --edge=wls` | 完全绕过网关，纯 WLS 直接提供 TLS |
| `php bin/w server:start app -p 9986 --no-nginx` | `--edge=wls` 兼容别名 |

公开 `--edge` 只接受 `auto/gateway/wls`；`--edge=legacy` 和未知值都会非零退出。
`legacy` 是已保存 WLS 1.x 项目配置的内部兼容状态：首次只能从没有 edge mode 的
WLS 1.x 配置识别，随后可由运行时内部持久化为 `edge_mode=legacy`，以便重启后仍等待
显式提升；它不能用 CLI 创建。

优先级为 CLI > 实例配置 > 环境配置 > `auto`。gateway/legacy scope 中 `-p` 是
只绑定 loopback 的项目 backend；显式纯 WLS 中 `-p` 是 public port，冲突时非零
退出，不会静默改端口。只有未显式指定端口的 auto fallback 才分配 20000–29999。

auto fallback 默认只监听 `127.0.0.1`，输出会同时给出项目公开主机与高端口。该地址
不是 80/443 的透明替代；防火墙、DNS 或外部负载均衡是否可达必须单独确认。

### 2.1 首项目自动建立

首次建立只在宿主状态精确分类为 `INSTALL_REQUIRED` 时进入：平台原生查询必须明确证明
同名 service registration 不存在，current/legacy definition、metadata/link、slot、Launcher、
Guardian、active pointer 与安装/rebootstrap 事务等语义残留均不存在，80/443 没有未知
listener，并且固定项目分发路径存在匹配当前平台/架构的生产包。只有安全空目录、诊断
日志和已知 root-only lock scaffolding 可保留；未知或残留状态要求显式 repair。
WLS 先对项目路径逐级 no-follow，再用宿主包管理器校验 `release_ready`、签名、目标
profile、组件摘要与来源；校验失败时不得创建宿主 bootstrap 锁或信任根副作用。

预检通过后，启动进程在 root-only `package-bootstrap.lock` 下重查宿主状态。并发中的
胜者执行 stage → 平台定义 → activate → start → trusted status，且全程共用调用方的
同一绝对 deadline；等待者取锁后发现可信网关便直接加入，不能重复 stage。安装逻辑在
锁内重新从固定项目根解析包，并比较 canonical root/path、target/listen profile 及
manifest/package/signature digest，以阻断父目录替换和 TOCTOU。首装采用持久 journal 覆盖
stage → definition → activate → start → trusted status；只允许同一 fingerprint 幂等恢复，
其它残留要求显式 repair。只有控制面可注册、平台 ready、Nginx 数据面运行且不是
`DATA_PLANE_DOWN` 才算建立成功。安装内容复制到宿主 A/B 槽并交给平台守护后，首项目
停止或迁移不会停止网关。

未知 owner、权限不足、包缺失/无效或平台建立失败时，`auto` 只报告原因并降级纯 WLS
高端口；`gateway` 失败退出。WLS 不会因 PATH 或系统中“安装过 Nginx”就采用它，也不会
停止或修改实际占用 80/443 的未知进程。`edge=wls` 在决策入口即绕过宿主发现和建立。

发布边界必须单独验收：`wls-gateway-package.yml` 目前产出
`wls-gateway-project-distribution-*` overlay，同时包含目标平台签名包和启用公钥 inventory；
最终项目发布组装必须消费这个 overlay，并验证 manifest 的 signing key id 与 inventory
一致。当前仓库没有另一个会自动消费该 artifact 的最终组装 workflow，因此“artifact 已上传”
不能写成“所有现有项目已携带包”。未完成该发布集成，或发行工程没有注入启用公钥时，
项目目录会保持缺包/空信任库，生产 `auto` 必须 fail closed 到纯 WLS，`gateway` 必须失败。

### 2.2 稳定 Launcher 整代重引导

普通 A/B 升级只能在已冻结的宿主 Launcher 摘要下切换运行时槽位；
`restartControlPlane` 也只会重启这个已安装 Launcher，不能借此加载项目包中
已变更的 Launcher。需要更换 Launcher 或跨不兼容宿主运行时边界时，管理员必须显式执行：

```bash
php bin/w server:gateway:rebootstrap \
  --package=/absolute/path/to/signed-gateway-package \
  --profile=default \
  --nonce=<32-lowercase-hex> \
  --confirm
```

重引导先在 A/B 外封存并完整验证签名候选包，再要求 stop 回执同时证明
`accepted=true`、`data_plane_stopped=true` 和 `manual_cleanup_required=false`。平台守护已持久
停用、受管进程全部退出且 80/443 双重 bind 检查均通过后，才会将
Launcher 与 A/B 整代备份、原子发布，并在保持原 gateway epoch 的前提下连续观察
新数据面至少 15 秒。未知 80/443 owner 始终只读检查，WLS 不会终止或修改它。

WLS 2.0 v1 不在宿主启动时无人值守推进未完成的重引导事务。管理员在崩溃或断电后
必须重新执行完全相同的签名包、nonce 和 profile；签名 schema 3 日志会从精确持久阶段
幂等续传。已经发布 `START_AUTHORIZED` 或 `ROLLBACK_START_AUTHORIZED` 的精确代可以由
平台守护重新拉起，但守护不会替管理员推进、提交或结束事务。stop 之后任一步失败，
会先撤销候选启动授权，再回滚整代运行时、平台定义、派生状态和旧 gateway epoch；旧代
必须通过精确身份探针并连续健康至少 15 秒，才会完成回滚并重新开放入口。若回滚恢复
本身失败，则保留 `ADMIN_STOPPED` 和可重放 journal，不会以未知代开放 80/443。旧代备份
在签名 boot ID 与单调时钟证据下保留至少 24 小时；跨宿主启动或发现未来单调时间时，
重新起算完整 24 小时，不会提前回收。该 24 小时目前是经认证的恢复源/取证保留，
不等于 `COMMITTED` 之后已有可执行回滚入口。普通 A/B 升级的五分钟自动旧槽回退
不得冒充为稳定 Launcher/CA 整代重引导的提交后恢复能力。在独立 Recovery
Guardian、提交试用期与单调 generation-head 补齐前，不得宣称后者可自动回滚。

### 2.3 Linux systemd 定义与 Recovery Guardian 边界

Linux 的完整 unit 定义固定放在
`/etc/weline-gateway/weline-wls-gateway-v2.service`。该目录必须是
`root:root`、`0700` 且无 ACL；unit 的 `ReadWritePaths` 只额外授予此专用目录，
不会放开 `/etc/systemd/system`。系统搜索路径中的
`/etc/systemd/system/weline-wls-gateway-v2.service` 只能是指向前述绝对路径的
精确符号链接。安装以绝对 definition path 执行 `systemctl enable --now`，由 systemd
建立链接；后续 stop/restart 才使用已加载的 unit name。

Recovery Guardian 只会在专用目录中以同目录临时文件加原子 rename 恢复 definition，
并在恢复、对账和 live proof 时同时验证 canonical link 的 owner、无 ACL 的父目录和
精确 `readlink` 目标。链接缺失、相对链接、外来 regular file 或权限异常全部 fail-closed，
不会覆盖 `/etc/systemd/system` 中的对象。旧 schema-1 的 canonical regular unit 仅在
已证明为 WLS 自有的精确旧定义时由安装事务迁移；运营人员不要手工替换该链接或复制 unit。

`/var/lib/weline-gateway` 与 `/etc/weline-gateway` 可以是不同文件系统。重引导维护会
继续在 home 文件系统保留完整 generation reserve，并始终在 definition 同目录保留受
nonce 绑定的 4 MiB / 2 inode reserve（两个各 2 MiB 的精确文件，覆盖同目录原子发布的
staging 与 backup）。即使两个路径的 `st_dev` 相同，definition 目录仍单独预留，避免将
目录配额或 inode 预算错误地视作与 home 共用。`begin-release` 先持久化 home 侧
`TRANSITION` 标记并复验完整 reserve，再同步删除这两个专用目录 credits；因此 Guardian
实际原子写入前确实能使用预留容量。若在删除中崩溃，带标记的重放只会清理由名称、
no-follow、owner、mode、ACL、设备和实际块数共同证明的剩余 credit；`complete-release`
只幂等确认其已缺失，绝不再次消耗容量。两个文件系统各自保留各自的容量/原子写保护，
不会以跨文件系统 rename 换取“可用”，也不会因为独立 `/var` 挂载错误拒绝安全的 Linux
布局。

预停机取消遇到 PHP journal 已持久为 ALLOCATING 时，POSIX Launcher 先执行
--capacity-reserve=inspect，成功输出必须严格等于
{"schema":"wls-capacity-inspect/1","state":"NONE|ALLOCATING|HELD|RELEASING"}。
NONE 与经 no-follow/owner/ACL/内容验证的部分 ALLOCATING 只能使用无 manifest 的
complete-release cancel 清理，随后写入零容量、HMAC 签名、绑定 host/nonce/package/
profile/launcher/bytes/inodes 的 RELEASED receipt；它们绝不能触发重新分配或获得
HELD manifest。只有完整 HELD 才会由幂等 create 再验证后绑定 manifest，并走正常
begin-release/complete-release。RELEASING、多个 live state（exit 78）、外来或
损坏对象（exit 77）、参数错误（exit 64）以及 JSON schema 漂移都 fail-closed；任何
状态都不从 stderr 推断。receipt 在 journal 之前已发布的崩溃窗口只接受精确的零容量
cancel receipt、空 manifest 绑定和最终 NONE 原生 inspect，之后才可提升 journal。
Windows 在提供同一 inspect 与 definition-side direct reserve 合同前必须保持该路径
fail-closed。

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

WLS 会先区分项目根：

- 原路径仍存活的同宿主 clone：只在复制品内清除复制来的 active/pending
  宿主凭据，原子生成新 UUID，重置 desired/certificate/instance generation，
  然后执行全新 enrollment。它不会调用旧项目的 identity transfer，也不会删除
  原项目的凭据、宿主声明或路由。
- 当前根就是已声明的原项目根：才使用旧凭据 + pending 新凭据双证明，
  可恢复地原子转移 UUID 和宿主授权。

clone 的新身份会保留“待全新 enrollment”恢复标记；宿主已提交但本地凭据或
标记清理中断时，重试同一命令会幂等续传，不会再生成第二个 UUID；
若新 UUID 的宿主凭据已经完整落盘，恢复也会保留该精确 after-image，
只清除复制来的旧身份或 pending 能力。
完成回执也会持久化，因此进程在成功后、输出前异常退出时，再次执行仍只会返回已完成。
若该 clone 日后确实需要再做一次同根双凭据转移，必须显式使用：

```bash
php bin/w server:gateway:enroll --rotate-project-id --same-root-transfer --confirm
```

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

Master 的受保护 lease 是运行中实例的代际事实源。status、Benchmark 与生命周期命令
会只读叠加新鲜、非倒退且进程身份匹配的 lease，因此容器/namespace 外层 PID 不会把
健康 Master 误判为停止。heartbeat 对 instance、PID、control port、epoch 和 token
执行完整比较：另一新鲜代际已接管时旧 Master 立即 fail-closed；lease 连续 15 秒
不可确认时也停止，绝不反复覆盖新代状态。完整重启必须先以旧身份 CAS 推进 epoch，
再启动下一代子进程。

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

- 多项目加入网关时，默认回源不再共用 9981。WLS 按项目 UUID/实例身份在
  `20000–29999` 使用宿主锁、持久租约和实际 bind 分配独立稳定 loopback backend；
  CLI/实例显式指定的端口仍保持权威。
- 公网域名首次没有有效证书时，`--edge=gateway` 只启动 loopback HTTP 回源并报告
  `PENDING_CERTIFICATE`；普通 443、后台、前台和 REST 地址都不会提前发布。
  `--edge=wls` 以及 `auto` 的纯 WLS fallback 会返回
  `TLS_CERTIFICATE_UNAVAILABLE`，不会为了启动成功而隐式生成公网自签名证书。
- 既有证书只在未进入七天续期窗口、私钥与公钥配对且 SAN/CN 覆盖当前域名时
  才可复用；SAN 存在时以 SAN 为权威，不能以冲突 CN 绕过，只有无 SAN 旧证书才回退 CN。
  启动探针和实际签发分支共用同一判定。续签在原路径替换证书时，
  解析缓存会按内容 SHA-256 重新校验，不会沿用旧 SAN 结果。
- 项目在 `generated/acme-http01/.desired.json` 原子持久化 challenge generation、排序摘要和明确过期时间；每域名兼容投影仍由同一事实源生成，供纯 WLS Worker 读取。旧单文件会在已授权域名范围内迁移，符号链接、通配符 HTTP-01、非法 token/proof 一律拒绝。
- 目标域名属于共享网关时，证书服务必须先把本代 challenge 同步发布成功，再通知 CA 发起验证。Master 对明文 backend 的自动申请还要求 `gateway + wls-edge/2 + certificate_pending` 三项认证事实同时成立。首次 Agent 注册竞态使用严格 15 秒 monotonic publication barrier；退避不会越过剩余预算继续发布。失败不会丢弃项目待确认状态，也不会提前通知 CA；Gateway Agent 每 10 秒观察 generation/digest 并重放，30 秒无变化也会续同步。
- Controller 只接受 enrollment 与当前路由同时授权的精确域名，统一按 IDNA ASCII 规范化；低 generation 拒绝，同 generation 同摘要幂等对账、异摘要拒绝。同代同摘要会在活动 lease 漂移时恢复数据面；内容相同而 generation 前进时只持久化栅栏，不触发 Nginx reload。到期清扫产生的新 generation 同步落盘。
- 公开 Host 在写入实例状态前就转为 IDNA UTS46 ASCII，后续证书目录、DNS 归属校验、
  ACME 命令和网关 route 不会使用不同域名身份。运行时缺少 IDNA 能力时，非 ASCII
  域名配置会 fail-closed，不以 Unicode 原文继续注册或签发。
- `server:start` 只按当前实例的规范化 `public_host` 判断启动成功：主域 ACTIVE 才发布
  普通 HTTPS；显式 pending 且主域为 PENDING_CERTIFICATE 才接受 challenge-only。
  同宿主其他域名的 ACTIVE route 不能掩盖本项目主域缺失或失败。
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
- Windows release manifest 必须声明
  `windows_kernel32_ffi_atomic_write=true`。未签名组包阶段和安装后槽位 self-test 都会
  使用包内锁定 `php.exe` 检查 FFI extension、`ffi.enable`，并实际执行 kernel32
  `FFI::cdef`；只通过 `php --version` 不能满足发行合同。项目侧当前 PHP也在任何 UUID、
  租约或续签队列写入前执行同一门禁，因此纯 WLS 不依赖网关 Broker 才能安全启动。
- Windows 项目状态替换为每个目标最多 8 个、每目录最多 256 个恢复 backup 叶子设置
  硬配额；成功提交立即用内核 API 清理本代 backup，分享冲突导致的保留项达到配额后
  会 fail-closed 并要求修复，不能无限消耗目录或磁盘。
- 项目凭据目录会在 `.credentials.lock` 内有界回收崩溃留下的
  `<target>.tmp-<24hex>`。`<target>.wls-backup-<16hex>` 只在配对的 active/pending
  target 完整解析、host ID 与文件名一致，且 UUID/rotation 受项目持久事实授权后
  才删除；target 缺失、损坏或事实不匹配时保留 backup 证据并 fail-closed。
- 控制协议标识为 `wls-edge/2`；管理/项目双通道、OS peer identity、项目能力凭据、
  nonce 防重放、请求/响应认证、generation 和 fencing 均已接通。
- `repair`、`revoke`、`transfer`、`upgrade` 属于长管理事务，客户端和 Windows
  Native Broker 为候选验证、发布及稳定观察保留 90 秒响应窗；项目
  `register/renew/drain/unregister` 同样使用 90 秒响应窗，避免首个同步 publication
  尚未返回时发生幂等重放风暴。普通 status、own-status 和 heartbeat 仍使用短超时。
  项目撤销会建立不可逆墓碑；后续租约扫描、后端探测和迟到消息只能保持
  `REMOVED`，并清空全部后端路由身份，不能复活路由。
- Gateway Agent 不在 argv 或进程标题中携带 Master token；它从同实例、PID、epoch
  和新鲜 heartbeat 绑定的受保护 Master lease 读取凭据。POSIX lease 只允许 owner
  读写、服务组只读、other 无权限；组可写文件会被拒绝。Agent 会登记 PID/launch
  身份，因此 IPC 断开后可以通过身份栅栏安全补位。
- 新 A/B 槽在 active pointer 切换前封存为 root + dedicated gateway group：
  目录 0750、可执行文件 0550、普通文件 0440。旧槽未满 24 小时回滚保留期时，
  upgrade 必须失败，不能以“修复”为由覆盖。
- 宿主重启恢复 TLS/503 时，项目注册只要求受信 release、Broker 与 supervisor
  控制面 ready，不要求整体数据面已经 ready；否则会形成“注册依赖 ACTIVE、ACTIVE
  又依赖注册”的恢复死锁。
- 项目证书仍是唯一源；Native Broker 只从 enrollment 授权目录 no-follow 读取，
  校验私钥/SAN/权限和复制前后摘要后生成内容寻址快照。无效新证书不会替换 current。
- WLS 2.0 v1 对全部租户关闭 Nginx TLS session cache、session ticket 和 0-RTT。
  实测证明仅使用不同的 per-route ticket key 不能可靠隔离 TLS 1.3 恢复会话，因此
  v1 采用 fail-closed 策略；同一域名和跨域名恢复都必须重新握手。
- 状态盘为普通发布保留至少 16 MiB 安全阈值，并维护以不可压缩内容实际写入的独立 recovery reserve。任一原子 write/fsync/rename 或 reserve 重建失败会保留原 active/LKG、清理 staging、释放 reserve 并建立 `DISK_PRESSURE` marker；marker 即使剩余空间恢复也继续锁住新持久操作。Controller 带 marker 重启不重新分配 reserve，也不修复/隔离 state、security ledger、journal 或清理 stale runtime，周期维护只做数据面只读观察与有界快照 GC。管理员确认存储恢复后才重建 reserve、补齐待完成的 security-ledger bootstrap、清 marker，再对账 A/B 槽和中断发布。
- 宿主 A/B、LKG、隔离 503 和 Controller/Nginx 恢复逻辑已有专项覆盖；
  Linux 隔离 VM 已证明宿主 reboot 后先恢复 TLS/503、项目恢复后逐路由 ACTIVE，
  以及 90 秒故障触发、30 秒健康回切、301 秒排空释放。每个发布持久化边界的 kill
  恢复已在任务隔离 Controller/Nginx 中覆盖。
- Linux/macOS 的 auto Direct 默认使用 Master 持有的 `shared_fd`，Linux
  `reuseport` 仅作为显式性能选项。Linux event 共享监听会有界批量 accept，并定期
  对账内核就绪；reload 的 Worker 软期限覆盖最长 Nginx upstream keepalive，Master
  另留确认裕量。稳定 POSIX Launcher 在 Linux 使用 child-subreaper 并回收整棵受管
  进程树，避免 Controller/Nginx 后代退出后积累 zombie。
- 当前宿主网关可用于开发联调，不等同于计划完成后的生产可用声明。

## 6. 当前本机验收边界

> 当前结论与三平台百万报告以 [WLS 当前能力与验收状态](WLS当前能力与验收状态.md) 为准。下方带日期、旧测试数或旧任务号的内容只保留为历史回归证据；不得据此宣称物理 Windows/MSVC/SCM、macOS system-domain 冷重启或公网首签已经完成。

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
  恢复通过 `14 tests / 2054 assertions`；该证据不等于 launchd 安装/reboot；
- macOS 当前源码的两个独立纯 WLS 实例
  `ai-test-wls2-mac-session-a-0731:29661` 与
  `ai-test-wls2-mac-session-b-0731:29662` 真实复用同一 Session Server；
  `SessionStateFacade` 跨实例双向读写和持久化通过，两个注册信封在连续健康 30 秒后
  以相同证据摘要晋级 `shared_session`，两个公网入口实际协商 HTTP/2 并返回 200；
  测试实例停止后端口和 consumer token 均已释放，既有项目共享 sidecar 未被停止；
- `Gateway|Windows|Nginx` 跨平台门禁通过
  `283 tests / 3034 assertions / 18 capability skips`，含 Windows Native Broker
  的长管理事务 I/O 窗口；实际 Windows VM 因 Parallels 授权过期挂起，仍为
  `BLOCKED_ENVIRONMENT`；
- legacy 80/443 显式提升已在 Linux VM 成功：promotion v50 的维护窗 69.897 秒，
  连续公网稳定观察 12.216 秒，提升后项目和宿主网关生命周期相互独立；
- PostgreSQL 18 一次性克隆又以历史生产包的公开信任记录完成签名提升；全程未读取或
  临时派生私钥。systemd 启动故障在 2.559 秒恢复项目 Nginx，激活成功后配置持久化
  故障在 33.394 秒恢复；两条回滚均以 H1/H2 完整 200/133089-byte 响应确认，且旧
  Nginx 回到项目用户身份。无故障提升维护窗 39.729 秒，路由 ACTIVE 连续稳定
  12.054 秒，宿主 systemd/专用用户接管 80/443；项目正常停止只注销自身，宿主
  MainPID 保持不变，重新启动后同一路由恢复 ACTIVE；
- 同一签名 Linux 网关已完成真实 B→A 全包切换。实测证明项目运行时可能比已安装的
  稳定 Launcher 新：若升级发送新版 HUP/SCM 控制信号，旧 Launcher 可正常退出，
  `Restart=on-failure` 不会重新拉起。因此普通 A/B 事务只能在已冻结 Launcher 摘要下
  执行平台完整 service restart，保证已经退出时仍可启动回退槽；加载变更 Launcher 必须走
  签名、持久停机的整代 `server:gateway:rebootstrap` 事务。修复后连续三次终止身份已验证的
  A 槽 Broker，失败计数 1→2→3，第三次
  自动切回 B；systemd、ACTIVE route、项目 Master 及 H1/H2 200/133089-byte 响应正常。
  Windows restart 在 stop/start 间按 SCM 数字状态进行 100ms 有界轮询，分别等待
  STOPPED/RUNNING，单阶段最长 30 秒；该逻辑已有解析/合同回归，仍不替代 Windows 实机；
- 稳定 Launcher 不再把无 `ADMIN_STOPPED`、无平台 stop/reload 请求的 Broker 退出码 0
  透传为成功。该情形会统一转为失败，使 systemd、LaunchDaemon 和 Windows SCM 的
  恢复策略重新拉起宿主网关；管理员显式停止与签名停机意图仍保持成功退出且不重启。
  macOS 又以随机标签的临时用户 launchd 服务真实验证同一签名 Launcher：
  假 Broker clean exit 后至少启动两次，last exit code 为 1，测试后已 bootout 且无随机
  标签残留。该证据只验证 launchd 重启语义，不替代 root LaunchDaemon/ACL/reboot；
- 稳定 Launcher 在 Broker 回收后再次对账平台停机信号，Windows 也先发布 stop
  intent、再上报 `STOP_PENDING`。因此显式停机与 Broker clean exit 同轮发生时，
  不会被误分类为意外失败而诱发多余恢复；
- 旧签名槽位 PHP 8.5.8 与项目 PHP 8.5.4 对 `own-status` 浮点最短表示不同，曾导致
  解码后重编码验签失败。客户端现在先走现行规范串，失败时保留原始 JSON 数字词法
  重建排序规范串，HMAC 仍为强制条件；验签后递归移除 secret/token/private-key，
  只有结构严格校验通过的 enroll 一次性凭据可交给凭据存储，CLI 再做第二层脱敏；
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
- 当前 Linux 隔离网关复测以 10 个独立 H2 批次完成
  `1,000,000 started/done/succeeded`、0 failed/errored/timeout、0 非 2xx；
  总计约 443.32 秒、约 2255.7 QPS。全程 generation 54、route ACTIVE、8/8
  worker；结束后的 `CLOCK_STABLE` 观察自动收敛为 `HEALTHY/NONE`。
- Master 代际栅栏、Linux event/shared_fd 与 reload 排水修复后的纯 WLS 当前源码
  报告
  `benchmark_report_20260731_060651_752486_wls-health_pid137902.json`
  为 HTTP/2 1,000,000/1,000,000 成功、0 失败、质量门禁 PASS；12810.93 QPS，
  P95 33.601ms、P99 81.068ms。压测后 epoch 3、8/8 Worker、lease failure 0。
- 同一修复加载到网关租户并优雅重新注册后，单次当前源码报告
  `benchmark_report_20260731_062550_997297_wls-health_pid144076.json`
  为 HTTP/2 1,000,000/1,000,000 成功、0 失败、八个 Worker 全命中、质量门禁
  PASS；2982.61 QPS，P95 197.058ms、P99 301.624ms、TLS P95 14.493ms。结束后
  route `ACTIVE`、网关 `HEALTHY`、Master lease running/epoch 9。
- 签名 legacy 提升与跨版本验签/脱敏全部修复后的最终报告
  `benchmark_report_20260731_153807_322176_wls-health_pid35175.json` 为 HTTP/2
  1,000,000/1,000,000 成功、0 错误、8/8 Worker 全命中、质量门禁 PASS；
  4268.46 QPS，P95 53.729ms、P99 84.544ms。压测前后项目 Master PID 32301、
  网关 epoch/route generation 均稳定；结束后宿主 MainPID 25536、route ACTIVE、
  网关 HEALTHY，status/routes 原始敏感键扫描为 0；
- 当前聚焦回归：Gateway 协议安全 `49 tests / 1222 assertions`；Master
  lease/epoch/overlay、reload drain、MemoryPressure、EventExtLoop、运行策略和
  Worker 请求边界 `93 tests / 414 assertions`；Linux Native Launcher
  `7 / 290`，macOS 同源 `7 / 266 / 1 Linux-only skip`。23 个变更 PHP 文件 lint
  与 diff check 均通过。
- 使用正式框架 bootstrap 的全量 Weline_Server 回归为
  `1409 tests / 7363 assertions / 20 capability skips`，零错误、零失败；当前
  `NativeGateway(Broker|Launcher)` 聚焦复跑为
  `12 tests / 393 assertions / 1 Linux-only skip`。

- 当前源码已在同一 Linux PostgreSQL 宿主完成严格矩阵：网关 H1/H2、纯 WLS H1/H2
  均 warmup 后三轮、每轮精确 1,000,000；双租户 H2 三轮均为两个项目各 500,000。
  全部 0 错误并通过实际协议、body、tenant、Worker 和网关身份门禁；四类单数据面的
  中位 QPS 依次为 5724.44、5618.14、28265.88、29517.21。
- Controller 原位接管和 Worker rolling reload/drain 期间分别完成 300,000 次持续
  H2 流量，均 0 错误。expected-reload 门禁证明 Master 不变、前后 Worker 身份健康
  且代际指纹变化；真实 SSE 完成后承载 Worker 以长连接计数全零自然排空。
- 多项目内存压力容量变更现在由同用户宿主级短租约串行化；协调状态异常时紧急缩容
  fail-open，而恢复扩容 fail-closed；生产 claim 使用跨进程 monotonic 时间并隔离
  reboot 后旧状态，紧急缩容可抢占恢复 claim，初始化失败按 monotonic 30 秒重试且不
  改写原始容量上限；claim 时间窗必须是有限且位于 1–300 秒内，合法 JSON 中的异常
  时间值也不能长期阻断紧急缩容。协调锁与 Windows 状态替换回退锁都以 250ms
  monotonic deadline 获取；macOS/Windows boot identity 探针超时后执行有界温和/
  强制终止。READY 后的后台首渲染、动态首渲染和 FPC process-pull 默认关闭，只有
  显式配置才运行，避免可路由 Worker 被高成本预热阻塞。显式
  `--expect-reload` 在无法取得唯一权威 WLS 身份时直接失败，不跳过 Worker 门禁。
- 两个压测租户分别停止后只注销自身，宿主 Launcher/Broker/Nginx/Controller 和
  IPv4/IPv6 80/443 保持，网关继续 `ready/HEALTHY`。在线旧槽仍有两个被 PID 1 收养
  的无资源 zombie Master；当前源码的 child-subreaper/reaper 回归已经通过，需在
  遵守 24 小时 A/B 保留后的正常升级或 reboot 后复验，不通过强制切槽掩盖该观察。

TEST-036 的严格性能与持续流量合同已经通过，但这不等于 TASK-014 可以完成。其明文
前置仍是 TASK-013 全绿；当前 macOS/Windows 系统服务与 ACL/reboot、Windows 实机、
外部 CA/DNS 公网首次实签尚未全部闭合。Linux PostgreSQL legacy promote 的签名成功、
启动失败回滚和激活后失败回滚已经闭合，不再属于发布阻断。

本轮已补齐 Session/无状态能力的运行时写入、认证证明、30 秒恢复观察、实例摘要心跳
重放与多实例 generation 防抖；首次证书启动/发布 CI 门禁通过 `78 tests / 394 assertions`，
并由 PostgreSQL 16 与 fail-closed bootstrap 明确禁止 SQLite。deferred 证书服务不会在
gateway `PENDING_CERTIFICATE` 前初始化项目 ORM；配置恢复、本地/泛域证书与 SNI map
也只会在有效 edge 决策后执行，公网 gateway 不受这些 legacy 数据库副作用阻塞，
真正签发/入库仍要求 PostgreSQL。
ACME/Edge Protocol 2 聚焦回归通过 `101 tests / 1520 assertions`，聚焦
真实数据面恢复通过 `3 tests / 1662 assertions`，Native Broker/Launcher 聚焦门禁通过
`9 tests / 334 assertions`。共享 Session 空闲关停使用进程内单调完整 grace 与向上取整的
跨进程截止投影，发布工作流独立门禁为 `6 tests / 32 assertions`。磁盘压力注入回归通过
`1 test / 59 assertions`，完整 Gateway 协议通过 `43 / 1055`；
Linux 隔离 ext4 已真实覆盖 ENOSPC、`IFree=0` 和只读 remount，均保持原数据面并在
确认修复后重建 reserve、清除 marker。当前仍未覆盖 macOS/Windows 系统服务
ACL/reboot、Windows 实机和外部 CA/DNS 公网首次实签。

原生 CI 不再只做编译与组件 self-test：Ubuntu/macOS 会安装 `composer.lock` 指定的
依赖并直接执行签名 Launcher 生命周期集成测试，同时分别启用 transient
systemd/launchd 恢复场景；Windows 除编译并执行 Broker、Launcher 和 Controller
self-test 外，还会以一次性签名槽和无端口测试 Broker 建立临时 SCM 服务，验证意外
clean exit 自动重启及显式 stop 保持停止，并让真实 Broker 在 NTFS 上拒绝宽授权私钥
及源/目标 reparse。macOS 同时使用随机 root:wheel 目录和 0644 plist 验证
system-domain LaunchDaemon；root Broker 只写 root-owned 测试目录，finally 会先尝试
bootout 再清理。固定 Windows 服务已存在时测试拒绝替换，删除服务、目录或临时私钥
失败都会使门禁失败；测试 helper/private snapshot 命令默认不构建且不进入生产安装清单。
Native 测试源码及 Composer 依赖变化均触发相应门禁，job 有
45 分钟总期限。同一可执行组合已在当前 macOS 通过
`10 tests / 307 assertions / 3 honest skips`，Host 合同通过 `23 / 488`，PostgreSQL
发布组合通过 `30 / 663 / 1 capability skip`；此前 Linux 验收 VM 为
`9 / 327 / 1 macOS-only skip`。托管 Runner 的 system-domain/SCM/NTFS 结果必须以
提交后的 GitHub 工作流为准。门禁实际通过前不能声明对应平台已验收；通过后仍不替代
macOS/Windows 冷 reboot、Windows rename 竞态或公网 CA/DNS 验收。

稳定 Launcher 的退出码现在分为“Broker 原始结果”和“已授权控制结果”：只有平台
HUP/PARAMCHANGE 或签名 A/B 迁移才能产生内部 reload，Broker 自行返回保留码 254 会按
失败处理；平台 stop、shutdown 或签名 `ADMIN_STOPPED` 一旦成为权威意图，则 Broker
即使非零退出也以正常停机结束。POSIX stop 信号优先于 HUP 并在阻塞信号后原子消费；
Windows 通过 SRW lock 发布/撤销 stop event，以单调 reload generation 合并请求，
stop/reload 都有 15 秒强制收敛期限。

Windows 私钥快照不再只排除三个宽用户组。Broker 会枚举 DACL 中所有授予文件内容读取
权的 allow ACE，仅接受已登记项目所有者、LocalSystem、Administrators、Gateway 服务
SID 和 Windows 所有者语义；未知或对象型可读 ACE 直接拒绝。原生读取同时要求普通文件、
1 字节至 1 MiB、每次 `ReadFile` 成功且总读取量与稳定文件大小一致，失败候选不得发布。
托管 NTFS 夹具另外覆盖具体无关 SID、空/超限文件和源/目标 reparse。

特权平台门禁只从独占的 root-owned/system ACL 根执行 Launcher 与签名槽；systemd 或
launchd 提交一经尝试，finally 都会按唯一 unit/label 回收，且只删除已成功创建的随机
根。原生命令全部有界，POSIX PHPUnit 只加载 `vendor/autoload.php`，不会建立 SQLite
应用沙箱。Windows 密钥使用独占创建和受限 ACL，服务夹具以唯一二进制路径认领部分创建
状态，stop 与 delete 分阶段清理，工作流另有 `always()` 密钥清理兜底。
Linux 宿主包已经完成来源、依赖、SBOM、
自检与签名门禁，但不会绕过 A/B 旧槽至少保留 24 小时的策略替换在线槽位。当前
`controlrestart6` 候选包已由最新 Controller 源码构建并签名；在线宿主因 B 槽仍在
回滚保留期而正确拒绝切槽。Windows Native Broker、Named Pipe/DACL 与超时策略已有
静态和跨平台回归；2026-07-31 再次恢复现有 Parallels Windows 11 VM 时，平台明确因
限时授权过期拒绝恢复。只有在合法可运行的 Windows Service 实机/reboot 验收完成后，
才可声明该平台 release-ready。

完整需求、缺陷映射和验收矩阵见
`迁移前历史记录（已清理）`。
