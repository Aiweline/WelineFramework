# wave3a — Search serving alias `direct`（P0-2）

- date: 2026-09-22
- seat: **Team:后端:**（Search 读模型归属）
- channel: `channel/framework-unreasonable-audit.md` msg-5/6 → 本席回报
- architect_gates: G1–G6（msg-6 **有条件同意**切 index；门禁未齐优先收紧 scope）
- reload: **未执行**（禁自 reload）

## result

`patched_scope`（关「改一品扫整站」默认直读路径；**未**无门禁硬切默认站 `alias=index`）

## root_cause

1. **`w_search_serving_alias` 表空（0 行）** → `SearchAliasStore::state(0)` **缺行软默认** `alias=direct, generation=0, version=0`（代码默认，非 Env 配置项）。`isMissingDefault(0)=true`。
2. **叠加** `SearchRolloutGate` mode=`off`：即便 alias 将来为 index，off/shadow 仍走 Product current（符合 MIG-P3C；切读须 G2/G3 allowlist|on）。
3. 直读曾调用 Product **`snapshotWebsite` 整站** + website+watermark 进程袋 → 「改一品 → 水位 bump → 下次搜索展开全部店铺/渠道」。

运行事实（只读）：index `active_generation=18`、`registry_ready=true`；Product source vs Search incremental **lag≈4**；**无本波 MIG fresh-verify 证据** → 按 G1–G3 **禁止硬切**。

## fix（对齐 G4/G6；G1–G3 留给迁移批切）

| 项 | 内容 |
|----|------|
| Product `1.0.291` | 公开 Query `snapshotScope(website,store,channel)`；全站 `snapshotWebsite` 仅 indexer/migration |
| Search `1.4.18` | `ProductProjectionDirectCatalogReader` 只调 `snapshotScope`；`SearchAliasStore::isMissingDefault`；文档写明禁无门禁自动 CAS |
| 架构门禁 | **禁跨模块直调**：只扩 Product Query；Search 经 `ProductSearchProjectionSourceInterface` / Search Query |
| UT | `SearchAliasStoreTest` + scope reader — phpunit OK |
| **未做** | 无 G1 verify / G2 allowlist / G3 serving 证据下的默认站 CAS→`index`（遵 msg-6） |

## next（@项目经理）

1. 追平 Search 增量至 Product watermark（lag→0）
2. `commerce:migrate-p3c-search` preflight→…→allowlist（或等价 G1–G3）后 CAS `0:0:0`
3. PM 批验证 → reload → 性能复测 `state(0).alias=index`

## reload_needed

**true**（本席不自 reload；scope 补丁生效需部署/reload；alias 切 index 另需迁移门禁）


## 性能复测（msg-10 · 性能检查工程师）

- date: 2026-09-22
- reload: PM 已执行；本席禁 reload
- **verdict: pass_scope_gate**（alias 仍 `direct`/`missing=yes` → **不判 fail**）

| 检查 | 结果 |
|------|------|
| DirectCatalogReader → `snapshotScope` | **pass** |
| 无店面 `snapshotWebsite` | **pass** |
| 公网 HIT 回归 | **pass**（`/`+`/products`） |

下一步仍属 PM：追平水位 → G1–G3 CAS → alias=index 后再复测。
