---
status: ready-for-plan
work_kind: feature
feature_slug: wls-worker-memory-tuning
module: Weline.Server
updated: 2026-11-11
session_path: dev/session/wls-worker-memory-tuning.md
fe_be_scope: WLS runtime + backend WLS panel + Nginx request telemetry input
---

# WLS Worker memory tuning and telemetry

## 1. 目标

在每 Worker 256MiB 上限内执行 admission、drain、restart 三档策略；采集 Fiber/FPC 请求的 `real` / `used` memory delta、耗时和 FPC 分类；通过内部 request ID 去重后按规范化路由前缀聚合，按 p95 `real` delta 排名并同时显示平均值。后台 WLS 面板提供运行数据、预算估算、SmartTuning 与聚合历史保留配置。SmartTuning 开启时自动应用、关闭时保留人工值；自动扩缩容只改变 Worker 数量，并遵守现有 Worker/host 上限。

用户已确认的行为已写入「已确认澄清」与各 EARS/UC；未冻结的持久化、IPC、Nginx 接入及算法边界单列为 P1/P2 设计风险。本规格不选择这些实现方案。

## 2. 范围

### 包含

- 每 Worker 256MiB 内存上限；固定 headroom 阈值 admission/drain/restart 为 50/45/10MiB；百分比模式为 30%/15%/10%，基数为 Worker `memory_limit`。
- 每个 Fiber 请求记录 `real` / `used` memory delta、耗时、Worker 身份及 FPC 分类（`cold`、`fpc_hit`、`static`、`api`）；每 5 秒生成有时间标记的运行快照。
- Nginx `$request_id` 通过内部 upstream header `X-Weline-Request-ID` 与 WLS Fiber/FPC 事件关联；Nginx 使用结构化记录，先按 request ID 去重/关联，再聚合；不向浏览器响应头添加该 ID。
- 路由汇总移除 query/body，数字与 UUID 路径段归一化；TOP-N 按 p95 `real` delta 排名，同时展示平均值。
- 后台 WLS 面板查看运行数据、预算估算、SmartTuning/阈值状态，并受权读写策略及聚合历史保留配置。
- 保守 cold 请求模型取最近窗口中各 Worker 最坏的 cold p95；SmartTuning 开启时自动应用、关闭时保留人工值。
- SmartTuning 开启时自动控制 Worker 扩容与缩容；只改变 Worker 数量，复用现有 `CapacityAdvisor` / `CapacityScaleApplier` 能力并遵守其既有 Worker/host 扩缩边界；单 Worker `memory_limit` 保持 256MiB。
- 仅保留聚合历史，默认 7 天；授权操作员可配置保留天数或永久保留；原始请求记录及 request ID 不作为历史保留。

### 不包含

- 提高、自动调整或绕过每 Worker 256MiB 内存上限；本功能只可自动调整 Worker 数量。
- 向浏览器响应头暴露 `X-Weline-Request-ID`，或在本功能的持久历史中保留原始请求行、request ID、query/body。
- 面向店面访客的遥测/配置入口、无 WLS 权限的后台读写，或新建并行的容量扩缩容策略。
- 直接中断尚未完成的响应；排水须遵守 `REQ-SERVER-0019` 的既有安全约束。
- 替代或重写既有 `REQ-SERVER-0016` 硬内存压力路径；交互边界作为 P1/P2 设计风险记录。

## 3. 角色

- **有权 WLS 运维操作员**：在后台面板查看运行及聚合历史、修改内存策略和历史保留设置。
- **无本功能权限的用户**：打开、读取或尝试修改内存面板时，均不得取得受保护数据或改变配置。
- **Nginx 与 WLS Worker/Fiber/FPC**：系统数据源；按已确认的内部 request ID 契约提供可去重的观测事件，不是面板登录角色。

## 4. 已确认澄清（2026-11-11）

