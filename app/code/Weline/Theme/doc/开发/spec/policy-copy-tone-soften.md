---
status: ready_for_align
work_kind: feature
feature_slug: policy-copy-tone-soften
module: Weline_Theme
fe_be_scope: frontend_copy+i18n
updated: 2026-09-23
session_path: ../session/policy-copy-tone-soften.md
clarify_status: clarified
align_freeze_ready: true
advisor_brief: ../team/policy-copy-tone-soften/meetings/电商顾问-ops-brief.md
recommendation: A
---

# 政策页文案语气软化（policy-copy-tone-soften）

> **对齐冻结**：顾问 brief 已并入；stance=同意改语气 / 保留合规 / 否决改回默认无理由；与 UC 无冲突 → `align_freeze_ready=true`。施工范围锁定 **recommendation=A**（只改政策源串 + Theme zh/en CSV + 默认站词典；FAQ/顶栏仅冲突时同改）。

## 澄清记录

| # | 问题 | 来源 / 当前结论 |
|---|------|-----------------|
| Q1 | 改什么 | 用户：「合规页面政策写得太生硬了，运营看下怎么修改，翻译一起改」→ 语气去生硬化，非推翻合规。 |
| Q2 | 合规底线 | 前波 `storefront-policy-compliance` + 顾问 brief：隐私跨境告知、Cookie 非必要先同意、EU 冷静期底线、配送时效非保证等**必须保留（可软写）**。 |
| Q3 | 退换口径 | 商家友好退换**硬保留**（`merchant-leaning-returns.md`）；顾问否决改回「默认无理由」卖点。 |
| Q4 | 范围页 | privacy / term-condition / refund / cookie / shipping / disclaimer / accessibility；顶栏/FAQ/`default.phtml` 仅冲突时同改。 |
| Q5 | 顾问 brief | **已落盘**：`../team/policy-copy-tone-soften/meetings/电商顾问-ops-brief.md`；通道 msg-003 escalate + msg-004 PM 采纳 A。 |
| Q6 | 范围选项 | A 推荐采纳；B 视觉改版另立项；C 只改 en 否决（违反中文 source）。 |

## work_kind / fe_be_scope

| 字段 | 值 |
|------|-----|
| work_kind | `feature`（店面政策页用户可见文案与多语展示行为） |
| fe_be_scope | **前端文案 + i18n**（改 `layouts/policy/*.phtml` 内 `WidgetI18n::label('简中源串')` + Theme 中英 CSV + 默认站其它 locale 系统词典）；**不改** Controller 路由逻辑、布局壳 CSS/HTML 结构、Cookie banner JS、后端策略引擎 |

## 背景

- 访客/运营反馈：政策页「一、二、三 / 请您仔细阅读 / 构成协议」等公文腔过硬，不像可逛可买的汉服商城客服说明。
- 前波合规对齐已落地法定底线披露；本波只做**语气软化**与语气一致的多语同步。
- 商家友好退换口径（质量导向、不主推销无理由）必须在退款/条款相关段落中保留，不得为「好说话」改回宽无理由店内承诺。
- 品牌语气（顾问）：店员把规矩讲清楚——温和、具体、可执行；首屏先答「和我有什么关系」。

## 目标 / 非目标

### 目标

1. 各政策页正文语气更友好、可读，仍保留合规与商家友好退换底线。
2. 源串仍为简体中文；`en_US` 与默认站至少一个非中英 locale 展示目标语译文（非中文 source 原样）。
3. 改写清单与成功标准以电商顾问 brief 为准；施工席不得自行发明削弱法定披露的措辞。

### 非目标

- **不改**亚马逊式目录结构 / `amazon-policy` 布局壳（Hero、sticky TOC、双栏卡片）——对应否决选项 B。
- **不削弱**法定披露（跨境告知、Cookie 同意、强制性冷静期弱披露等）。
- **不做** Cookie banner / 同意横幅 JS 或新交互控件。
- **不新建** Event / QueryProvider / Widget 扩展点（现有 WidgetI18n + 模块 CSV + 系统词典足够）。
- **不改** SESSION 文件（仅 PM 维护）。
- **不对**仅改 `en_US`、不动中文源串（选项 C 已否决）。

