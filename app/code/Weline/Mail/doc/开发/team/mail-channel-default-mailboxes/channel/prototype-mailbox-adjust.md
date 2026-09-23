# 原型调整 · 企业邮箱「邮箱」页

席位：`Team:原型:` · 2026-09-22  
client_session_id：`team-mail-ui-proto-20260922`  
审图权威：[`ui-shentu-mailbox-20260922.md`](./ui-shentu-mailbox-20260922.md)（E/F/G 均 fail）  
主模板：`Mail/view/templates/Backend/Index/enterprise.phtml`（`$view === 'mailbox'`）  
对照 throwaway：`Mail/view/email/_prototype/mailbox-variants.html`（**PROTOTYPE · 禁止当生产**）

> 问题：企业邮箱「邮箱」页信息架构过碎，主 Tab / 文件夹 / 写邮件 CTA / 双栏空态层级撞车，店主不知道下一步做什么。

MCP：本席尝试 `prepare_project` 未连通；已宿主 Read `AI硬规则索引.md` + `prototype/UI.md` + `weline-theme-development` 薄镜像；主题 Token / `w-*` 优先，禁止通用 SaaS 私造。

---

## 现状 IA 诊断（对齐审图）

| 层 | 现状 | 问题 |
|---|---|---|
| 页头 | 自造 `mail-enterprise__hero` + 硬编码 `Inter` | 未用 `w-backend-page` / `w-backend-page__heading` |
| 主 Tab | `mail-enterprise__tabs` 描边块 | 像第二套按钮条，非 `w-tabs` soft |
| 工具条 | 邮箱 select 与「收件箱/已发送」分两端 | 文件夹复用主 Tab 样式 → 视觉撞车 |
| 写邮件 | `details/summary` 卡片 | ▶ 播放感；主 CTA 隐藏；与工作区脱节 |
| 地址重复 | 列表卡片头再写一遍邮箱 | 工具条已选当前邮箱，冗余 |
| 双栏空态 | 左「暂无邮件」+ 右「选择一封」各一块空白 | 无行动；第二空白无信息增量 |
| Stalwart | 仅 `w-badge` warning | 无「去配置」行动链 |

**禁止落地**：平行第二套 inbox（`index.phtml` mailView）视觉语言；以 enterprise 单页为准统一。

---

## 推荐信息架构（落地目标）

```
w-backend-page
├── heading：标题 + 说明 | badge +（未就绪时）链到「域名与 DNS」
├── 主导航 w-tabs soft：邮箱* | 用户与账号 | 域名与 DNS   ← 唯一一级导航
└── mailbox 面板
    ├── w-toolbar（单行）
    │   ├─ 左：当前邮箱 w-field + w-select（选项文案：邮箱地址；勿强调「用户 #N」）
    │   ├─ 中：文件夹 soft segment（收件箱 | 已发送）← 次级，体量小于主 Tab
    │   └─ 右：主 CTA w-button primary「写邮件」→ 打开 compose（dialog / panel）
    ├── compose（默认收起；?compose=1 或 CTA 打开）
    │   └─ Weline.UI.dialog 或 w-card 面板（禁止 details/summary 当主入口）
    └── 工作区 w-card（一体）
        ├── 有邮件：左列表 + 右阅读（既有双栏）
        └── 空：单块 w-empty（跨栏）——标题 + 说明 + 次 CTA「写第一封」+ 链「去用户与账号」
```

### 四块怎么改（硬）

1. **主 Tab**  
   - 仅三视图：`mailbox | accounts | domains`。  
   - 类名：`w-tabs` + `w-tabs__list[data-variant=soft]` + `w-tabs__tab`（路由 `<a>` 可当 tab，对照 Smtp Config / Seo Account）。  
   - 禁止：描边圆角块再当「伪 Tab」；禁止文件夹复用同一套。

2. **工具条**  
   - 一律 `w-toolbar` / `w-cluster` 横排紧凑。  
   - 左账号 · 中文件夹 · 右写邮件；窄屏再叠成两行（账号+CTA / 文件夹）。  
   - 文件夹用 **更小** soft tabs 或 `w-button` group `data-variant=outline` + `aria-current`，视觉权重 < 主 Tab。

3. **写邮件 CTA**  
   - 工具条右侧 **唯一主按钮**「写邮件」。  
   - Compose 进 dialog（优先）或同页 `w-card` 面板；`details/summary` 仅可作高级折叠，**不得**当主入口。  
   - 空态次 CTA「写第一封」触发同一 compose 开法。