| 主题 | 已确认决定 |
|---|---|
| Worker 内存 | 每 Worker `memory_limit` 保持 256MiB；只允许自动扩缩 Worker 数量，不自动提高单 Worker 上限。headroom 的百分比基数为 Worker `memory_limit`。 |
| 固定阈值 | admission/drain/restart headroom = 50/45/10MiB。headroom=47MiB 时：**拒绝且不立即 drain**；50MiB 与 45MiB 之间的 5MiB 分档间隔有意保留。 |
| 百分比阈值 | admission/drain/restart = 30%/15%/10% × Worker `memory_limit`。 |
| SmartTuning | 开启时自动应用调出的阈值；关闭时保留人工设定值。自动调参不得改变每 Worker 256MiB 上限。 |
| 冷请求模型 | 使用最近窗口内各 Worker 最坏的 cold p95 作保守模型；预算/并发估算不得用平均 Worker 掩盖该最坏值。 |
| 遥测与 FPC | 按 Fiber/FPC 请求记录 `real` / `used` memory delta、耗时和 FPC 类别；类别为 `cold`、`fpc_hit`、`static`、`api`。快照间隔 5 秒。 |
| 路由 | 去除 query/body；数字及 UUID 路径段归一化；TOP-N 按 p95 `real` delta 排名，并显示平均值。 |
| 请求关联 | Nginx `$request_id` 经内部 upstream header `X-Weline-Request-ID` 传给 WLS；Nginx 写结构化记录，WLS Fiber/FPC 事件沿用同一 ID；先关联/去重，再聚合；不加浏览器响应头。 |
| 历史 | 只保留聚合历史，不留原始请求记录或 request ID；默认 7 天，授权操作员可配置保留天数或永久保留。 |
| 自动扩缩容 | SmartTuning 开启时自动控制 Worker 扩容与缩容；复用既有 `CapacityAdvisor` / `CapacityScaleApplier` 能力及其现有 Worker/host 扩缩边界；每 Worker `memory_limit` 保持 256MiB，扩缩仅改变 Worker 数量。 |

以上为用户已确认的需求，不是待回答问题。SmartTuning 算法边界、遥测窗口等实现层细节另列为 P1/P2 开放设计风险，不改变上述行为。

## 5. 用户故事与 EARS 验收

### US-1：观察并关联 Nginx、FPC 与 Worker 请求

作为 WLS 运维操作员，我希望从每个逻辑请求得到可去重的 Fiber/FPC 与 Nginx 聚合数据，以便定位请求路径、内存与耗时，同时不保留原始请求记录或 ID。

- **EARS-REQID-01 / AC-REQID-01** — WHEN 一个逻辑请求产生 Nginx 及 WLS Fiber/FPC 观测事件时，系统 SHALL 使用 Nginx `$request_id` 经内部 upstream header `X-Weline-Request-ID` 关联各事件、在聚合前去重，并 SHALL NOT 将该值加入浏览器响应头。
- **EARS-REQID-02 / AC-RETENTION-01** — WHEN 请求事件完成关联与聚合后，系统 SHALL 只保留聚合历史，不保留原始请求行、请求正文、query 或 request ID。
- **EARS-OBS-01 / AC-OBS-01** — WHEN Fiber/FPC 事件进入统计时，系统 SHALL 记录可观察的 `real` / `used` memory delta、耗时、Worker 身份及 FPC 类别（`cold`、`fpc_hit`、`static`、`api`）。
- **EARS-SNAPSHOT-01 / AC-SNAPSHOT-01** — WHILE Worker 遥测运行时，系统 SHALL 每 5 秒发布一次带采集时间和 Worker 身份的快照。
- **EARS-ROUTE-01 / AC-ROUTE-01** — WHEN 面板展示路由 TOP-N 时，系统 SHALL 移除 query/body、归一化数字及 UUID 路径段，按 p95 `real` delta 排名并同时展示平均值。

**隐形需求摘要：** 相关事件只按内部 ID 关联，ID 不进入浏览器响应或长期历史；每个逻辑请求在多事件源存在时只计一次。所有数值与快照须有单位、时间和 Worker 范围；路由汇总不暴露 query/body。

### US-2：按三档策略管理 Worker 内存压力

作为 WLS 运维操作员，我希望准入、排水和重启遵循已确认阈值，以便在每 Worker 256MiB 限制内保护服务且不截断进行中的响应。

