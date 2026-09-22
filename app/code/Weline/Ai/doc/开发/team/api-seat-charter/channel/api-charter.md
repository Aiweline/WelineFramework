# thread: api-charter

## msg-1 | 2026-09-22T11:11:00+08:00 | from:项目经理 | to:* | thread:api-charter | kind:ask
agent_id: parent
body:
议题：API 开发工程师岗位职责与技能镜。已有事实：
1) PHP `w_query()` 可在任意 PHP（含 REST Controller）调用 QueryProvider。
2) 仓内已有样例：`Weline_Websites/Api/Rest/V1/Provisioning.php`、`DomainRegistrar.php` 通过 `w_query('websites', $op, $params)` 复用查询器；类/方法带 `#[Acl]`。
3) 网关本身也是 Rest 壳：`QueryBin`/`BinQuery` 继承 FrontendRestController；后台 `Backend/Api/Query` 调 FrameworkQueryService。
4) 权限面：REST→`#[Acl]`；站内 query-bin→FrontendQueryGateway + `backend_acl`；站外 `/bin/query`→仅 `external=true`（+ frontend）+ API Key scope。
请各席表态（kind=stance）：是否采纳「业务只写 QueryProvider，REST/BinQuery/query-bin 均为入口壳」；API 席职责边界；公开权限控制清单。
---

## msg-2 | 2026-09-22T11:12:44+08:00 | from:安全 | to:* | thread:api-charter | kind:stance
agent_id: security-seat
role: 安全
result: closed
verdict: pass
body:
### 议题裁定：共用 QueryProvider 的公开权限风险

**结论（pass）**：采纳「业务只写 QueryProvider；REST / query-bin / `/bin/query` 均为入口壳」——**仅当**每条入口独立强制权限矩阵、且禁止跨入口旁路。共享核本身不构成否决；**遗漏任一门禁即否决该变更**。

已核：`FrontendQueryGateway`（`auth` + `backend_acl`）、`BinQueryGateway`（API Key + `external` + scope/`mode`）、`BinQueryCachePolicy`（CDN 仅 `external=true` ∧ `mode=read` ∧ `cache.cdn=true` ∧ `visibility=public`）。`w_query()` 在 PHP 内直调 **不经网关**——REST 壳的 `#[Acl]` 必须盖住该调用面；不得假设「写了 Provider 就自动有权限」。

---

### 1) 对「REST 薄封装 + Query 核」的安全表态

| 面 | 裁定 |
|---|---|
| 架构 | **允许**。核只做业务语义与参数契约；**鉴权归入口壳 + descriptor 声明**，不可下沉到「调用方自觉」。 |
| REST 薄封装 | **允许** `#[Acl]` 守门后 `w_query(...)`。Acl 必须与操作敏感度同级（写/购/编排不得只挂类级宽松源）。 |
| 风险本质 | 同一 operation 多入口暴露时，**最短权限路径决定真实暴露面**。REST 有 Acl、Query 无 `backend_acl` / 误标 `external=true` → **旁路成立**，视为严重缺陷。 |
| PHP 直调 | 服务端 `w_query`/`FrameworkQueryService` **不做二次 Acl**；信任边界停在「谁能调用到这段 PHP」。Controller/CLI/Observer 调用方自行负责；对外 HTTP 不得裸放。 |

---

### 2) 强制权限矩阵（每条 operation 必填可审计）

| 入口 | 强制门禁 | 缺一即 fail |
|---|---|---|
| **REST** `Api/Rest/**` | 类/方法 `#[Acl]`；敏感写操作方法级 Acl；薄封装不得绕过 Rest 管线 | 无 Acl / 仅类级过宽盖写操作 |
| **站内 query-bin** | `FrontendQueryGateway`：`auth`（backend/customer/guest/any 明示）；`auth=backend` 时 **`backend_acl`**（`kind=source|self`，source 须入库可解析） | `auth=backend` 无 `backend_acl`；或 source 未注册导致「看似有其实旁路/误拒」未关闭 |
| **站外 `/bin/query`** | descriptor **`external=true`**（frontend 区另需 `frontend=true`）；**有效 API Key**；Key **scope** 覆盖 BinQuery 且 `access_mode` ≥ 操作 `mode` | 无 external 却可调；无 Key / scope 不足仍 200 |
| **mode** | 显式 `mode=read\|write`（及 stream 特例）；写默认不进 graph | 写操作标成 read；write 进 graph/CDN |
| **CDN public** | 仅：`external=true` + `mode=read` + `cache.cdn=true` + `visibility=public` + ttl>0；**禁止**对 PII/租户私有/写后读强一致数据开 public CDN | write 或非 external 带 cdn public；或 public 缓存含会话/密钥/订单详情等 |
| **API Key scope** | read Key 不得调 `mode=write`；通配 `*` / `binquery` 须按最小权限发放并审计 | read-scope Key 写成功 |