4. **双栏空态**  
   - **合并为单一 `w-empty`**（SEO Embed / SystemConfig 范式）：图标 +「这个文件夹暂无邮件」+ 一句说明 + 按钮区。  
   - 有邮件但未选中时，右栏再显示「选择一封邮件查看正文」；**列表也为空时不要再并排第二块空洞**。  
   - 列表头不要重复邮箱地址；可用文件夹名（收件箱/已发送）即可。

5. **Stalwart badge**  
   - `w-badge` warning 旁加 `w-button`/`a` outline：「去域名与 DNS」或「配置管理连接」（链到 `view=domains`）。

6. **文案**  
   - Select 选项：优先显示名或纯邮箱；`用户 #1` 可降到 title/次行或省略（店主无意义）。  
   - 源串保持简中；中英 CSV 由翻译席/前端落地时补（本席不改业务 PHP）。

---

## Variant 要点（2 个 · 结构不同）

### Variant A —「工具条三区」（**推荐默认落地**）

- **结构**：页头 + 主 soft tabs + **一行 toolbar** + 一体工作区。  
- **主 affordance**：右上「写邮件」。  
- **空态**：工作区单卡 `w-empty`。  
- **为何优先**：改动面小（仍在 enterprise.phtml mailbox 分支）、对齐 Marketing/Smtp/Seo 后台密度、不引入侧栏复杂度。  
- **对照**：throwaway `?variant=A`。

### Variant B —「文件夹侧轨」

- **结构**：主 soft tabs 下为 `grid：左窄轨 + 右工作区`。  
  - 左轨：收件箱 / 已发送（竖排 `w-tabs` 或列表按钮）。  
  - 顶条仅：当前邮箱 select +「写邮件」。  
- **空态**：仍单块 `w-empty` 占右工作区。  
- **差异点**：文件夹从「横排伪 Tab」变成明确次级导航；适合邮件夹未来变多。  
- **成本**：布局改动更大；若产品近期只有两夹，A 更划算。  
- **对照**：throwaway `?variant=B`。

**本席裁定**：验收与前端落地以 **Variant A** 为默认；若产品确认文件夹会扩展（草稿/垃圾等），再切 B。UI 席可混搭「A 的 toolbar + B 的未来扩展钩子（预留 rail 槽）」。

---

## `w-*` 映射清单（给 UI / 前端 / 主题）

| 区域 | 必用 | 禁 |
|---|---|---|
| 页壳 | `w-backend-page` · `__heading` · `__title` | 私造 `mail-enterprise` 当页壳；硬编码 `Inter` / #hex |
| 主导航 | `w-tabs` + `data-variant="soft"` | 与文件夹同级描边块 |
| 工具条 | `w-toolbar` / `w-cluster` · `w-field` · `w-select` · `w-button` | 两端漂浮无容器 |
| Compose | `Weline.UI.dialog` 或 `w-card` 面板 | `details/summary` 作主 CTA |
| 空态 | `w-empty` +（可选）`w:icon` | 双栏各一块无行动空白 |
| 警告 | `w-badge` + 行动链 | 只 badge 无下一步 |
| 色/间距 | `--weline-theme-*` / `--weline-space-*` | 私造色板 / px 阶梯 |

对照页：`Smtp/view/Backend/Config.phtml`（soft tabs）、`Seo/.../Embed/index.phtml`（`w-empty` CTA）、`Marketing/.../winback`（`w-backend-page`）。

---

## 非本席范围

- **不改** Controller / 发信 / Stalwart 业务 PHP。  
- **不写** 生产 `enterprise.phtml` 最终样式（可留 throwaway）。  
- UI / 前端 / 主题席按本方案改模板与 Token；测试席 WB-OP 验空态与写邮件入口。

---

## 签收提示（给 PM）

- [ ] 主 Tab 与文件夹视觉权重分离  
- [ ] 写邮件为工具条主 CTA（非 summary）  
- [ ] 空列表单块 `w-empty` + 次 CTA  
- [ ] Stalwart 警告带行动链  
- [ ] 无硬编码 Inter / 平行 inbox 皮肤  

**notify_pm: true**

@项目经理：本席已交付/上报，请检查并更新 SESSION。  
交付物：本文件 + `Mail/view/email/_prototype/mailbox-variants.html`（PROTOTYPE）。推荐落地 **Variant A**。请唤醒 `Team:UI:` / `Team:前端:`（及按需 `Team:主题开发工程师:`）按映射改 `enterprise.phtml` mailbox 视图；本席不扮演最终码落地。