- **EARS-MEM-01 / AC-MEM-01** — WHEN 固定模式生效时，系统 SHALL 使用 admission/drain/restart headroom 50/45/10MiB；WHEN 百分比模式生效时，系统 SHALL 使用 Worker `memory_limit` 的 30%/15%/10%。
- **EARS-MEM-02 / AC-MEM-47** — IF 固定模式下 headroom 为 47MiB，THEN 系统 SHALL **拒绝且不立即 drain**；50MiB 与 45MiB 之间的 5MiB 分档间隔 SHALL 保持有意存在。
- **EARS-MEM-03 / AC-DRAIN-01** — IF 策略进入 drain/restart 阶段，THEN 系统 SHALL 停止接纳新工作，并在进行中的响应按既有安全边界完成/排空后才允许 Worker 退出或重建；面板 SHALL 显示当前阶段。

**隐形需求摘要：** 固定值与百分比值均以 headroom 阶段表达；不提高单 Worker 上限。47MiB 的行为是明确合同，不得将其解释为立即 drain；重启不得抢断未完成响应。

### US-3：自动应用 SmartTuning 并扩缩 Worker 数量

作为 WLS 运维操作员，我希望 SmartTuning 开启时自动应用调出的阈值，并自动按既有容量策略扩容或缩容 Worker，以便在限制单 Worker 内存的同时随负载调整容量。

- **EARS-SMART-01 / AC-SMART-01** — IF SmartTuning 已开启，THEN 系统 SHALL 自动应用计算出的阈值，不要求操作员另行点击确认或保存。
- **EARS-SMART-02 / AC-SMART-01** — IF SmartTuning 已关闭，THEN 系统 SHALL 保留并继续使用人工配置值，不得以自动结果覆盖它们。
- **EARS-BUDGET-01 / AC-BUDGET-01** — WHEN 系统生成保守 cold 请求预算/并发估算时，THEN 系统 SHALL 使用最近窗口中各 Worker 最坏的 cold p95，并展示该统计窗口、Worker `memory_limit` 基数和估算输入。
- **EARS-SCALE-01 / AC-SCALE-01** — IF SmartTuning 已开启，WHEN 既有 WLS 容量策略触发扩容或缩容条件时，THEN 系统 SHALL 使用既有 `CapacityAdvisor` / `CapacityScaleApplier` 能力自动执行对应的 Worker 数量增加或减少，并 SHALL 遵守现有 Worker/host 扩缩边界；扩缩 SHALL 只改变 Worker 数量，且每 Worker `memory_limit` SHALL 保持 256MiB。

**隐形需求摘要：** SmartTuning 开启时既自动应用阈值，也控制 Worker 扩容和缩容；关闭时人工阈值不能被后台采样悄然改写。自动扩缩复用既有容量能力，只在现有 Worker/host 边界内改变 Worker 数量，不提高单 Worker 上限。公式边界、样本不足和扩缩容冷却策略属于 P1/P2 设计风险，不在本文编造。

### US-4：在后台 WLS 面板管理运行数据和配置

作为有权的 WLS 运维操作员，我希望在后台面板读取运行数据、修改策略和保留期，以便在一个受控入口管理功能。

- **EARS-UI-01 / AC-UI-01** — WHEN 有权操作员打开后台 WLS 内存面板时，系统 SHALL 展示最新快照、聚合历史、内存策略与 SmartTuning 状态。
- **EARS-UI-02 / AC-UI-01** — WHEN 有权操作员保存有效的策略或保留配置时，系统 SHALL 保存并显示实际生效/保留状态；重新打开面板时 SHALL 显示已保存值。
- **EARS-ACL-01 / AC-ACL-01** — IF 用户没有本功能后台权限，THEN 系统 SHALL 拒绝读取和修改操作，不返回受保护的数据，也不改变任何配置。

**隐形需求摘要：** 运行数据与所有配置只经后台 WLS 面板访问；拒绝必须可观察；建议、已保存和已生效状态不得混淆。

### US-5：管理聚合历史保留期限

作为有权的 WLS 运维操作员，我希望控制聚合历史的保留时长，以便按需要保留运行趋势而不保存原始请求数据。