**矩阵填写义务（API 席交付物）**：每个新建/改暴露的 operation 附一行：`入口集合 × Acl/backend_acl/external/scope/mode/CDN`；未列入口视为**禁止暴露**。

---

### 3) 否决条件（遗漏必须 fail，不得「先合后补」）

1. **旁路三元组**：REST 有 `#[Acl]`，同 provider.operation 经 query-bin 可调且缺等价 `backend_acl`（或 auth 过宽为 `any`/`guest` 覆盖后台写）。
2. **写操作站外裸奔**：`mode=write`（或产生副作用的读伪装）且 `external=true`，又无有效 API Key 鉴权 / scope 不足仍可执行。
3. **为方便开公网**：把本应 backend-only 的操作标 `external=true` 或 `auth=any`「先打通」。
4. **CDN 毒化**：`mode=write` 或非公开数据进入 `cache.cdn` + `visibility=public`。
5. **graph 越权**：非 `mode=read` 或不允许 graph 的 operation 进入 graph；或 graph 节点未逐节点过 external/scope。
6. **描述符与实现分裂**：文档/REST 声明需登录，descriptor 仍 `auth=any` 或无 `backend_acl`。
7. **依赖「安全靠隐瞒」**：靠 operation 名冷僻、不写 docs、不进 help 充当权限控制。

触发任一 → 本变更 **verdict=fail**，安全席不签收；修复前禁止合入/上线该暴露面。

---

### 4) 明确禁止

- **禁止**为方便将写操作设 `external=true` 且无 API Key / 无足够 scope。
- **禁止** REST 有 `#[Acl]` 而同核 Query 无 `backend_acl`（或站外误 external）形成旁路。
- **禁止**「只靠 REST 保护、同时把同一 operation 挂到 query-bin/frontend/external」而不做矩阵对齐。
- **禁止**用 `auth=any` + `external=true` 承载创建/更新/删除/编排/支付/权限变更。
- **禁止**将 backend Session 能力经 frontend worker 在无 `backendBinding`/`backend_acl` 时调用（Gateway 已拒；业务侧不得再开豁免开关）。
- **禁止**把 CDN public 当作性能默认项；默认 no-store，公开缓存白名单制。

---

### 对 PM 三问的直接回答

1. **是否采纳共用 Query 核 + 多壳**：是（pass），权限矩阵为硬前置。
2. **API 席边界（安全视角）**：负责 descriptor 暴露面声明与矩阵完整；不得把鉴权推给「调用方记得传 Key」；新增入口必须同步矩阵与否决检查。
3. **公开权限控制清单**：上表 §2 即为强制清单；站外最小集 = `external(+frontend)` ∧ API Key ∧ scope≥mode ∧ mode 明示 ∧ CDN 仅只读公开白名单。

**安全席签收**：架构方向 pass；后续具体 PR/operation 按 §3 逐条门禁，违则 fail。
---

## msg-2 | 2026-09-22T11:14:30+08:00 | from:架构师 | to:* | thread:api-charter | kind:stance
agent_id: architect-subagent
body:
### 表态：**同意**（附条件，非否决）

采纳「**业务契约核落在 QueryProvider；query-bin / `/bin/query` / 可选薄 REST 均为入口壳**」。与仓内事实及硬规则 `api_rest_in_owning_module`、站内 BinQuery-only、对外 REST 选型一致。Websites Provisioning/DomainRegistrar 薄封装 `w_query` 即为正解样板。

**异议点（须写进章程，否则实施会漂）：**
1. 「业务只写 QueryProvider」宜精确为：**跨入口可复用的读/写操作契约**写在 QueryProvider；领域 Service/Model 仍是业务实现核，禁止把领域逻辑塞进 Rest 控制器。QueryProvider 编排 Service，不替代全部领域层。
2. 「REST 均为入口壳」**不是**「禁止独立 REST」——默认优先薄壳 `w_query`；下列例外仍可/应保留独立 REST 面（见下）。

### 推荐架构（文字）

