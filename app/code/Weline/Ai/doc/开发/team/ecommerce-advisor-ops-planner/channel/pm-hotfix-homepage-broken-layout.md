# channel — P0 热修：首页发布后布局塌陷 / 叠层（PM 认账）

日期：2026-09-23  
角色：`Team:项目经理:`（只记账/派工/DoD，**禁止**改 Theme / Product / Widget / 前端业务码）  
`client_session_id`：`f22ca421-pm-hotfix-broken-home`  
`prepare_project` readiness：`ready-1790097780936-c2e8c0a34ebac1ca`  
验收面：
- https://p05113ef3.test.weline.com:9555/
- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/

## 0. 验收面纠偏（2026-09-23 · 用户新指示）

用户明确：**hanfu 只能在主题编辑器内优化（未发布）**；运营前头主导，按现实流程向 PM 提需求。

- 正确面：`theme_id=3` 主题编辑器草稿（见新 SESSION `Weline_Theme/doc/开发/session/hanfu-theme-editor-optimize.md`）
- 本热修若继续用已发布店面 `/`，只能验 **Default 前台激活面**，**不能**代替 hanfu 草稿优化
- 本文件热修波：仅收口「发布后门禁失职」已改源码的回归；**新优化需求迁到新 SESSION**，由运营 escalate 驱动

## 1. PM 认账（禁止甩锅运营 · 运营要背锅——如实记）

用户（运营视角）反馈首页发布后「怪怪的」，并明确：**这种情况要项目经理背锅**。

**本席承认（不辩解）**：Wave-3 商城感汇审（`meetings/汇审-wave3-mall.md` PASS）时，**验收门禁失职**——发布前未用禁缓存 Browser 抓到视觉回归，导致坏版上线。顾问升级见 `homepage-broken-layout-ops-escalation.md`。运营指出要 PM 背锅——**如实记录，锅在本席 DoD/汇审，不在运营**。

未抓到的问题：

1. 商品卡主图塌陷（媒体区只剩顶条，中部空白，心愿/列表/快览漂在空白；标题截断）；
2. Hero 与信任条叠层（主 CTA 被切开；仍显示占位文案 `Illustrative scene`）；
3. 悬浮圆形人像压住「Recommended product / 特色产品」标题左半。

**定性**：**P0 热修**。根因已坐实：Wave-3 MALL-01 对 `.wpc-media`/`img` 加 `max-height: 7.5rem`，与商品卡 `padding-top:100%` 方图锁冲突 → 图只剩顶条；Hero 整块 `overflow:hidden` 裁 CTA。  
**责任归属**：PM 验收门禁，**不是**运营、**不是**顾问 brief。禁止甩锅。

### 1.1 源码归属（防双写 · 2026-09-23 更新）

**父会话已热修布局源码**（Theme + hanfu design homepage）：去掉 7.5rem 压塌、Hero CTA 裁切修复、Illustrative 隐藏、强制 `.wpc-image` absolute 铺满、信任条死 max-height 取消、店音乐头像缩小。

| 席位 | 调整后角色 |
|------|------------|
| **席 A** [78708b02](78708b02-af5c-462a-b7e6-244ac4805ab4) | **stop 源码双写** → 仅清缓存 / 发布态验收；禁止再改 homepage layout |
| **席 B** [807aafe2](807aafe2-6481-4caf-9420-56ac36004aaf) | 勿碰 Hero/卡媒体；仅 store-music 避让（若父会话已达标则停写码只验收） |
| **PM 本席** | 禁缓存双路径 DoD 验收 + 汇审补记；仍不写业务码 |

## 2. 用户证据（ui_shot 必须认账）

| # | 现象 | 定性 |
|---|------|------|
| 1 | 推荐区商品卡主图塌陷；图标漂空白；标题 `...mmended product` / `nded product` | 布局/媒体容器回归 |
| 2 | Hero CTA「Shop Featured / Shop by Occasion」被下一截切开；Hero 上 `Illustrative scene` | Hero 高度/叠层 + 占位标未关 |
| 3 | 左侧圆形悬浮头像压住货架 H2 | store-music / avatar 与标题重叠 |