- **EARS-HISTORY-01 / AC-RETENTION-01** — WHEN 新安装或未覆盖保留配置时，系统 SHALL 默认保留聚合历史 7 天。
- **EARS-HISTORY-02 / AC-RETENTION-01** — WHEN 有权操作员设置保留期限时，系统 SHALL 支持配置保留天数或永久保留，并仅对聚合历史应用该策略。
- **EARS-HISTORY-03 / AC-RETENTION-01** — WHILE 聚合历史被保留时，系统 SHALL NOT 将原始请求行或 request ID 作为可回查的历史数据保存。

**隐形需求摘要：** 永久保留仅适用于去重后的聚合；过期清理不得误删仍在保留期内的聚合数据。具体存储、清理调度和查询方式留给架构阶段。

## 6. 验收 ID 与 UC 映射

| Acceptance ID | 验收意图 | 覆盖 UC |
|---|---|---|
| AC-REQID-01 | Nginx 内部 request ID 关联 Nginx/WLS Fiber/FPC 事件、聚合前去重，且不出现在浏览器响应头 | UC-1 |
| AC-OBS-01 | Fiber/FPC 内存 delta、耗时、Worker 身份与类别可观察 | UC-1 |
| AC-SNAPSHOT-01 | 每 5 秒发布带时间和 Worker 身份的快照 | UC-1 |
| AC-ROUTE-01 | query/body 去除、数字/UUID 路径段归一化、按 p95 `real` delta 排名并显示平均值 | UC-1 |
| AC-RETENTION-01 | 只保留聚合；默认 7 天，支持自定义天数或永久，不保留原始请求记录/ID | UC-1、UC-5 |
| AC-MEM-01 | 固定 50/45/10MiB 与基于 Worker `memory_limit` 的 30%/15%/10% 阈值 | UC-3、UC-4 |
| AC-MEM-47 | headroom 47MiB 时“拒绝且不立即 drain”，保留 5MiB 分档间隔 | UC-3 |
| AC-DRAIN-01 | drain/restart 遵守在途响应排空约束，阶段可观察 | UC-4 |
| AC-SMART-01 | SmartTuning 开启自动应用；关闭保留人工值 | UC-4 |
| AC-BUDGET-01 | 使用最近窗口各 Worker 最坏 cold p95，展示统计窗口、memory_limit 基数及估算输入 | UC-4 |
| AC-SCALE-01 | SmartTuning 开启且既有 WLS 扩容/缩容条件触发时，自动按对应方向增加/减少 Worker 数，复用 `CapacityAdvisor` / `CapacityScaleApplier` 并遵守现有 Worker/host 扩缩边界；每 Worker `memory_limit` 保持 256MiB | UC-4 |
| AC-UI-01 | 后台 WLS 面板读取运行数据、配置并显示实际保留/生效状态 | UC-1、UC-4、UC-5 |
| AC-ACL-01 | 无本功能权限用户的读写均拒绝，配置不变 | UC-2 |

以上是可供计划 `acceptance[].covers_use_cases` 对齐的验收定义，不是执行结果；本需求分析席未运行任何验收。

## 7. 用例规格

以下 URL/数据名是测试运行参数，须由 P1 测试环境映射到真实隔离实例；本文不猜线上地址或账号。Web 步骤由真实 Browser/WB-OP 执行，并由正式 e2e 断言覆盖 `acceptance[].covers_use_cases`。下列用例已定义但未执行，不代表验收通过。

### UC-1：Nginx–FPC–Worker request ID 端到端关联（主成功）

