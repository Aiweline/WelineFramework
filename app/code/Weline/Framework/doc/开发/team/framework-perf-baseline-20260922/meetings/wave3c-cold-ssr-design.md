# wave3c — 冷 SSR 开题（体积 / 瀑布 / 预热）

- date: 2026-09-22
- seat: **Team:主题:**（`work_mode=design` · **design_only**）
- channel: `channel/framework-unreasonable-audit.md` msg-5/6/7
- architect: msg-6 `stance_cold=open_three_axes_volume_waterfall_warmup_no_claim`
- related: `meetings/框架不合理点-20260922.md` P0-1 / P1-7 / P2-2 / P2-4；`meetings/主题-R3-design.md`；`surfaces.md`
- status: **开题纪要**（本波**不写大码**、**不宣称达标**、不与 wave3a 抢施工席）

---

## 0. 边界与禁区（硬）

| 项 | 约束 |
|----|------|
| 本波产物 | 仅本纪要 + channel 短回报 |
| 达标声称 | **禁**：不得用公网暖 HIT（~10–40ms）或跨样本伪加速比宣称冷路径已关 |
| SLA | 目标仍参考 &lt;100ms；**本开题不改 SLA** |
| 平行袋 | **禁**业务 `static` / 无 Policy 进程袋；Theme 扫盘升格须 `CachePolicy` + `deps=theme` |
| 功能语义 | **禁**删 Slot/部件/依赖语义换体积；禁删 namespace 假 HIT |
| FPC / READY | **禁**改 fail-open 默认=0；**禁**非 owner 假 HIT；**禁**拆出站 `private,no-store` |
| 归因 | 下一波施工前须同 Worker + `wls_trace`/phase 拆 **A/B/C 占比** 再排优先级 |

**归因纪律（msg-6）**：进程冷 MISS **7–14s** vs 暖 HIT~10–40ms → 问题在 **冷构建**；FPC 层已证明有效，wave3c 只解冷构建三轴。

---

## 1. 对照证据（本开题采信，不重采）

| 样本 | 量级 | 含义 |
|------|------|------|
| 首页 HTML | ≈ **1.32MB**（~1.3MB） | mega/隐藏 Slot + chrome 放大解析与字符串组装 |
| 列表 `/products` | ≈ **2.06MB**（~2MB） | 列表投影体更大；MISS 时与瀑布叠加 |
| Worker 直连冷 | `/`≈14s · `/products`≈7.9s | 全量 SSR（P0-1） |
| 公网暖 HIT | ~10–40ms | **仅**证明 FPC 有效 ≠ 冷达标 |
| R3 结构 HotCache | 代码已补；review「冷瀑布收益未证」 | 结构层 HIT ≠ 体积/串行 builder 关闭 |
| Theme 扫盘 | Directory/Path/Catalog 多实例袋、未全挂 HotCache | P2-4 / M6 合规边缘债 |

---

## 2. 三轴开题（对齐架构师 msg-6）

### 轴 A · 体积（Owner 提示：主题 + 前端）

**机制判断**

- 首页~1.3MB / 列表~2MB 使冷 MISS 的 DOM 拼装、字符串拼接、gzip 前缓冲均变贵。
- mega / 非首屏 / 隐藏 Slot 的 regular widgets 仍参与 SSR 输出 → 放大 HTML，而非「看不见就不算成本」。

**短方案（下一波 · 设计意向，非本波施工）**

1. **非首屏** mega / regular widgets：**按需或延迟输出**（首屏关键 Slot 先闭环；次屏延迟渲染或客户端补全入口——须保留功能语义与 SEO 约束，细节施工波再冻）。
2. 压缩 / CDN / 传输层复测（与 HTML 源体积分账；禁把传输优化冒充 SSR 变小）。
3. **禁**删功能语义、禁卸依赖部件「只为好看体积」。

**本席下一波可验清单（设计）**

- [ ] 首屏 Slot 清单 vs 非首屏/mega 清单（按 layout 冻结）
- [ ] 延迟输出不影响已发布布局语义与 ACL/可见性
- [ ] 体积前后对照用同路径同语种同 Host；禁跨样本

---

### 轴 B · 瀑布（Owner 提示：主题 + 后端/Product）

**机制判断**