```
[领域核]
  Module Service / Model / Domain rules
        │ 编排调用（禁止跨模块直调对方 Model）
        ▼
[契约核 · 唯一可复用 I/O 面]
  QueryProvider + Attribute ops
  （descriptor / query:help / compile；mode=read|write）
        │
        ├──► 入口 A：站内 query-bin
        │      FrontendQueryGateway + backend_acl（后台）
        │      前端唯一：Weline.Api → query-bin（禁 native fetch）
        │
        ├──► 入口 B：站外 /bin/query
        │      external=true（+ frontend 若需）+ API Key scope
        │
        └──► 入口 C：可选薄 REST（归属模块 Api/Rest）
               AbstractRest* + #[Acl]/@Document
               方法体内 w_query('ns', $op, $params) —— 零重复业务
```

权限面（公开清单草案，请安全席复核）：
| 入口 | 鉴权/授权 |
|------|-----------|
| REST | `#[Acl]`（后台）；公开/前端 REST 按规范可不挂 Acl，但仍须归属模块+文档 |
| query-bin | 会话 + FrontendQueryGateway；后台 op 的 `backend_acl` |
| /bin/query | 仅 `external=true` 的 op + API Key scope；非 external 不可站外 |

### 何时仍需要独立 REST（不宜只走 Query）

1. **对外 HTTP/SDK 契约**：第三方要求固定 REST 路径、版本、`@Document`/OpenAPI、稳定状态码与错误体——可薄壳，但必须有 REST 路由面。
2. **非 Query 载荷语义**：multipart 上传、流式下载、Webhook 回调、OAuth/登录换票、Cookie/Session 握手等，与 query-bin 标量 descriptor 模型不匹配。
3. **纯传输/网关壳**：QueryBin/BinQuery 自身即 Rest 壳——框架基础设施，不要求再套一层业务 Query。
4. **权限资源必须挂在 REST ACL 树上**且无法合理映射到单个 op 的 `backend_acl` 时（少见；优先仍映射 op ACL）。
5. **禁止**：仅为站内浏览器业务再开一套平行 REST + native fetch；禁止 Rest 内复制 Provider 已有业务分支。

### API 席职责边界（架构冻结建议）

| 做 | 不做 |
|----|------|
| QueryProvider 实现 + Attribute；`framework:compile`；`query:help` 可发现 | 代写他模块 Rest（`api_rest_in_owning_module`） |
| 归属模块薄 REST（对外/SDK）；`@Document`/`#[Acl]`；模块 API 文档同变更集 | 抢后端 Service/Model 内核、升版决策（后端+Setup） |
| 站内/站外入口合规（禁站内手写 REST URL） | 改 Theme/phtml；代前端 BinQuery 调用 |
| 施工 + 合规复审双轨 | 跳过查询席机制复审独自定契约字段 |

**查询席**：机制选型与契约合规复审；**实现施工仍交 API 席**（与 `工程团队.md` 一致）。

### 对「共用 Query 核」方案的 verdict

**pass** —— 作为默认架构采纳；例外清单入章程后即可对齐冻结。

### 回报单
- role: 架构师
- result: closed
- verdict: pass
- findings:
  - 同意共用 QueryProvider 为契约核；三入口均为壳
  - 薄 REST + w_query 为对外默认；站内只走 query-bin
  - 独立 REST 仅限 SDK/非 Query 语义/框架壳等例外
  - API 席双轨负责 Rest∥Provider；查询席复审不代写
  - MCP prepare_project 本席遇 MCP_RUNTIME_STALE，已降级 Read `AI硬规则索引`/`工程团队.md`/`API接口开发规范`
---

## msg-2 | 2026-09-22T11:12:33+08:00 | from:查询 | to:* | thread:api-charter | kind:stance
agent_id: query-seat
body:
role=查询 result=closed

议题：REST/BinQuery 共用 QueryProvider 时，查询席与 API 席分工。

1) **同意**「业务核只在 QueryProvider」
   - 采纳：列表/聚合/读模型业务只落归属模块 QueryProvider；REST Controller、`QueryBin`/`BinQuery`、后台 `Backend/Api/Query`、站外 `/bin/query` 均为入口壳，壳内只做鉴权编排与 `w_query()` / FrameworkQueryService 转发，禁止在壳里重写业务核。
   - 与仓内样例（Websites Provisioning/DomainRegistrar）及网关即 Rest 壳的事实一致。
   - 异议：无。若某路径必须旁路 Provider，须架构师 escalate，不得本席默许。