- **角色：** 测试访客（经 Nginx 发起请求）、有权 WLS 运维操作员（查看聚合结果）。
- **前置：** 隔离的真实 WLS/Nginx 测试实例；`{{COLD_FPC_ROUTE_URL}}`、`{{STATIC_ROUTE_URL}}`、`{{API_ROUTE_URL}}`、`{{NUMERIC_ROUTE_URL}}`、`{{UUID_ROUTE_URL}}`、`{{WLS_MEMORY_PANEL_URL}}` 均由测试环境提供；夹具能分别产生 cold、Nginx FPC hit、static、api 事件并给出预期逻辑请求数。
- **主成功步骤（Browser/WB-OP + formal e2e）：**
  1. 在 Browser/WB-OP 打开 `{{COLD_FPC_ROUTE_URL}}`，产生一次 cold Worker/Fiber 请求；再次打开同一 URL，按夹具产生一次 Nginx FPC hit。
  2. 依次打开 `{{STATIC_ROUTE_URL}}` 与 `{{API_ROUTE_URL}}`；打开 `{{NUMERIC_ROUTE_URL}}` 和 `{{UUID_ROUTE_URL}}`，其测试数据使用数字/UUID 路径段及无敏感内容的 query 参数。
  3. 在 Browser 的网络/响应头观察中确认页面响应不包含 `X-Weline-Request-ID`。
  4. 打开 `{{WLS_MEMORY_PANEL_URL}}`，以有权操作员身份登录；等待不超过下一个 5 秒快照周期，查看对应 Worker 与路由汇总。
  5. 对照夹具核对每个逻辑请求仅计一次、FPC 类别与 `real`/`used` delta/耗时可见、路由段已归一化、TOP-N 以 p95 `real` delta 排序且显示平均值；确认 query、请求正文及原始 request ID 不在可回查聚合历史中。
  6. 同一次 e2e run 对 Nginx 与 WLS Fiber/FPC 事件做临时关联断言，确认 request ID 一致且重复事件先去重；测试报告只保留断言结果，不输出原始 ID。
- **备选/异常：** 若请求关联重复计数、FPC 分类不符、超过一个快照周期无数据，或响应头出现 `X-Weline-Request-ID`，本 UC 判失败，不以页面 HTTP 200 替代上述断言。
- **期望结果：** 真实浏览器流量经 Nginx 与 Worker/FPC 形成正确的一次性聚合；面板显示用户确认的路由和统计口径，且 response header 与持久聚合不泄漏 request ID/raw 请求数据。
- **映射 acceptance：** `AC-REQID-01`、`AC-OBS-01`、`AC-SNAPSHOT-01`、`AC-ROUTE-01`、`AC-RETENTION-01`。
- **状态：** 需求已定义，未执行；真实 URL/夹具由 P1 测试环境提供。

### UC-2：无权限用户访问后台内存功能（权限失败）

- **角色：** 无本功能后台权限的 WLS 用户。
- **前置：** 隔离实例中已有遥测、聚合历史和配置；`{{WLS_MEMORY_PANEL_URL}}` 与无本功能权限的测试账号由本地测试环境提供。
- **步骤（Browser/WB-OP）：**
  1. 登出有权账号，在 Browser/WB-OP 打开 `{{WLS_MEMORY_PANEL_URL}}`，使用无本功能权限的账号登录。
  2. 从后台可见导航进入该面板；若入口隐藏，则直接在地址栏打开同一面板 URL。
  3. 尝试读取遥测、历史、阈值及 SmartTuning/保留设置；若出现配置表单，尝试提交一项非生产测试变更。
  4. 登出无权限账号，再以有权操作员打开相同面板，核对配置、SmartTuning 状态和保留期限均未改变。
- **备选/异常：** 无权限账号若能看到受保护数值或保存操作得到成功反馈，即判失败；不能以“入口未显示”作为服务端拒绝的唯一证据。
- **期望结果：** 后台读取和写入均被拒绝，无遥测/聚合历史泄漏，配置保持原值。
- **映射 acceptance：** `AC-ACL-01`。
- **状态：** 需求已定义，未执行。

### UC-3：headroom=47MiB 的固定模式决策

- **角色：** WLS 运维操作员；测试流量由 Browser/WB-OP 发起。
- **前置：** 隔离 Worker 已设固定阈值 admission/drain/restart=50/45/10MiB；测试夹具能将可观测 headroom 精确置于 47MiB；`{{MEMORY_PRESSURE_TEST_URL}}` 与 `{{WLS_MEMORY_PANEL_URL}}` 已提供。
- **步骤（Browser/WB-OP）：**
  1. 打开 `{{MEMORY_PRESSURE_TEST_URL}}` 发起新请求，同时在 `{{WLS_MEMORY_PANEL_URL}}` 查看该 Worker 的 headroom、准入和阶段状态。
  2. 确认 headroom=47MiB 时请求结果及 Worker 当前阶段。
