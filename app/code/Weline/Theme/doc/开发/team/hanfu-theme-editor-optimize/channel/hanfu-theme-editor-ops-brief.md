# hanfu 主题编辑器 · 运营 brief（电商顾问）

日期：2026-09-23（实体预览复审 ≈01:47+08）  
席位：`Team:电商顾问:`（运营策划 · **禁写码**）  
SESSION：`app/code/Weline/Theme/doc/开发/session/hanfu-theme-editor-optimize.md`  
主题事实：`w_weline_theme` id=**3** / name=hanfu / `is_active_frontend=0`（未发布；**只能**在主题编辑器草稿内验收）  
主验收（需后台登录）：  
`https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/theme/backend/theme-editor?theme_id=3&page_type=homepage&layout_option=default&editor_area=frontend&preview_area=frontend&status=draft&interaction_mode=edit`  
**禁止**把已发布店面 `/`（Default 前台激活面）当本主题主验收。

---

## 1. 运营意图（领域决策）

面向「长安汉服 / Chang'an Hanfu」独立站：在 **未发布** 的 hanfu 主题内，先把编辑器草稿首页做成**可逛可买的水墨商城**，而不是纯画册。

| 维度 | 目标 |
|------|------|
| 品牌 | 水墨留白 + 朱砂点缀；色服从 Theme `ink` token，勿另造色板 |
| 首屏 | 5 秒内懂「卖汉服」；一主 CTA；可见带价商品卡 |
| 转化 | Hero → 信任 → 精选货架 → 品类磁贴 → 特价；内容装饰不压货架 |
| 验收面 | **仅** theme_id=3 编辑器草稿预览（禁缓存 + 抹 `navigator.webdriver`） |