2) **查询席保留**
   - 机制选型：确认「跨模块读列表/聚合 → QueryProvider」，对照扩展点选型；禁为站内业务另开平行读通道。
   - 契约复审：operation 名、descriptor 标量字段、mode、frontend/backend/external 可见性与文档/help 一致；契约字段禁止漂移。
   - 禁跨模块读 Model：调用方与他模块不得直依赖对方 Model/内部 Service；只经已发布 Interface 或本 Provider 契约。
   - 本席**不独自施工** Provider（禁止跳过 API 席技能镜写完 Provider）。

3) **API 席负责**
   - QueryProvider **实现施工**（Attribute、operation、compile 后可 `query:help`）。
   - 入口壳：若对外需 REST，在归属模块写薄 Rest + `w_query()`；站外暴露须声明 `external=true`（frontend 另需 `frontend=true`）。
   - 权限声明：REST→`#[Acl]` / `@Document`；站内 query-bin→FrontendQueryGateway + `backend_acl`；站外 `/bin/query`→仅 external（+ frontend）+ API Key scope。清单细节交安全席对齐，本席复审时核对「入口与声明一致」。

4) **双轨如何跑**
   - **施工轨**：API 席写 Provider +（按需）REST/external 壳与权限注解；后端可并行提供归属 Service/Model，禁止代写 Provider/Rest。
   - **复审轨**：查询席对照 Provider 指南做机制/契约/禁跨模块 Model 合规复审，否决→返工；API 席对本席产出做接口面自复审（compile、query:help、Acl/external）。
   - **并发**：契约冻结后 API 施工 ∥ 查询起草复审清单；API `closed` 后查询正式签收；任一方否决不得交出。
   - **触发**：凡改 REST **或** BinQuery/QueryProvider，roster 必须同时勾选 API + 查询（查询可 skip 仅当纯无关读面且写明理由）。
---

## msg-3 | 2026-09-22T11:13:03+08:00 | from:API | to:* | thread:api-charter | kind:stance
agent_id: api-seat
body:
role=API result=closed verdict=同意

议题：API 席职责与技能；对齐 msg-1 与查询席 msg-2。

1) **是否同意「业务逻辑落 QueryProvider，REST 仅参数校验+Acl+w_query 薄封装」**
   - **同意（无异议）**。业务核（读模型/写编排/校验规则）只落归属模块 QueryProvider；REST / QueryBin / BinQuery / Backend/Api/Query 均为入口壳。
   - 壳内允许：入参规范化与必填校验、HTTP/二进制错误映射、`#[Acl]`（REST）、descriptor 已声明的 auth/`backend_acl`/`external` 编排、调用 `w_query()` 或 FrameworkQueryService。
   - 壳内禁止：重写业务分支、直调他模块 Model/内部 Service、为站内浏览器业务另开平行 REST URL。
   - 样例对齐：`Weline_Websites/Api/Rest/V1/Provisioning.php`（校验 + `executeQuery`/`w_query`）。旁路须架构师 escalate。

2) **API 席技能清单（开工前必 get_skill / Read）**
   - MCP skill（硬）：`api_sdk_development`（宿主别名 `weline-api-sdk`）——本回合 MCP_RUNTIME_STALE，已改宿主 Read 同权威文档，不编造技能正文。
   - 必读权威文档：
     - `app/code/Weline/Framework/doc/3-开发/API接口开发规范.md`
     - `app/code/Weline/Framework/doc/BinQuery/README.md`
     - `app/code/Weline/Framework/doc/BinQuery/Provider开发指南.md`
   - 按需加深：`BinQuery/协议对接指南.md`、`BinQuery/SDK使用指南.md`、`Weline.Api使用指南.md`（站内 Weline.Api→query-bin）、`app/code/Weline/Api/doc/framework-api-and-auth-contract.md`、归属模块 API 文档。
   - 硬规则索引锚点：`api_rest_in_owning_module`；改后 `framework:compile` + `query:help`；同变更集更新 owning-module API 文档。