- 冷路径上 Header / 搜索类型 / 目录投影 / Slot **串行** builder 叠加；MISS 时墙钟落在「一串同步段」。
- Theme 链上 `is_file` / `is_dir` **扫盘**未全挂 HotCache（P2-4）；R3 已覆盖已发布布局 structure 投影，**未**覆盖路径/目录事实全链。
- 商品事实与聚合若与 Slot 同层同步拉，会把列表~2MB 与投影债绑在同一瀑布里。

**短方案（下一波）**

1. 已发布布局 / 目录事实：继续 **CachePolicy**（R3 方向延续；草稿/预览禁入）。
2. 模板路径 / area 目录事实：`rememberPolicy`，`deps=['theme']`；**升格扫盘袋**，**禁**平行 `static`。
3. 商品事实与聚合分层：店面读走正式 Query/投影边界（不与 wave3a Search alias 抢席；消费方禁跨模块直调）。
4. **禁**删 namespace 假 HIT；禁无无 epoch 进程袋「顶」扫盘。

**与 R3 关系**

| 层 | R3 现状 | wave3c B 轴 |
|----|---------|-------------|
| 已发布 layout structure | Policy 已补；冷收益未证 | 保留；施工波用 trace 证 HIT 是否缩短瀑布 |
| 路径/目录扫盘 | 多实例袋 | **下一波主题主责升格** HotCache |
| Slot widget SSR 串行 | 未关 | 与 A 轴延迟输出联动，非单靠结构缓存 |

---

### 轴 C · 预热（Owner 提示：后端/Runtime；主题只协作路径清单）

**机制判断**

- fail-open 窗口 + deferred 完成前公网首击仍可全量 SSR（M3 残余）。
- Process L1 挤出（多 MB locale 体）→ 首页 receipt 丢失 → 二次全量 SSR。
- 部分 locale probe MISS 占用 `max_paths` 槽 → 关键路径变体被挤（P2-2）。

**短方案（下一波）**

1. **保留** fail-open 默认；强化 deferred：`/` + 关键路径优先、公网 Host、L1 保活首页 receipt。
2. locale 失败 **勿**挤掉关键 HIT；路径贡献与 ready 判定归后端。
3. **禁**改 fail-open=0；**禁**非 owner 假 HIT；**禁**拆出站 `private,no-store`。

**主题协作面（非本席改 Runtime）**

- 冻结「关键路径」清单建议（`/`、目录代表如 `/products`）；locale 扩展槽不得反向挤掉首页。
- 不在本开题改 warmup 代码。

---

## 3. 归因 → 排期建议（给项目经理 · 非自排施工）

施工波启动前强制：

1. 同 Worker 冷 MISS 一次，采 `wls_trace` / phase：拆 **A 体积拼装** / **B 串行 builder+扫盘** / **C 预热缺口（若本请求本应已被暖）** 占比。
2. 占比最大轴先排；若 A+B 接近，优先可验证的 Policy/扫盘升格（B）与首屏延迟输出（A）小步并行，**仍禁大码一次投完**。
3. C 轴变更须后端 owner；主题只提供路径/布局清单与验收对照。

**suggested_seats（施工波，非本波）**

| 轴 | 主席 | 协席 |
|----|------|------|
| A | 主题 + 前端 | 部件（若 Slot 延迟边界） |
| B | 主题 | 后端/Product（投影分层） |
| C | 后端/Runtime | 性能复测 |

---

## 4. 明确不做（本开题）

- 任何 Theme/Frontend/Runtime **大码**或升版宣称
- 宣称冷 TTFB / HTML 体积已达标
- 平行 `static` 袋、无 Policy 进程袋
- 删功能语义 / 卸 Slot 依赖
- 改 fail-open、假 HIT、拆 `private,no-store`
- 与 wave3a Search alias 硬切抢席

---

## 5. result

```
Team:主题: result=design_only
wave=wave3c-cold-ssr
verdict=open_three_axes（对齐 msg-6）
artifact=meetings/wave3c-cold-ssr-design.md
axes=A体积(~1.3MB/~2MB)·B瀑布(Slot串行+扫盘)·C预热(fail-open保留/deferred强化)
code=none
claim_sla=false
next=@项目经理：批施工波前先同Worker trace拆A/B/C占比；主题可领A/B小步，C归后端
```