- **备选/异常：** 夹具无法证明 headroom 精确为 47MiB 时，场景无效、不得判通过；若该值下触发立即 drain 或新请求被准入，判失败。
- **期望结果：** headroom=47MiB 时：**拒绝且不立即 drain**；保留 admission=50MiB 与 drain=45MiB 之间有意设置的 5MiB 分档间隔。
- **映射 acceptance：** `AC-MEM-01`、`AC-MEM-47`。
- **状态：** 需求已定义，未执行；实现前应由 P1 冻结夹具与可观察证据。

### UC-4：SmartTuning 自动应用与 Worker 自动扩缩容

- **角色：** 有权 WLS 运维操作员；测试流量由 Browser/WB-OP 发起。
- **前置：** 隔离实例提供 `{{WLS_MEMORY_PANEL_URL}}`、`{{COLD_SAMPLE_URL}}`、`{{SCALE_OUT_URL}}`、`{{SCALE_IN_URL}}`；Worker/host 上下限已配置；可设置人工阈值及 SmartTuning 开关；P1 冻结的 tuning 公式与 `{{EXPECTED_TUNED_THRESHOLDS}}` 可由样本复算。
- **步骤（Browser/WB-OP）：**
  1. 登录后台面板，关闭 SmartTuning，设置并记录一组人工阈值。
  2. 打开 `{{COLD_SAMPLE_URL}}` 产生覆盖最近窗口的 cold 请求样本；面板显示各 Worker 的 cold p95 与用于保守决策的最坏值。
  3. 开启 SmartTuning；等待一个计算/发布周期，不点击额外确认或保存；刷新面板并核对自动应用值等于 `{{EXPECTED_TUNED_THRESHOLDS}}`。
  4. 打开 `{{SCALE_OUT_URL}}` 触发已配置的 scale-out 条件；观察 Worker 数量增加且不超过既有 Worker/host 上限，逐 Worker `memory_limit` 仍为 256MiB。
  5. 让一个测试请求保持进行中，再打开 `{{SCALE_IN_URL}}` 触发 scale-in；观察在途请求完成后才减少 Worker 数量。
  6. 关闭 SmartTuning，重新设置一组人工阈值，再产生新的 cold 样本；刷新面板并确认人工值没有被自动结果覆盖。
- **备选/异常：** 样本不足或负载夹具未跨过既有扩缩条件时，测试夹具无效、不得判通过；超过上限、修改单 Worker memory limit、SmartTuning 开启后仍需人工确认、或关闭后人工值被覆盖，均判失败。
- **期望结果：** SmartTuning 开启即自动应用，关闭即保留人工值；估算基于最近窗口各 Worker 最坏 cold p95；扩缩仅改变 Worker 数量、遵守既有上下限，且 scale-in 不截断在途请求。
- **映射 acceptance：** `AC-MEM-01`、`AC-DRAIN-01`、`AC-SMART-01`、`AC-BUDGET-01`、`AC-SCALE-01`、`AC-UI-01`。
- **状态：** 需求已定义，未执行；确切公式、负载夹具与目标计数由 P1 对齐冻结。

### UC-5：查看并修改聚合历史保留期限

- **角色：** 有权 WLS 运维操作员。
- **前置：** 隔离实例和 `{{WLS_MEMORY_PANEL_URL}}` 可用；面板初始未设置用户覆盖值；测试历史包含处于保留期内及超过测试保留期的聚合样本。
- **步骤（Browser/WB-OP）：**
  1. 登录后台面板并打开聚合历史设置，确认默认值为 7 天。
  2. 将期限改为 `{{TEST_RETENTION_DAYS}}` 天并保存，刷新页面确认配置仍显示该天数；在测试历史中核对期限内聚合仍可查、超期聚合按已冻结清理规则不可查。
  3. 将期限改为“永久”，保存并刷新；再次查看测试历史，确认聚合历史保留。
  4. 检查可回查历史只含聚合，不含原始请求记录或 request ID。
- **备选/异常：** 保存失败或刷新后恢复旧值时判失败；测试历史未能区分期限内/超期样本时该轮无效，不得宣称保留策略已验收。
- **期望结果：** 默认 7 天；有权操作员可设置天数或永久；策略只作用于聚合历史，原始请求行与 ID 不作为历史保留。
- **映射 acceptance：** `AC-RETENTION-01`、`AC-UI-01`。
- **状态：** 需求已定义，未执行；具体清理周期与边界语义属于 P1/P2 设计风险。

