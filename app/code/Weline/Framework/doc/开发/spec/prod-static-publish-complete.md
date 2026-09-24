---
status: clarified
work_kind: feature
feature_slug: prod-static-publish-complete
module: Weline_Framework
fe_be_scope: both
updated: 2026-09-24
session_path: ../../../../../../../dev/session/prod-static-publish-complete.md
clarify_status: clarified
plan_complexity: complex
ecommerce_advisor: skip
ecommerce_advisor_note: 非电商主路径；若规格触及店面静态资源可见性，属「非顾问决策面」（资源路径/发布完整性），不拉电商顾问做运营决策。
---

# 规格：生产模式一次性全量静态发布补齐（prod-static-publish-complete）

## 1. work_kind / fe_be_scope / 背景

| 字段 | 值 |
|------|-----|
| `work_kind` | `feature` |
| `fe_be_scope` | `both`（后端：部署模式切换 / `deploy:upgrade` 全量铺平；前端/店面：PROD URL 形状与 `resolveStaticPath` / `Module::` 解析；无独立新 UI 页面） |
| 归属模块 | `Weline_Framework` |
| 电商顾问 | **不拉起**（非电商主路径）。店面资源可见性仅作技术验收面，**非顾问决策面**。 |

### 背景（用户意图 · 硬）

线上（症状证据站：长安汉服等）仍大量静态资源 **404**。用户纠偏：

> **正确解法不是** 逐文件补丁 / 手工 `rsync` 若干模块；**而是** 框架在「设置为线上/生产模式」时，把**全部**静态资源按**正确路径**一次性补齐发布。

已知上下文（供澄清，**非冻结实现**）：

- 文档契约已写：`deploy:upgrade` 应对每个活跃模块 `view/statics` 同时铺 **主题 overlay**（`pub/static/{theme.path}/…/view/statics/…`）与 **flat 树**（`pub/static/{Vendor}/{Module}/…`），对齐 PROD `Module::` / `resolveStaticPath`（无 theme、无 `view/statics` 段）；`deploy.flat_static.*` Provider 仅为补充/兼容，**不再是防 404 主路径**（见 `static-resource-versioning.md`、开发日志 2.5.176）。
- 生产症状仍见：大量 `/Weline/*/view/statics/*` 与 `/static/...` 404；可疑粘滞：`FPC` / `deploy_version=dev`；nginx `sub_filter` 等运维层改写（**运维层不得替代框架全量发布契约**）。
- 本需求目标是把「切到 prod → 全量按正确路径发布」做成可验收的**完整闭环**（含缺口修复），而非再开一轮手工同步。

### 目标（what）

1. 运维/开发者将部署模式切换为 **prod** 后，框架**一次**完成全部活跃模块静态资源的正确路径发布（overlay + flat）。
2. 店面在 PROD 下请求静态资源时，**不再依赖** `/Vendor/Module/view/statics/...` 源码形路径作为主可达路径；浏览器请求的 URL 形状与磁盘 flat/约定路径一致且 **200**。
3. **无 `deploy.flat_static.*` 白名单登记**的活跃模块，只要存在 `view/statics`，也必须落盘 flat 树（白名单不得再充当「有无 flat」的门闩）。

### 成功标准（草稿）

- 本机：`deploy:mode:set prod`（或等价入口）后，抽样多模块 flat + overlay 均存在；店面关键 CSS/JS HTTP 200；HTML/`modules.js` 等不再指向 DEV 形 `/…/view/statics/…` 作为主路径。
- 生产：仅作**症状证据**引用，**不作为本需求施工与验收唯一环境**。

---

## 2. 用户故事 + EARS + 主路径用例

### 用户故事 US-1

As a **站点运维/开发者**,  
I want **将部署模式切换为生产（prod）时框架一次性按正确路径发布全部模块静态资源**,  
so that **店面不再因缺失 flat/overlay 或路径形状错误而大面积静态 404，且无需手工 rsync 补文件**.

#### EARS（US-1）