## 角色

- **店面访客**：打开政策页阅读规则，切语言看译文。
- **电商顾问（运营）**：定改写 brief、`supported_countries`、ops 成功标准与 `ops_acceptance`（issuer 盯验收）。
- **主题开发工程师**：按 brief 改 phtml 源串与 Theme `zh`/`en` CSV（plan theme-copy）。
- **翻译工程师**：默认站全语种（中英 CSV + 其它 locale 词典）并抽检（deps 等 theme-copy closed）。

## 用户故事

1. As a 店面访客, I want 政策页读起来像友好客服说明而不是公文, so that 我愿继续逛买且仍能理解规则底线。
2. As a 访客（非中文 locale）, I want 政策正文显示我所选语言, so that 英文站/其它语种站不露出大段中文公文。
3. As a 运营（电商顾问）, I want 改写后仍符合合规与商家友好退换口径, so that 店面可运营验收通过。

## EARS（验收标准）

1. WHEN 访客打开 `/policy/privacy`（及 terms/refund/cookies/shipping/disclaimer/accessibility 等价路径）THEN 页面 SHALL 展示语气软化后的正文，且 SHALL NOT 删除顾问 brief「保留要点」中的法定底线披露。
2. WHEN 退款/条款页涉及退换规则 THEN 正文 SHALL 保持商家友好退换口径（质量为主；个人原因原则上不支持；法定例外仅弱披露），SHALL NOT 恢复宽无理由店内主推。
3. WHEN 访客以 `en_US` 打开同一政策页 THEN 可见正文/标题/目录 SHALL 为英文译文（专有名词除外），SHALL NOT 大段保留中文 source。
4. WHEN 访客以默认站已启用的至少一个非中英 locale 打开同一政策页 THEN 可见正文 SHALL 为目标语译文（经系统词典），SHALL NOT 等于中文 source 原样。
5. IF 电商顾问 brief 给出「禁止项/绝对宣称」THEN 系统交付文案 SHALL NOT 包含该宣称（含顶栏「售后无忧」「全球包退」等）。
6. WHEN 七页政策首屏/导语渲染 THEN SHALL NOT 以「请您仔细阅读 / 构成协议 / 充分理解并同意」开场（ops_acceptance 条 1）。

## 用例（喂 e2e / Browser）

### UC-1 访客读软化后的隐私政策（主成功）

| 字段 | 内容 |
|------|------|
| id | UC-1 |
| 名称 | 隐私政策语气友好且合规 |
| 角色 | 店面访客 |
| 前置 | 主题已按顾问 brief 改写并 collect；默认站 zh 可访问 |
| 主成功步骤 | 1. 打开 `/policy/privacy` 2. 阅读 Hero 导语与首节 3. 抽检境内为主+境外支付/物流告知仍在 |
| 备选/异常 | 若 ops 布局 `content` 覆盖默认正文 → 以运营配置为准，本 UC 标 N/A 并记 SESSION |
| 期望结果 | 店员说明体开场；合规保留要点仍可见 |
| 映射 acceptance | e2e/WB-OP：policy-privacy-tone；ops_acceptance#1+#2 |
| 电商主路径 | **是** → **可进对齐冻结**（顾问约束已并入） |

### UC-2 退款政策保留商家友好口径

