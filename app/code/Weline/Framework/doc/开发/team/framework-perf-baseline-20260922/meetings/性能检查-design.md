# 性能检查 — design + baseline review

- seat: 性能检查工程师
- date: 2026-09-22
- scope: **框架级**热路径与缓存机制（非单业务 feature）
- architect_joint: **true**（架构师子智能体 stance=同意；通道 `channel/perf-architect.md`）
- verdict: **fail（未达标）** — 缓存机制设计大体合规，但冷路径与若干已知瓶颈未关闭；不得宣称性能达标

---

## 业务特性摘要

| 项 | 结论 |
|----|------|
| 面 | 店面 HTML（首页/商品列表）+ 后台慢请求历史 + Search direct 读模型 |
| 站店渠 | 默认站 `website_id=0` / Host `p05113ef3.test.weline.com:9555`；多 scope 缓存键 |
| 个性化 | 店面公共 chrome/目录可共享；登录态/草稿/权限敏感不得进共享池 |
| 热路径 | Worker 冷构建（Header/Slot/目录投影）、i18n 批量、Search direct 全站快照、ACL 多别名查询、WLS Fiber 长同步段 |
| 可复用 owner | HotCache/CachePolicy、StorefrontCatalogCacheCoordinator、`Parser::prefetchWords`、发布态 offer 批量入口 |

---

## 框架结构映射（已核对）

- 运行时：WLS Master + Worker（本机现 **2 Worker**）+ Session/Memory sidecar；请求生命周期 / Fiber。
- 缓存栈：`CachePolicy` → HotCache/`rememberPolicy` → CacheManager → CachePool → WLS Memory Adapter；scope=`global|website|store|channel`；namespace generation 失效。
- 设计否决项已文档化：业务类禁止平行进程内袋；CachePool 代理层无 epoch 的 processStore **已撤销**（多 Worker 脏读）。
- 扩展点：跨模块读应走 QueryProvider，禁止用直调换性能捷径。

---

## 缓存设计合规检查

| 检查项 | 结论 | 证据 |
|--------|------|------|
| CachePolicy + scope/vary/dependencies 权威入口 | **pass（设计）** | `统一缓存范围与性能优化.md` 范围声明与统一入口 |
| 业务平行进程内袋硬禁 | **pass（规则）** | 硬规则索引 + 2026-09-09 撤销代理层袋 |
| `CachePool::getMultiple/setMultiple` | **fail（实现缺口）** | `CachePool.php` 仍 **逐项** `get`/`set` 循环——不可当真实批量 RPC；文档已警告勿用其替换 DB N+1 |
| 可变 Model/个性化 HTML 进共享池 | **pass（口径）** | 文档明确禁止；需在具体 feature 复审继续盯 |
| 失效挂 namespace/owner | **pass（设计）** | NamespaceGeneration + 领域协调器 |
| 预热 Host / FPC authority | **风险** | Worker 状态：`homepage-fpc:deferred-after-ready:fail-open`，warmup `hit=false` |

---

## 本机运行证据（2026-09-22 · no-cache · HTTPS）

实例：`default`，Master 57830，Worker 2（95525/95593），Memory/Session Running。

| 样本 | HTTP | TTFB | total | 字节 |
|------|------|------|-------|------|
| 首页冷 | 200 | **2.356s** | 2.360s | 1,289,478 |
| 首页紧邻再请求 | 200 | **0.014s** | 0.018s | 1,283,504 |
| `/en_US/products`→`/products` 冷 | 200 | **2.299s** | 2.305s | 2,135,209 |
| 商品列表紧邻再请求 | 200 | **0.009s** | 0.017s | 2,135,209 |

解读（检查纪律）：

- 暖路径已很快（约 10–20ms 级 TTFB），说明 **共享/进程内热缓存有效**。
- 冷路径仍约 **2.3s**，相对历史诊断（曾 5–26s）有改善迹象，但**远未**到版本计划冷启动目标（&lt;100ms 量级）；首页约 **1.29MB**、列表约 **2.14MB** HTML，体积本身是解析成本。
- 本样本未开 Browser 下半页验收；未附 router timing 明细，故不宣称加速倍数。

历史证据（`性能诊断-20260908.md`，仍有效作 backlog）：

1. 后台菜单翻译 N+1 → 已有 `prefetchWords` 修复，**整页后台仍可能慢**（ACL 多别名 ~1.29s 等未关）。
2. Search **direct** 默认与「改一品扫整站」风险仍在文档 backlog。
3. WLS Fiber 长同步段（ready→resume ~1.7s 级样本）未闭合归因。
4. 店面冷构建瀑布（Header/搜索类型/目录投影）仍是主矛盾之一。

---

## 优化方向（与架构师共同冻结）

1. **批量预取落正式入口**：菜单/ACL/标签词等用 `Parser::prefetchWords` / 批量 Provider / **真** MGET·MSET；禁止把 DB N+1 换成逐键 WLS RPC；`CachePool::getMultiple` 当前为循环，不可当批量。
2. **商品/目录事实与聚合分层**：实体事实独立 namespace + `CachePolicy`；聚合依赖 catalog/price/… 正式 bump；重建组装已有快照，禁止删依赖保假 HIT。
3. **Theme 已发布布局/Slot 投影进 HotCache**：复用 Theme `w_changed`；键仅 area/theme/layout/page_type/target + 站店渠；预览/草稿禁入公共池（替换空 stub / 私有袋）。
4. **I18n 定向失效**：统一 `global/i18n` generation + 写侧 impact；收敛全清广播与旁路。
5. **Search 读模型收口** + **冷构建预热/FPC Host**：校验索引一致性后切正式 alias；direct 须约束请求 scope；修正 warmup `fail-open`，使公网首请求可 HIT。
6. **（补充）后台 ACL 别名批量判定**与 **HTML/Slot 体积**（Mega menu 延迟）——机制协商，不删功能语义。

### 禁止项（双方一致）

- 业务类 `static`/平行进程内袋绕开 HotCache  
- 无 epoch/广播的多 Worker 进程内袋；重做已否决的 CachePool `processStore`  
- 可变 Model / 个性化 HTML / 草稿进共享池；公共键带 request_id  
- 删 namespace 依赖假 HIT；跨样本伪加速比；FPC HIT/HTTP 200/单测绿冒充冷启动达标  
- 跨模块直调 Service/Model「性能捷径」；单席私定「加缓存」

---

## 复审结论

| 维度 | 结果 |
|------|------|
| 缓存机制设计合规 | 大体 **pass**（权威清晰；实现上 getMultiple 仍为缺口） |
| 当前运行性能 | **fail**（冷路径 ~2.3s；目标未达；FPC warmup fail-open） |
| 已知 backlog 关闭度 | **fail**（Search/ACL/Fiber/HTML 体积等仍开） |

下一步：与架构师联合冻结上列方向 → 写入 `channel/perf-architect.md` + 更新本文 `architect_joint=true`；再按方向开施工轨（归属 Framework/Product/Search/Acl 等席），开发后再跑 `性能检查-review.md`。