1. **WHEN** 操作者将部署模式成功切换为 `prod` **THEN** 系统 **SHALL** 对**全部活跃模块**中存在 `view/statics` 的模块，完成主题 overlay 与 flat 树（`pub/static/{Vendor}/{Module}/…`）的一次性铺平，且不以 `deploy.flat_static.*` 白名单作为是否铺 flat 的前置条件。
2. **WHEN** 部署模式已为 `prod` 且全量静态发布已完成 **THEN** 店面页面解析出的模块静态 URL **SHALL** 符合 PROD 约定形状（与 `Module::` / `resolveStaticPath` 对齐：无 theme 段、无 `view/statics` 段作为主路径），且对应磁盘文件存在并可 HTTP 200 取得。
3. **IF** 某活跃模块存在 `view/statics` 但未登记任何 `deploy.flat_static.*` Provider **THEN** 系统 **SHALL** 仍为其生成/更新 flat 树（缺失模块也有 flat 树）。
4. **IF** 全量发布过程中某模块无 `view/statics` 目录 **THEN** 系统 **SHALL** 对该模块静态铺平 **no-op**（不失败整次 prod 切换），且不影响其它模块铺平。
5. **WHEN** 切到 prod 的发布流水线结束 **THEN** 系统 **SHALL** 使后续店面响应所依赖的部署版本/缓存语义与 prod 一致（不得在默认路径上继续以 `deploy_version=dev` 或过期 FPC HTML 主导静态 URL），具体失效策略由对齐冻结会结合性能席结论冻结。

### 用户故事 US-2（非目标边界可见）

As a **站点运维**,  
I want **验收以框架一键发布闭环为准**,  
so that **不会把「手工 rsync 了几个模块」误判为需求完成**.

#### EARS（US-2）

1. **IF** 仅通过手工复制/rsync/nginx 单点补丁使个别 URL 暂时 200 **THEN** 该状态 **SHALL NOT** 被记为本 feature 验收通过。
2. **WHEN** 验收执行 **THEN** 验证步骤 **SHALL** 以「本机切 prod → 框架发布产物 + 店面请求」为主链；生产站 URL 仅可作症状对照，不作唯一通过条件。

### 用例

#### UC-1 — 切到 prod → 全量静态发布（主成功路径）

| 字段 | 内容 |
|------|------|
| id | UC-1 |
| 名称 | 切换生产模式触发全量静态发布 |
| 角色 | 运维/开发者（本机 CLI） |
| 前置 | 本机仓库可运行；至少 2 个活跃模块含 `view/statics`（建议含 1 个**未**登记 `deploy.flat_static.*` 的模块）；当前可为 `dev` |
| 主成功步骤 | 1. 记录切换前 `pub/static/{Vendor}/{Module}/` 抽样是否缺失。<br>2. 执行框架「设置为生产模式」入口（候选：`php bin/w deploy:mode:set prod` 或文档等价命令；**以对齐冻结会冻结的正式入口为准**）。<br>3. 等待命令成功结束（exit 0 / 控制台成功语义）。<br>4. 断言：抽样模块 A/B 的 flat 根目录存在，且相对 `view/statics` 的关键文件（如 `css/*.css` / `js/*.js`）已落盘。<br>5. 断言：对应主题 overlay 路径仍存在（双树并存）。 |
| 备选/异常 | A1：无 `view/statics` 的模块 → 跳过该模块静态铺平，整次切换仍成功。<br>E1：铺平中 I/O 失败 → 命令失败且可观察错误；不得静默标「已 prod」却缺主路径产物（失败语义细节对齐冻结）。 |
| 期望结果 | 全部有 statics 的活跃模块均具备 flat（+ overlay）；不依赖手工 rsync。 |
| 映射 acceptance | `type=e2e` / CLI+文件系统断言；计划项建议 `e2e-plan-suite` 含「mode set prod → flat 存在」 |

#### UC-2 — 店面请求不再走 `/Vendor/Module/view/statics` 主路径