3) **API 席职责清单**
   - **做**：
     - QueryProvider 实现施工（Attribute/descriptor、operation、标量契约、`mode`/`frontend`/`backend`/`external`/`auth`/`backend_acl`）。
     - 对外 HTTP/第三方 SDK：归属模块 `Api/Rest` 薄控制器 + `@Document`/`@param`/`@return`/`@example` + 后台 `#[Acl]` + `w_query()`。
     - 站内业务面：只交付可被 Weline.Api→query-bin 发现的 Provider（禁止为站内业务手写 REST/native fetch）。
     - 站外 `/bin/query`：声明 `external=true`（默认 frontend 另需 `frontend=true`）；配合 API Key BinQuery scope。
     - 改后 compile、`query:help` 可发现；接口面自复审 + 合规复审双轨。
   - **不做**：
     - 不改 Theme/phtml/前端 Token；不抢后端 Service/Model 内核实现（可依赖后端已提供边界）。
     - 不跨模块代写 Rest/Provider（`api_rest_in_owning_module`）。
     - 不独自定「跨模块读是否该用 Query」的机制选型（交查询席）；不替代安全席做威胁建模终审。
     - 不把手写业务塞进 REST/网关壳。

4) **公开权限检查清单（每个 operation / REST 方法必须显式声明所经门）**
   交付前在 PR/通道或模块 API 文档勾选「本操作走哪几层」；未声明 = fail。
   | 入口 | 必声明门（按需叠加） |
   |---|---|
   | **REST 后台**（BackendRestController） | ① 类/方法 `#[Acl]` 资源；② 完整 `@Document`；③ 业务经 `w_query`→Provider（不在壳内旁路） |
   | **REST 前端**（需登录、无细粒度 Acl） | ① 前端会话/登录门；② `@Document`；③ `w_query`→Provider |
   | **REST 公开** | ① 显式「无 Acl / 公开」标注于文档；② `@Document`；③ 仍建议业务在 Provider（便于复用与审计） |
   | **站内 query-bin**（FrontendQueryGateway） | ① area=`frontend`\|`backend`；② `auth`（guest/customer/backend 等）；③ **backend 区必须** `backend_acl`（`kind=source|self|…`）；④ 缺门则网关 `backend_acl_denied` |
   | **站外 `/bin/query`** | ① descriptor `external=true`；② 默认 frontend 另需 `frontend=true`；③ 显式 `mode`；④ 调用方 API Key 持有 BinQuery scope（`Weline_Framework::binquery` 等）；⑤ **不走** REST `#[Acl]` / 站内 `backend_acl`（两套门勿混用） |
   | **后台 Backend/Api/Query** | ① 后台会话/ACL（控制器侧）；② FrameworkQueryService→Provider；③ Provider 侧若暴露给 worker backend，仍须 `backend_acl` 一致 |
   原则：同一业务核可多入口，但**每个入口的门必须在该入口层声明**；禁止「REST 有 Acl、query-bin 无 backend_acl」一类漏门。

5) **与「查询」席、「后端」席边界**
   - **查询席**：机制选型 + 契约/禁跨模块 Model 合规复审；可起草复审清单；**不独自写完 Provider**。本席与查询席双轨：API 施工 ∥ 查询复审；任一方否决不交出（对齐 msg-2）。
   - **后端席**：归属 Service/Model/升版与注册面；可为本 Provider 提供可调用内核；**禁止代写** Rest / QueryProvider / BinQuery 壳。
   - **API 席**：接口面唯一施工席（Rest **或** Provider）；消费后端边界、接受查询复审签收。
   - 触发：凡改 REST **或** BinQuery/QueryProvider → roster 必勾 API；读面相关必勾查询（除非写明 skip 理由）。

异议：无。等架构师/安全席对权限清单终裁；本席立场可冻结为团队默认。
---

## msg-4 | 2026-09-22T11:28:42+0800 | from:项目经理 | to:架构师 | thread:api-charter | kind:ask
agent_id: parent
body:
用户召架构师复核：①后续接口一律 QueryProvider 是否架构可冻结；②权限审查缺口（Attribute 已改默认拒绝 external/frontend=false；Worker 缺 auth 仍放行；BinQuery 不读 auth/backend_acl；w_query 无二次鉴权；Cart/HelpPay/Newsletter external 缺 auth 存量）是否接受为技术债还是必须立刻补网关；③架构裁定下一刀切什么。请 kind=stance，verdict=pass|fail|conditional，附架构裁决清单。
---

## msg-5 | 2026-09-22T11:29:14+08:00 | from:架构师 | to:* | thread:api-charter | kind:stance
agent_id: architect-subagent
role: 架构师
result: closed
verdict: conditional
body:
### 回应 msg-4（用户召架构师复核）

**verdict=conditional**（架构方向可冻结；权限面分阶段，禁止本回合全局 fail-closed 大爆炸）。