| 字段 | 内容 |
|------|------|
| id | UC-2 |
| 名称 | 退款页软化但不放宽无理由 |
| 角色 | 店面访客 |
| 前置 | 同 UC-1；refund 已改写 |
| 主成功步骤 | 1. 打开 `/policy/refund` 2. 断言质量问题导向与个人原因通常不支持仍在 3. 强制法例外弱披露仍在 |
| 备选/异常 | 试图改回宽无理由 → 顾问否决阻断；本规格已锁定否决 |
| 期望结果 | 口径与 `merchant-leaning-returns.md` + brief 一致；语气更客服化 |
| 映射 acceptance | e2e/WB-OP：policy-refund-tone-ops；ops_acceptance#2+#3 |
| 电商主路径 | **是** → **可进对齐冻结** |

### UC-3 切 en_US 见英文

| 字段 | 内容 |
|------|------|
| id | UC-3 |
| 名称 | en_US 政策正文为目标语 |
| 角色 | 店面访客 |
| 前置 | Theme `en_US.csv` 已补齐改写后 source 的英文译文并 `i18n:collect` |
| 主成功步骤 | 1. 打开 `/en_US/policy/privacy`（或站内等价） 2. 抽检标题与至少 2 段正文为英文 |
| 备选/异常 | 路径 locale 与 RequestContext 不一致时 WidgetI18n 以路径段为准 |
| 期望结果 | 无大段中文正文 |
| 映射 acceptance | WB-OP：policy-i18n-en；ops_acceptance#5 |

### UC-4 非中英 locale 见目标语

| 字段 | 内容 |
|------|------|
| id | UC-4 |
| 名称 | 非中英 locale 词典生效 |
| 角色 | 店面访客 |
| 前置 | 默认站该 locale 已启用；改写后源串已入系统词典并发布 |
| 主成功步骤 | 1. 打开 `/{locale}/policy/refund` 2. 抽检至少标题+1 段 ≠ 中文 source |
| 备选/异常 | 该 locale 未启用 → 换默认站实际已选非中英语种 |
| 期望结果 | 目标语展示；模块 `i18n/` 下无该 locale 的模块 CSV |
| 映射 acceptance | WB-OP：policy-i18n-non-zh-en；ops_acceptance#5 |

### UC-5 顾问运营验收（店面）

| 字段 | 内容 |
|------|------|
| id | UC-5 |
| 名称 | ops_acceptance |
| 角色 | 电商顾问 |
| 前置 | 主题+翻译施工 closed；测试/抽检证据可读 |
| 主成功步骤 | 顾问按下方六条指针亲自禁缓存 Browser 抽检，写 `ops_acceptance=pass\|fail` |
| 备选/异常 | fail → 附缺口规格 escalate 返工；缺 pass → 禁止汇审完成 |
| 期望结果 | 六条全部满足 |
| 映射 acceptance | 汇审门禁：ops_acceptance |

## 顾问约束（全文并入 · 权威 brief）

> 权威全文：[`../team/policy-copy-tone-soften/meetings/电商顾问-ops-brief.md`](../team/policy-copy-tone-soften/meetings/电商顾问-ops-brief.md)  
> 通道：msg-003 escalate；msg-004 PM 采纳 recommendation=A。  
> **冲突检查**：无顾问否决阻断项与既有「不削弱披露 / 保留商家友好退换 / 不改壳」目标冲突 → 允许冻结。

### stance

**同意改语气；保留合规要点；否决改回「默认无理由」卖点。**

| 项 | 决定 |
|----|------|
| 语气软化 | **同意** — 去公文腔，改成汉服古风商城「店员把规矩讲清楚」 |
| 法定披露 | **必须保留** — 隐私跨境告知、Cookie 非必要先同意、EU 冷静期底线披露、商家友好退换主规则 |
| 商家友好退换 | **硬保留** — `storefront-policy-compliance/channel/merchant-leaning-returns.md`；店内主规则=质量问题导向；无理由**不是**营销卖点 |
| 绝对宣称 | **禁止回潮** — 顶栏/营销勿再写「售后无忧」「全球包退」等 |

### supported_countries