| 字段 | 内容 |
|------|------|
| id | UC-2 |
| 名称 | PROD 店面静态 URL 形状与可达性 |
| 角色 | 匿名店面访客（本机 Browser / curl） |
| 前置 | UC-1 已在本机成功；本机验收 Host 可用（`{project_hash}.test.weline.com`）；后台可用 `admin/admin`（若需清缓存/确认模式，禁止向用户索密） |
| 主成功步骤 | 1. 打开本机店面首页（或含模块静态引用的代表页）。<br>2. 采集页面/`modules.js`/网络面板中模块静态请求 URL 列表（≥3 条跨模块）。<br>3. 断言：主路径 URL **不含** `/view/statics/` 段（PROD 约定）；形状对齐 `{Vendor}/{Module}/…` flat（或框架冻结的 PROD 规范）。<br>4. 对抽样 URL 发 GET，期望 HTTP 200 与非空 body（或正确 MIME）。 |
| 备选/异常 | A1：若仍出现 `/view/statics/` 请求 → 记缺陷（发布未闭环或编译/解析未切 PROD），**不得**用 rsync 掩盖后宣称通过。<br>E1：FPC 命中旧 HTML 仍吐 DEV 路径 → 归入「切 prod 后缓存/版本未对齐」缺口（性能席设计检查协同）。 |
| 期望结果 | 店面静态主路径为 PROD flat 约定且可达；DEV 源码形路径不是主依赖。 |
| 映射 acceptance | Browser WB-OP + 网络断言；`type=e2e` |

#### UC-3 — 缺失白名单模块也有 flat 树

| 字段 | 内容 |
|------|------|
| id | UC-3 |
| 名称 | 未登记 flat_static Provider 的模块仍获 flat |
| 角色 | 运维/开发者 |
| 前置 | 选定活跃模块 M：有 `view/statics`，且**无** `deploy.flat_static.*` 实现登记该模块文件白名单 |
| 主成功步骤 | 1. 若存在旧 flat 可先删除该模块 flat 根（仅本机验收沙箱）。<br>2. 执行与 UC-1 相同的切 prod / 全量发布入口。<br>3. 断言 `pub/static/{Vendor}/{Module}/` 下存在 M 的 statics 相对文件（非整树空目录）。 |
| 备选/异常 | 无（或：Provider 补充写入与整树双写幂等，不互相覆盖损坏）。 |
| 期望结果 | 「没进白名单」≠「没有 flat」；白名单仅补充/兼容。 |
| 映射 acceptance | UT（已有 `ModuleFlatStaticsPublishTest` 方向）+ CLI 后文件系统断言 |

---

## 3. 框架机制映射（候选 · 选型冻结留给对齐冻结会）

> 本席只列**候选映射**与文档锚点；**不冻结**最终类/事件排序/是否增 Observer。扩展点原则：优先 Event / 既有 Console 流水线；禁止跨模块直调他模块 Service。