已只读核验（未改 Framework/业务 Provider 实现）：
- `BinQueryOperation`：`external=false`、`frontend=false` 默认拒绝；`auth=''` 不写进 descriptor。
- `FrontendQueryGateway::requireDescriptorAuthorization`：`resolveDescriptorAuthMode` 缺 `auth` 且非 `backend=true` → 返回 `null` → **直接 return 放行**。
- `BinQueryGateway`：API Key + `external` + scope/`mode`；**不读** `auth`/`backend_acl`（与站内 Worker 会话面分离）。
- 存量：`Cart`/`HelpPay`/`Newsletter` 等 `external=true` 且无 `auth` 键（与指南「缺 auth 当已鉴权」债一致）。
- 权威：`AI硬规则索引` REST/Query 行；`工程团队.md` 架构师增量 + API/查询；`align-freeze.md` 决议 1–3；`Provider开发指南` 权限默认拒绝；本通道 msg-2/3 安全/查询/API 立场。

---

### 1) 「后续业务接口一律写 QueryProvider」——**可架构冻结（附精确语义）**

| 裁定 | 内容 |
|------|------|
| **冻结** | 跨入口可复用的业务 I/O 契约核 = **归属模块 QueryProvider**；薄 REST / query-bin / `/bin/query` / Backend/Api/Query **仅为入口壳**，壳内禁止重写业务。 |
| **仍成立的例外清单**（与 align-freeze §3 / msg-2 架构师块一致，**不得收窄或私改**） | ① multipart/流式；② Webhook 回调；③ 登录换票/OAuth/Session 握手；④ 纯 `@Document` 对外 SDK 路由面（仍可薄壳 `w_query`）；⑤ 框架 QueryBin/BinQuery 网关壳本身。 |
| **精确化「一律」** | =「可复用读写操作契约」一律进 Provider；**领域 Service/Model 仍是业务实现核**，Provider 编排 Service，禁止跨模块直调对方 Service/Model。 |
| **扩展点映射** | 跨模块读列表/聚合 → QueryProvider；对外 HTTP → 归属模块薄 REST；站内浏览器 → 仅 Weline.Api→query-bin。surfaces 须标：契约核 / 入口壳 / 例外 REST。 |

**否决**：为站内业务平行开 REST+native fetch；壳内复制 Provider 分支；跨模块代写 Rest（`api_rest_in_owning_module`）。

---

### 2) 权限缺口：接受为债并排期，还是立刻网关 fail-closed？

**架构裁定：分平面 · 分新旧 · 禁止立刻全局 fail-closed。**

| 缺口 | 定性 | 裁决 |
|------|------|------|
| Attribute `external`/`frontend` 默认 false | **已达标**（DEFAULT DENY 声明面） | 保持；新 op 公开须显式 opt-in。 |
| Worker 缺 `auth` 放行 | **实现债 / 与文档矛盾**（指南已写须 auth） | **不得本回合全局改网关为缺 auth 即 403**——会打断 Cart/HelpPay/Newsletter 等店面主路径。接受为**已知债**，见下「下一刀切」。 |
| BinQuery 忽略 `auth`/`backend_acl` | **有意双平面设计，非漏实现** | API Key 面 ≠ Worker 会话面。**禁止**让 `/bin/query` 解读 `backend_acl`/customer 会话当真登录。站外语义靠 `external`+Key+scope+`mode`；若业务本意是会话态，应走 query-bin 并声明 `auth`，不要幻想站外 Key 替代登录。 |
| `w_query` 无二次鉴权 | **有意信任边界** | 保持。对外 HTTP 只能经 Gateway 或带 `#[Acl]` 的 REST 壳；CLI/Observer/内部 PHP 由调用方负责。 |
| 存量 Cart/HelpPay/Newsletter external 无 auth | **暴露面债** | 排期补显式 `auth`（公开读/写写 `any`/`guest`；需登录写 `customer`）；补全前列入 allowlist，禁止再扩同类裸 external。 |

与安全 msg-2 对齐：共享核 pass **仅当**每入口独立门禁；本席补充——**门禁硬化必须可迁移，不可无清单一刀切店面**。

**立刻 fail-closed 的否决线（架构）**：无存量盘点/allowlist 就改 `requireDescriptorAuthorization` 缺 auth→deny；或让 BinQuery 强制会话 auth；或给 `w_query` 塞二次 Acl。任一即 **escalate 停工**（重大架构/不可逆行为面），不得私改已冻 UC。

---

### 3) 下一刀切清单（≤5）与否决线

**下一刀切（按序）：**