## 8. P1/P2 开放设计风险（不是待用户回答的问题）

以下决策留给领域/架构/测试席在 P1 对齐冻结或 P2 架构阶段处理；本需求分析席不替持久化、IPC、Nginx 接入或扩缩容算法选择方案，也不继续追问已确认的产品决定。

1. **P1 — SmartTuning 算法边界：** 用户已确认开关的自动应用/人工值语义及 cold p95 统计范围；历史候选公式 `admission=max(50MiB,cold_p95×1.5)`、`drain=admission−5MiB`、`restart=max(10MiB,drain/3)` 尚未作为用户承诺。对样本不足、异常值、阈值上下界、热更新/现有 IPC 的行为需由 P1/P2 冻结。
2. **P1 — 预算/并发精确算式：** 输入口径已确认（最近窗口各 Worker 最坏 cold p95、Worker `memory_limit` 基数），但 baseline、并发数与 headroom 的组合算式尚无批准结果；不得在需求规格中臆造公式。
3. **P1 — 样本窗口与 Worker 生命周期：** 最近窗口长度、每 Worker ring 容量（2,048 条只是旧候选）、Worker recycle 后样本清零/保留、5 秒快照跨 Worker 的身份与历史范围待定。
4. **P1 — Nginx/WLS 关联契约边缘：** 在已确认的 `$request_id` / `X-Weline-Request-ID` 契约下，确定关联窗口及缺失、重复、迟到事件的处理；覆盖 HTTP/SSL/HTTP/3、Nginx FPC hit（Fiber 创建前）、static 与 bypass 分类。
5. **P1 — 容量扩缩策略：** 复用 `CapacityAdvisor` / `CapacityScaleApplier` 与现有 worker/host 上限是固定约束；目标数量计算、冷却窗口及和现有配置的交互由容量/架构席确认，不引入第二套 scaler。
6. **P2 — 聚合历史实现：** 存储归属、聚合 schema、写入频率、TTL/永久模式清理和查询路径待架构确定；必须满足仅保留聚合、不保留原始请求记录/ID。
7. **P2 — Nginx 日志落地：** 结构化记录的格式、路径、轮转及 `access_log off` location 覆盖待 Nginx 席确认；需满足内部关联、不改响应头且不把日志 I/O 放入 WLS 请求热路径。
8. **P2 — 资源开销与验收：** 在 256MiB Worker 与真实 Nginx/WLS 环境测量采集、去重、聚合的 CPU/内存/磁盘开销上限，并确定真实机证据格式。

## 9. 就绪检查

- [x] YAML `status: ready-for-plan`、`work_kind: feature`、slug 与所属模块已标明；同 slug SESSION 存在且由项目经理维护，本席未修改。
- [x] 目标、范围、非目标、角色和用户已确认澄清已写入；没有未答复的产品需求问题。
- [x] 5 个用户故事均有至少 2 条可观察 EARS 和隐形需求摘要。
- [x] UC-1 主成功、UC-2 权限失败、UC-3 47MiB、UC-4 SmartTuning+扩缩、UC-5 保留期均有角色、前置、步骤、备选/异常、期望结果和 acceptance 映射。
- [x] Web UCs 可由 Browser/WB-OP 执行；真实 URL/账号/夹具使用明确参数名，P1 必须由测试环境填入，不能猜线上值。
- [x] 每个 acceptance ID 列出覆盖的 UC，可供计划写入 `acceptance[].covers_use_cases`；feature 已明确 Browser/formal e2e 验收意图。
- [x] 所有尚未定的事项均作为 P1/P2 设计风险，不是要求用户重复回答；本文没有选择持久化、IPC 或 Nginx 日志接入方案。
- [x] 本文只定义可观察行为和用户指定的固定约束；未编写 PHP/JS/CSS/测试实现或补丁步骤。

**结论：** 需求澄清与用例门禁已满足，状态为 `ready-for-plan`，可进入 PM 计划及 P1 对齐冻结；这不表示架构已冻结、代码已实施或任何 UC 已验收通过。全部 UC 仍须由后续真实 Browser/WB-OP 与 formal e2e 执行并留证。