| 意图 | 候选机制 | 文档 / 代码锚点 | 备注 |
|------|----------|-----------------|------|
| 切到生产模式总入口 | Console `Deploy\Mode\Set` → `deploy('prod')` | `Console/Console/Deploy/Mode/Set.php` | 已含：清缓存 → Compile → 清 pub 静态 → `Upgrade::execute()` → `prod_after` |
| 全量静态铺平（overlay + flat） | `deploy:upgrade` / `Deploy\Upgrade` | `Console/Console/Deploy/Upgrade.php`；`doc/static-resource-versioning.md` | 2.5.176 已述整树 flat；本需求验收「完整闭环」含缺口修补 |
| 切 prod 后旁路扩展 | Event `Weline_Framework_Deploy_Mode_Set::prod_after` | `doc/event/deploy/部署模式切换到生产环境后.md` | 文档称静态已由 upgrade 完成；观察者可做压缩/清缓存/标志——**是否把「二次校验/补铺」挂此事件由对齐冻结会决定** |
| PROD URL / 磁盘约定 | Compiler / 模板静态标签 / `Module::` | Taglib `@static` / `fetchTagSource`；`static-resource-versioning.md` Taglib callback 例外 | Taglib callback 禁裸 `@static` 字符串（隐形需求） |
| 浏览器侧解析 | `resolveStaticPath`（如 `weline.js`） | 开发日志契约：不改 PROD 形状为默认前提；若必须改须专单冻结 | 与 flat 路径对齐 |
| 部署版本与缓存粘滞 | `deploy_version`、FPC、Cache Clear（Mode\Set 已调 Cache\Clear） | 性能席 design 轨；SESSION 已标 FPC/`deploy_version=dev` 粘滞 | **候选**：切 prod 流水线内强制版本写回 + FPC 失效；细节归性能/架构冻结 |
| 主题域 vs 模块 flat | overlay `pub/static/{theme.path}/…/view/statics` **与** flat `pub/static/{Vendor}/{Module}/` **双树** | `Upgrade` + versioning 文档 | 禁止「只留 overlay 删 flat」或「只 flat 丢 overlay」除非架构冻结书面例外 |
| 白名单补充 | `deploy.flat_static.*` Provider | `FlatStaticRuntimeFilesProviderInterface` | 降级为补充；非防 404 主路径 |
| 扩展点选型总则 | Event / Interface；禁跨模块 new Service | `doc/3-开发/扩展点选型.md` | 若需新事件须 TaskContract + 文档，禁发明未文档化事件名 |

**与既有实现的关系（澄清记录，非宣称已完成需求）：**

- 文档与 `Upgrade` 已描述/实现「整树 flat」方向；用户仍报生产大面积 404 → 规格认定「**闭环未完成或运行时路径/缓存未对齐**」，本 feature 继续以 UC/EARS 验收，直至本机主链通过；不以「代码里已有 publishModuleFlatStatics」单独关闭需求。

---

## 4. 非目标（硬）

1. **禁止**把「手工 rsync / scp / 面板上传若干模块静态」写成验收通过条件或关闭条件。
2. **禁止**以 nginx `sub_filter`、单 URL rewrite、逐文件线上补丁作为本 feature 的「完成定义」（可作临时运维缓解，但**不计入**本规格验收）。
3. **不在本规格**要求改业务商品/CMS 内容；不拉电商顾问做运营决策。
4. **不在澄清阶段**冻结具体类改动清单、是否修改 `resolveStaticPath` PROD 形状、是否新增 Observer——留给对齐冻结会 + 架构/扩展点席。
5. **默认不把生产 SSH 热修**作为开发主路径；生产仅症状证据。施工与验收默认**本机**。
6. **不把**「只修复某一个 404 文件」当作范围；范围是**全量**活跃模块静态按正确路径发布闭环。

---

## 5. 验收标准草案（本机；生产仅症状证据）

### 本机（主验收）

| # | 标准 | 证据形态 |
|---|------|----------|
| A1 | 执行官方「设为 prod」入口成功 | CLI 输出 / exit code |
| A2 | 所有抽样活跃模块（含无 flat_static 白名单者）flat 树文件齐全 | 文件系统断言 / UT |
| A3 | 同批模块主题 overlay 仍存在（双树） | 文件系统断言 |
| A4 | 店面代表页模块静态主路径无 `/view/statics/`，抽样 GET 200 | Browser WB-OP / curl；本机 Host |
| A5 | 切 prod 后店面不再被粘滞的 `deploy_version=dev` / 旧 FPC HTML 主导错误静态 URL（具体断言键名对齐冻结） | 响应头/HTML 抽样 + 性能席协同 |
| A6 | 回归：无 statics 模块不导致切换失败 | CLI |

### 生产（仅症状证据 · 非施工默认环境）

| # | 用途 | 说明 |
|---|------|------|
| P1 | 对照 | 例如 `https://www.changanhanfu.com/` 上静态 404 / 错误路径截图或 HAR **仅证明问题存在** |
| P2 | 非通过条件 | 未在本机 A1–A6 通过前，不得以生产偶发 200 宣称本 feature 完成 |

### 建议计划验收类型（供 PM）