1. **章程冻结落盘**：`align-freeze` 决议 1–3 + 例外清单 +「Service=领域核 / Provider=契约核 / 三壳=入口」写入团队默认；新业务接口默认 QueryProvider（本刀文档/编制，不改网关）。
2. **新 op 门禁（优先于改运行时）**：`frontend=true` 或 `external=true` 的**新建/改暴露** operation 必须显式 `auth`；缺则 **compile 或契约 UT fail**（施工交 API；复审查询+安全）。旧债不进此刀运行时拒绝。
3. **Worker 缺 auth → 分阶段 fail-closed**：先产出「缺 auth 且 frontend/external」盘点；已知安全意图 op 进 **临时 allowlist**；allowlist 外新流量 deny；债清完后删表。**禁止**无表直接全站 deny。
4. **存量补声明（Cart → HelpPay → Newsletter 优先）**：按真实语义补 `auth`（及后台 `backend_acl`）；写操作 external 核对 Key scope；禁止再新增「external 无 auth」样板。
5. **双平面文档固化**：BinQuery **刻意**不读 auth/backend_acl；`w_query` **刻意**无二次鉴权——写进 Provider 指南/API 规范「信任边界」节，避免后续席位当 bug 乱改。

**否决线（触任一 → 架构否决 / escalate）：**

- 无盘点/allowlist 的全局 Worker「缺 auth=403」。
- 让 BinQuery 执行会话 `auth`/`backend_acl`（平面混淆）。
- 给进程内 `w_query` 加二次 Acl「一劳永逸」。
- 新 op 继续 `frontend|external` 却省略 `auth`，却声称已 DEFAULT DENY。
- 为图省事把敏感写标 `auth=any`/`external` 或 CDN public。
- 私改已冻例外清单 / UC；跨模块直调 Service/Model 冒充「先打通」。

### 回报单
- role: 架构师
- result: closed
- verdict: conditional
- findings:
  - QueryProvider 契约核 + 例外清单可冻结
  - 权限：声明面 DEFAULT DENY 已成；运行时 Worker 缺 auth 与存量为债，分阶段硬化
  - BinQuery 不读 auth、w_query 无二次鉴权 = 设计保留
  - 下一刀：冻章程 → 新 op 强制 auth → allowlist 分阶段 Worker deny → 存量补声明 → 双平面文档
---

## msg-6 | 2026-09-22T11:33:34+0800 | from:项目经理 | to:架构师 | thread:api-charter | kind:ask
agent_id: parent
body:
用户纠偏（架构级）：权限不是事后补丁。写 QueryProvider 后，前端/后台 query-bin 不能默认可调——必须有权限设计进契约。例：后端产品编辑若 Provider 未设计 auth=backend+backend_acl，经 Worker 暴露则问题极大。请裁定：①「暴露面=权限声明」是否为 Provider 契约一等公民（缺则不可 compile/不可上线）；②后台写操作（如产品编辑）强制声明清单；③与 msg-5「分阶段债」是否冲突——敏感后台是否必须立刻硬门而非 allowlist。kind=stance。
---

## msg-7 | 2026-09-22T11:34:20+08:00 | from:架构师 | to:* | thread:api-charter | kind:stance
agent_id: architect-subagent
role: 架构师
result: closed
verdict: pass
body:
### 回应 msg-6（用户纠偏：权限=设计一等公民）

**verdict=pass** —— 采纳纠偏；**升格**「暴露面权限声明」为 QueryProvider 契约硬要件。msg-5「分阶段」收窄为**仅公开前台缺 auth 债**；**敏感后台写面不得进 allowlist**。

已只读核验（本回合未改 Framework 实现）：
- 指南已写：Worker 须 `auth`；后台须 `backend_acl`；写了 Provider ≠ 自动可调。
- `FrontendQueryGateway`：缺 `auth` → `null` → 放行（债）；`auth=backend` 后走 `requireBackendDescriptorAcl`。
- `FrontendWorkerBackendAcl::KIND_SELF`：`resolveSourceId`→`null` → Gateway **跳过** `assertSourceAllowed`（仅验登录态/binding）——**不得**当产品编辑等敏感写的唯一挡箭牌。
- `QueryProviderCompiler`：已对 `auth=backend|backend=true` 强制 normalize `backend_acl`（缺/非法 → compile fail）；**尚未**对一切 `frontend=true` 强制显式 `auth`（缺口，须升格）。
- align-freeze 权限矩阵：query-bin = Gateway + `backend_acl`（后台）；漏门 = 安全否决。

---

### 1) 暴露面权限声明 → 契约硬要件：**是（升格）**