| 维度 | 内容 |
|------|------|
| 结账可达子集（本波对齐） | AT,AU,BE,BR,CA,CH,CN,DE,DK,ES,FI,FR,GB,HK,IE,IT,JP,MX,NL,NO,PL,PT,SE,SG,US |
| 本机活跃国码 | RegionService::getCountries() ≈250 活跃非测试（含 CN/US/主要 EU） |
| 最低必查 | **CN** + **EU**（DE/FR/IT/ES/NL/IE 等）+ **US**（及同表 CA/AU/GB） |
| 分国保留要点摘要 | CN：PIPL 跨境显著告知；EU：14 日冷静期底线披露（店内可质量导向但不得取消强制权）+ Cookie 非必要先同意 + plain language；US：清晰真实披露 / CCPA opt-out 语境；ALL：白话摘要+可扫读 |

### ops_notes / design_brief / 逻辑约束

| 字段 | 内容 |
|------|------|
| ops_notes | 人格=店员讲规矩；可逛可买；禁止恐吓式拒客、浏览即全盘推定同意、顶栏绝对营销；章节优先白话小标题 |
| design_brief | **N/A**（本波不改视觉壳 / Token） |
| 逻辑约束 | ①语气软化≠削弱法定披露 ②merchant-leaning-returns 口径不可逆 ③改用户可见串须翻译工程师 ④禁改布局壳；禁顾问写码 ⑤冻结会须具备 stance + supported_countries + 本节 |
| suggested_seats | 主题开发工程师、翻译工程师（必要时前端） |
| recommendation | **A** |

### 逐页保留要点摘要（施工须对照 brief 全文软句/禁止项）

| 页 | 保留要点（摘要） | 禁止（摘要） |
|----|------------------|--------------|
| privacy | 收集/使用/共享；**境内为主+境外支付物流告知**；权利；未成年；Cookie 交叉；更新联系 | 删境外告知；「绝不跨境」「保证永不泄露」 |
| term-condition（及 /terms） | 服务说明、账户、违法禁令、交易与 IP、联系 | 浏览即全盘同意；恐吓式拒客首屏 |
| refund | **商家友好退换全文精神**；质量/错发/运输损坏；个人原因通常不支持；强制法例外；回程费用 | 改回七日/十四日无理由卖点；删强制法例外 |
| cookie | 类型；**非必要须先同意**；浏览器管理；与隐私关系 | 删同意句；继续浏览=同意全部 Cookie |
| shipping | 中国发货；时效参考非保证；清关税费；签收查验；包邮范围 | 「保证 X 日必达」；包邮含关税/回程 |
| disclaimer | 中断可能、第三方边界、合法责任限制 | 用免责否定质量售后或强制消费者权 |
| accessibility | 持续改进、已知限制、反馈渠道 | 未验证「完全符合 WCAG X」 |
| 关联面 | 顶栏克制「国际配送，售后有保障」；FAQ 与退换/时效一致；default 壳恐吓句若在用则同步软化 | 「无忧/包退/全球无理由」 |

**非过硬、必须保留的合规句型（可软写，不可删）**：隐私 4.2 境外必要共享告知；Cookie 非必要同意后再启用；退换质量为主+强制法例外+回程费用；配送时效非保证、关税默认收件人承担。

### ops_acceptance 六条指针（UC-5 / 汇审门禁）

施工 closed 后，电商顾问亲自禁缓存 Browser（或等价）抽检，**全部满足才 pass**：

1. **语气**：七页政策首屏/导语不再以「请您仔细阅读 / 构成协议 / 充分理解并同意」开场；读感像店员说明。
2. **合规未回退**：隐私境外告知、Cookie 同意句、退换「质量为主+强制法例外」、配送「时效非保证」均仍在且语义完整。
3. **商家友好口径**：任何页/FAQ/顶栏**未**把「无理由退货」写成默认店内福利。
4. **禁止项**：无「售后无忧」「保证 X 日必达」「绝不泄露」等绝对宣称。
5. **多语**：默认站至少抽检 `en_US` + 1 个非中英语种，政策关键句为目标语（非中文 source 残留）。
6. **古风商城感**：政策页仍可逛可读，不破坏现有布局壳；仅文案气质达标（本波不验收视觉改版）。