- `type=unit`：flat 整树 / 非法名守卫 / overlay+flat 并存（可延续 `ModuleFlatStaticsPublishTest`）
- `type=e2e` / `e2e-plan-suite`：UC-1 + UC-2 主路径
- Browser WB-OP：UC-2（禁缓存、抹自动化标志；交付后关 Browser）

---

## 6. 待澄清问题列表（不问用户；供 PM / 对齐冻结会）

1. **正式入口冻结**：验收与文档对外是否统一为 `deploy:mode:set prod`（或其它包装命令）？二次执行 prod（已是 prod 再 set）是否必须全量重铺？
2. **「全部静态」边界**：是否含模块 `view/theme` 资源、生成型文件（minify/map）、仅 Provider 声明的运行时文件？媒体库 `pub/media` 是否**明确排除**？
3. **`resolveStaticPath` / `Module::`**：对齐冻结会确认「不改 PROD URL 形状」是否仍成立；若生产 HTML 仍大量吐 `/view/statics/`，根因算发布缺失还是编译/解析未切 PROD——责任席（后端 vs 前端）？
4. **FPC / `deploy_version` 粘滞**：切 prod 时现有 `Cache\Clear` 是否足够？是否需在 `prod_after` 强制写 `deploy_version`、清整站 FPC、或版本目录（如 `/s/{version}/`）对齐？**待性能席 design 结论。**
5. **活跃模块集合**：禁用/未安装模块是否必须清理旧 flat？升级中新启用模块是否要求「仅 upgrade」即可不重走 mode set？
6. **主题多版本/多主题**：多 theme.path 时 overlay 铺哪些主题（仅当前激活 vs 全部已安装）？
7. **失败原子性**：中途失败是否回滚 `deploy=prod` 配置写入？当前 Mode\Set 顺序（先 upgrade 再 setConfig）是否接受？
8. **与 2.5.176 关系**：对齐冻结会确认本 feature 是「补闭环/回归加固」还是「发现新缺口后增量」——避免重复施工与 SESSION 双计。
9. **运维层 nginx `sub_filter`**：是否写入「允许并存但不可替代验收」的运维说明（框架 doc），还是明确列为反模式附录？
10. **跨站**：官方站 / 汉服 / DaoCharms 是否共用同一套 prod 静态契约（预期是），站点柜差异是否只影响部署文档而非框架行为？

---

## 澄清记录

| 时间 | 来源 | 要点 |
|------|------|------|
| 2026-09-24 | 用户意图（PM 转述） | 正确解法=框架设为线上/生产模式时一次性按正确路径补齐全部静态；禁止逐文件/rsync 完成定义 |
| 2026-09-24 | SESSION / 文档 | 已有 overlay+flat 契约与 `prod_after`；生产仍 404 + FPC/deploy_version 粘滞线索 |
| 2026-09-24 | 本席 | `status=clarified`；机制仅候选映射；电商顾问 skip（非顾问决策面） |

### 隐形需求摘要

- Taglib `callback` 返回 HTML 不得依赖未解析的 `@static(...)` 字面量（见 `static-resource-versioning.md`）。
- 模块不得自行拼接随机版本参数规避缓存。
- 脏工作区保留；本席不改 PHP/phtml/CSS。
- 查询/验收默认本机；未明示生产不得 SSH 变更。

---

## 就绪检查

- [x] `status` ≥ `clarified`
- [x] ≥1 用户故事 + 每故事 ≥2 条 EARS
- [x] ≥1 用例（UC-1/2/3，含主成功路径）
- [x] 非目标明确（含禁止 rsync 验收）
- [x] feature：已点名将产生的 `type=e2e` / Browser 验收意图
- [x] 未把架构实现细节写成冻结 how（仅候选映射）
- [ ] `ready-for-plan`：待对齐冻结会消化待澄清问题后由 PM/架构升格

**下一步（非本席）**：对齐冻结会（contracts + UC + deps）→ 架构/扩展点选型冻结 → 再施工。本席规格路径已就绪供冻结引用。