当期运营研究（2026-09-23）：首屏单 CTA + 信任条紧贴 Hero + 紧凑爆款货架。  
来源：[Audit Your Store · Above-the-Fold 2026](https://www.audityourstore.com/cro-guides/ecommerce-homepage-optimization/) · [Ecom Design Pro · Homepage Layout 2026](https://ecomdesignpro.com/ecommerce-homepage-layout/) · [ConvertCart · High-Converting Homepages](https://www.convertcart.com/blog/high-converting-ecommerce-homepage)。

---

## 2. 本席探活证据（编辑器）

### 2a. 首轮（≈01:38–01:45）— P0 撞车

| 项 | 结果 |
|----|------|
| theme_id=3 编辑器 | **HTTP 500** JSON（`isHotCacheBagPrimePendingForCurrentFiber`） |
| 证据 | `channel/evidence-theme-editor-500.md` |

### 2b. 复审（≈01:47+ · WLS 重启后）— 实体预览

| 项 | 结果 |
|----|------|
| 登录 | admin 会话已在后台 Dashboard（账号 admin） |
| URL | `theme_id=3` & `frontend_theme_id=3` & `status=draft` & `interaction_mode=edit` |
| 壳 | 「主题编辑器」+ 主题下拉 **hanfu（已启用）**；预览 iframe `editor_mode=1` |
| 抹 webdriver | navigate `initScript` 定义 `navigator.webdriver=undefined` |
| 已发布店面 `/` | **未用作**本主题验收 |

**预览 iframe 度量（desktop，vh≈644）**：

| 区块 | top(y) | h | 备注 |
|------|--------|---|------|
| sticky header | 0 | ≈242 | 吃首屏预算大 |
| Hero | 242 | 309（≈48vh） | 高度达标区间，但仍占满信任前空间 |
| 信任条 | 551 | 149 | 部分入屏 |
| 精选 featured | **700** | 1118 | **fold 下**；gap≈56px |
| 品类 strip | 1818 | 190 | 矮磁贴高度 OK |
| 特价 deals | 2037 | 632 | ≈**3.16 屏**，远超 ≤1.5 屏 |

**首屏 fold（scrollY=0）**：`foldVisibleProductCards=0`，`foldVisiblePrices=[]`。  
**精选**：4 卡；`products-grid columns-4` 但计算列仅为 **3 列**（305×3）→ 第 4 卡换行；卡高≈456。价签与 Add to Cart / Buy Now 存在。  
**Hero CTA**：同屏双主 CTA「Shop Featured」+「Shop by Occasion」（多 slide 重复）。  
**顺序**：Hero → 信任 → 精选 → 品类 → 特价 = **orderOk:true**。  
**品牌**：长按「長安漢服 / Chang'an Hanfu」；Hero 文案「A Garden in Ink」等水墨叙事。  
**叠层（店面）**：侧栏/迷你购物车关闭态；可见浮层：店内音乐条、客服球、社交 float（不挡主 CTA 路径，但占注意力）。  
**编辑器壳**：Event Monitor 黄条「本页尚未加载 Pixel 引导…」——属分析面提示，记入建议席，不替代商城感 P1。

截图：本回合 Browser 视口（编辑器壳 + iframe 预览；含精选货架滚动态）。

---

## 3. 问题清单（TopN · 优先级）— 实体复审 verdict

### P0 — HF-ED-P0-01

**状态：编辑器已可进（运营侧不再阻塞）**；正式关项仍交 **后端** DoD 核验（见 `pm-p0-followup.md`）。  
本席不代关。

### P1 — 实体预览 verdict

| ID | 项 | verdict | 证据摘要 |
|----|-----|---------|----------|
| HF-ED-P1-01 | 首屏商品化 | **FAIL** | fold 内 0 张带价卡；精选 top=700 > vh=644；需再压 chrome/Hero 或上移精选入屏 |
| HF-ED-P1-02 | 转化路径顺序 | **PARTIAL** | 顺序正确；特价 ≈3.16 屏（目标 ≤1.5）→ **要开发：上移 deals / 压缩中间段** |
| HF-ED-P1-03 | 货架密度与角标 | **FAIL** | `columns-4` 实渲 3 列致第 4 卡换行；卡过高；特价有 -15% 角标（此点 OK） |
| HF-ED-P1-04 | 品牌气质 | **PASS（带微调）** | 水墨叙事在；Hero **双主 CTA** 违背「一个主 CTA」→ 要收敛为单主 CTA |

### P2 — HF-ED-P2-01 products 草稿

**本回合未完成**（Chrome DevTools 连接超时）；请 PM 安排施工后或本席 resume 再抽检。不因此放行首页 P1。

### P3 — 可见串

若施工收敛 Hero CTA / 货架标题文案 → **必须**拉 **翻译工程师**。

---

## 4. supported_countries

**N/A（本波）**  
理由：主题编辑器视觉/布局商城感；不改运输/支付/政策国别面。

---

## 5. policy_notes

信任条含 Free shipping / Refunds / Secure payment——本波不改宣称则合规面 N/A；若改文案须勾政策页·FAQ·Cookie 并拉翻译。非律师意见书。

---

## 6. design_brief（给原型 / UI / 主题）

- 保持长安汉服水墨；色服从 ink。  
- **首屏必须看见货**：折叠 sticky 顶栏预算或压至 ≤160px；Hero 目标 ≤40vh **或** 信任条后精选第一行价签进入首屏。  
- Hero：**仅一个**主 CTA（建议保留「Shop Featured / 即刻寻衣」类； Occasion 降为文字链）。  
- 精选：真 1 行×**4** 列（修 grid）；卡高压缩使一行含价+主 CTA 可入一屏。  
- 特价：紧接品类后、尽量 ≤1.5 屏；中间勿插入过高内容块。

---

## 7. 要 / 不要开发（领域决策定稿）

### 要开发（dev_ask）

| ID | 要开发什么 | 成功标准 | 建议席 |
|----|------------|----------|--------|
| HF-ED-P0-01 | （后端正式关项）WLS HotCache bag prime 根因+防回归 | DoD 见 PM；运营侧已可进编辑器 | 后端 ± 性能检查 |
| HF-ED-P1-01 | 首屏可见 ≥4 带价卡 + ≥1 ATC/Buy | scrollY=0 预览 iframe 内可数 4 价签 | 主题 · 部件 · 前端 · 原型 · UI |
| HF-ED-P1-02 | 特价上移至 ≤1.5 屏；保持槽序 | deals top/vh ≤1.5 | 同上 |
| HF-ED-P1-03 | 精选真 4 列一行；压缩卡高 | columns-4 实渲 4 列；一行完整含价 | 同上 |
| HF-ED-P1-04 | Hero 收敛为单一主 CTA | 活跃 slide 仅 1 个实心主按钮 | 主题 · 部件 · 前端 · **翻译工程师**（改串时） |
| HF-ED-P2-01 | products 草稿抽检（待做） | 列表不崩、气质一致 | 同上 |
| HF-ED-ANALYTICS | （建议）预览缺 Pixel 引导提示 | 编辑器草稿预览可入流或明确 editor 豁免 | **数据分析**（非阻断商城感） |

### 不要开发（本波）

- 不要发布 hanfu 到前台激活面。  
- 不要用 Default `/` 店面当验收。  
- 不要重做整站品牌色板 / 另造紫粉霓虹。  
- 不要为「商城感」拆掉信任条或清空水墨 Hero（只压缩与排序）。  
- 不要本席指挥施工席；一律经 PM 组队。

**顾问拍板**：继续 **压矮 chrome+Hero + 信任下精选入屏**（不改嵌卡方案，除非施工证明嵌卡更能一屏达标）。

---

## 8. suggested_seats（给 PM · 本波施工）

1. **主题开发工程师 · 部件开发工程师 · 前端 · 原型 · UI**（P1-01～04）  
2. **翻译工程师**（Hero CTA / 可见串若改）  
3. **后端**（P0 正式关项，并行不阻塞 P1）  
4. （建议）**数据分析**（Pixel 引导提示）  
5. 其后：测试（UI+原型过签后；验收面仍 theme_id=3 编辑器）

---

## 9. stance / result

- `stance`：同意立刻组队做 P1 商城感施工；否决「顺序已对=验收通过」。  
- `result=escalate`  
- `notify_pm: true`  
- `@项目经理：请立刻组队解决`  
- `@项目经理：本席已交付/上报，请检查并更新 SESSION`

paths_changed（业务码）：**无**。