## 3. 工单与席位（真正唤醒 · 一席一智能体）

| 工单 | 席位 | 做什么 | 状态 | agent_id |
|------|------|--------|------|----------|
| `WO-HP-P0-HOTFIX-THEME` | **席 A** `Team:主题开发工程师:` + `Team:部件开发工程师:` | ~~改布局~~ → **stop 双写**；仅清缓存/验收 | **stopped→verify** | [78708b02-af5c-462a-b7e6-244ac4805ab4](78708b02-af5c-462a-b7e6-244ac4805ab4) |
| `WO-HP-P0-HOTFIX-FE` | **席 B** `Team:前端:` | store-music 避让（父会话已缩小头像；PM hit-test 过） | **verify-only** | [807aafe2-6481-4caf-9420-56ac36004aaf](807aafe2-6481-4caf-9420-56ac36004aaf) |
| 源码热修 | **父会话** | Theme + hanfu homepage 布局热修 | **done** | — |

## 4. 文件边界（硬 · 避免与父会话/席间重复大改）

父会话可能并行排查；**施工前先读本文件**，按边界下手，冲突先写 channel 再改。

| 席位 | **可改** | **禁止** |
|------|----------|----------|
| **席 A** 主题+部件 | ① 首页 Hero / 信任条 间距与叠层相关 CSS/layout（含 `homepage/default.phtml`、Hero widget、`.homepage-hero` / `.wc-theme_widget_hero_slider`、信任条相对位置）；② 商品卡**媒体区**容器高度/aspect-ratio/object-fit（含 Wave-3 引入的卡媒体 `max-height` 等导致塌陷的规则；`product-card` 媒体壳与货架 row 媒体相关 CSS）；③ Hero 内 `Illustrative scene` 占位标：隐藏/删除/仅 design 预览显示 | **不改** store-music / 悬浮头像定位与 z-index（归席 B）；不改 Cart/Checkout 业务；不扩 scope 做新商城感 |
| **席 B** 前端 | store-music 部件、悬浮圆形头像/播放器浮层：z-index、bottom/left 偏移、与 `#homepage-featured` / 货架 H2 避让，确保标题完整可读 | **不改** Hero 高度、信任条、商品卡媒体 aspect/max-height、Illustrative scene；不改 homepage 区块总顺序 |

**嫌疑热点（供席 A 优先核）**：`homepage-wave3-mall-01-done.md` 记载卡媒体 `max-height: 7.5rem` —— 极易导致「只剩顶条 + 空白」。

## 5. DoD（两席各自 done + PM 汇审）

双路径（`/` 与 `/zh_Hans_CN/`）禁缓存 Browser 验收后，各席写：

- `channel/homepage-hotfix-theme-done.md`（席 A）
- `channel/homepage-hotfix-fe-done.md`（席 B）

**全部必须满足**：

1. 商品卡主图**完整铺满**媒体区，无大块空白；心愿/列表/快览落在媒体层正确位置（非漂空白）；
2. Hero 主 CTA **完整可见**，不与信任条重叠/切开；
3. Hero **不再**对访客显示 `Illustrative scene` 占位；
4. 「Recommended product / 特色产品」标题**完整可读**，无圆形头像挡字；
5. 禁缓存（`Network.setCacheDisabled` 或 `?nocache=` + hard reload）双路径证据写进 done；`notify_pm: true`。

## 6. 禁写码席

| 席位 | 原因 |
|------|------|
| `Team:项目经理:` | 只派工/记账 |
| `Team:电商顾问:` | 本热修不需顾问写码；运营已举证 |

## 7. 状态

- [x] PM 认账 + 本热修单落盘（运营背锅要求如实记；见 §1）
- [x] 席 A / 席 B 已 interrupt：席 A **stop 源码双写**；席 B 勿碰 Hero/卡媒体
- [x] 父会话源码热修认账（`homepage-broken-layout-ops-escalation.md`）
- [x] PM 禁缓存双路径 DoD **PASS**
- [x] 汇审补记：`meetings/汇审-hotfix-broken-home.md` **PASS**
- [x] roster + progress 收口

**硬规则补记**：禁止再对首页货架 `.wpc-media` 设 `max-height` 压密度。