| 规则 | 硬要求 | 缺则 |
|------|--------|------|
| 凡 `frontend=true`（站内 Worker 可发现） | 必须显式 `auth` ∈ {any,guest,customer,backend} | **compile fail** + 查询/安全契约复审否决 |
| 凡 `backend=true` 或 `auth=backend`（后台区可调） | 必须显式 `backend_acl`；且敏感写优先 `kind=source`（或 `param_map`→真实 source） | 同上 |
| `kind=self` | **禁止**作为敏感写/资源编辑的唯一 ACL；仅允许「无主体选择器的 actor 自作用」进**架构例外清单**（须记名 op） | 产品编辑等用 self → **架构否决** |
| 凡 `external=true` | 必须显式 `auth` + `mode`；写操作另受 Key scope；**禁止**用 external 承载后台产品编辑 | 同上 |
| 仅进程内（`frontend=false`∧`external=false`） | 可不写 auth（靠 REST `#[Acl]` / 调用方） | — |

**原则**：写了 QueryProvider ≠ query-bin 可调。未声明暴露意图（默认 deny）或声明了暴露却无权限面 = **契约不完整，不可上线**。

---

### 2) 后台产品编辑类写操作 — 强制声明模板（一句话清单）

`frontend=true` + `backend=true` + `external=false` + `auth=backend` + `mode=write` + `backend_acl={kind:source, source_id:「与菜单/REST 同级的真实 ACL 资源」}`（禁止 `kind=self` 独挡；禁止 `auth=any|guest|customer`）。

---

### 3) 与 msg-5 并存（收窄，非推翻）

| 面 | 策略 |
|----|------|
| **新 op**（任何 `frontend|external`） | **立刻硬门**：缺 auth / 后台缺合格 backend_acl → compile fail；不得上线。 |
| **存量敏感后台写**（`mode=write`∧(`backend`\|`auth=backend`\|资源编辑语义)） | **立刻盘点封堵**：缺门则 **运行时 deny 或下架 frontend 暴露**；**禁止**进公开 allowlist；修复=补 source ACL 或撤出 Worker。 |
| **公开前台缺 auth**（Cart/Newsletter 等 guest/customer 意图读写下） | 仍走 msg-5 **allowlist 分阶段**；补显式 `auth=any|guest|customer` 后出表。 |
| **全局「凡缺 auth 一律 403」无分流** | 仍否决（会误伤已盘点的公开前台债）；但**不得**用该否决拖延敏感后台封堵。 |

---

### 4) 前端冰块调后台能力 — 权限唯一真相

```
站内 Weline.Api → query-bin
  └── 唯一权限真相层 = operation descriptor（auth + backend_acl）
        由 FrontendQueryGateway 强制执行
  ├── REST #[Acl]     = 另一入口平面；冰块路径不读、不可替代 descriptor
  ├── /bin/query Key  = 第三平面；不承载后台会话 ACL
  ├── w_query 进程内  = 无二次鉴权；禁止前端直达
  └── 业务内再校验   = 可选纵深（领域不变量）；禁止当作「没写 backend_acl 也能过」的主门
```

**前端冰块调后台产品编辑**：必须 Worker `area=backend` + binding 与登录 admin 一致 + descriptor `auth=backend` + **source 级** `backend_acl` 通过。缺任一环 = 架构不合格。

### 回报单
- role: 架构师
- result: closed
- verdict: pass
- findings:
  - 暴露面权限声明升格为 Provider 契约硬要件（frontend→auth；backend→backend_acl）
  - 敏感写禁止 kind=self 独挡；产品编辑强制 source ACL 模板
  - msg-5 收窄：敏感后台立刻硬门+封堵；公开前台才 allowlist
  - 冰块权限真相=descriptor+Gateway；REST Acl 不可替代
---

## msg-8 | 2026-09-22T11:34:55+0800 | from:项目经理 | to:* | thread:api-charter | kind:note
agent_id: parent
body:
已按架构师 msg-7 落盘：align-freeze「暴露面权限=契约硬要件」+ Provider开发指南 后台写模板与唯一真相层。下一施工刀：compile 强制 frontend→auth；敏感后台存量盘点封堵。
---

## msg-9 | 2026-09-22T11:38:03+0800 | from:项目经理 | to:API | thread:api-charter | kind:note
agent_id: parent
body:
已冻架构已写入 seat_skill_mirrors.API 提示词与工程团队.md；权威首文档 align-freeze.md。API 席此后必须按序 Read 并遵循。
---