`ops_acceptance=pending` 直至上述签收；缺签收不得汇审宣称完成。

### 前波口径引用

- 合规整改：`../team/storefront-policy-compliance/channel/policy-compliance-remediation.md`
- 商家友好退换：`../team/storefront-policy-compliance/channel/merchant-leaning-returns.md`

## 框架机制映射（不新建扩展点）

| 意图 | 选用机制 | 路径 / 契约 | 备注 |
|------|----------|-------------|------|
| 政策页路由与布局选择 | 现有 Frontend Controller | `Theme/Controller/Frontend/Policy.php` | 白名单 layout；本波不改路由 |
| 店面布局渲染 | Theme layout phtml | `view/theme/frontend/layouts/policy/*.phtml` | 改 `WidgetI18n::label('…')` 源串 only |
| 文案解析 | WidgetI18n → TranslationResolver | `Theme/Helper/WidgetI18n.php` | 中文源串；路径 locale 可覆盖 RequestContext |
| 模块中英词条 | 模块 i18n CSV | `Theme/i18n/zh_Hans_CN.csv` + `en_US.csv` | 改后必 `php bin/w i18n:collect Weline_Theme` |
| 其它默认站 locale | 系统词典 | LocaleDictionary / import + publishLocale | **禁止**模块非中英 CSV |
| 扩展点选型结论 | **无需新建** | 《扩展点选型.md》 | recommendation=A |

### 实现探查摘要（只读）

| 布局文件 | 约 `WidgetI18n::label` 调用数 |
|----------|-------------------------------|
| privacy.phtml | 76 |
| refund.phtml | 65 |
| default.phtml | 66 |
| term-condition.phtml | 60 |
| cookie.phtml | 53 |
| disclaimer.phtml | 53 |
| shipping.phtml | 47 |
| accessibility.phtml | 39 |

店面相关 URL（路径；交付 Host 由本机解析）：

- `/policy/privacy` `/policy/term-condition`（及 `/terms`）`/policy/refund` `/policy/cookie`（cookies）`/policy/shipping` `/policy/disclaimer` `/policy/accessibility`

## 验收要点（DoD 指针）

- [x] 顾问 brief 已并入「顾问约束」且 `align_freeze_ready=true`
- [ ] 主题改写源串后：`php bin/w i18n:collect Weline_Theme`
- [ ] 模块仅维护 `zh_Hans_CN.csv` + `en_US.csv`；无其它 locale 模块 CSV
- [ ] 默认站其它已选 locale：系统词典真实译文 + 店面抽检 ≥1 非中英
- [ ] UC-1…UC-4 证据（Browser/e2e）；**UC-5 ops_acceptance 六条全部 pass** 后方可汇审完成
- [ ] 未改布局壳结构；未做 Cookie banner JS；未削弱法定披露与商家友好退换口径

## 隐形需求摘要

- 源串默认简体中文（`module_i18n_chinese_source_default`）。
- 活跃 locale 必须显示目标语（`active_locale_must_show_target_language`）。
- 用户提及翻译 → 默认站全语种（`user_mentions_translation_all_default_website_locales`）。
- 店面验收须顾问 `ops_acceptance`（issuer_seat=电商顾问）。
- FPC：Policy Controller 标注 fpc；文案变更后按主题/缓存惯例失效。

## 待纠偏 / 开放点

1. ~~顾问 brief~~ → 已并入。
2. `/terms` vs `/policy/term-condition`：施工确认同源串语气同步（非新建路由）。
3. 顶栏/FAQ 冲突串：仅顾问/主题点名冲突时改写（recommendation=A）。

---

**规格已澄清并具备对齐冻结条件（`status=ready_for_align`，`align_freeze_ready=true`）。**
