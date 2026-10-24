# 指令：UGC 问答聚合（游客真实经验版块）

**MCP：跳过**（`content_ops_skills_skip_mcp`）。宿主 Read 本文件 + `Blog/doc/ai/skills/weline-blog-article/SKILL.md`。

**触发词（任一即执行）**

- `UGC问答`
- `UGC聚合`
- `游客问答`
- `游客经验`
- `UGC`
- `E-E-A-T`
- `EEAT`
- `真实经验版块`
- `ugc aggregation`
- `traveler Q&A`

**硬触发**：为目的地/线路/博客页生成「游客真实问答」内容、把站外真实讨论聚合为站内 E-E-A-T 信号时，执行本指令。

---

## 为什么做这个（E-E-A-T 信号原理）

Google 的 E-E-A-T（Experience, Expertise, Authoritativeness, Trust）里最稀缺的是第一个 **E（Experience）**——2022 年 12 月加进来的，「作者/内容是否有一手体验」。AI 搜索引擎（Google AI Overviews、ChatGPT、Perplexity）引用内容时同样偏爱带一手经验的内容。

| 信号 | 普通内容页 | UGC 问答版块 |
|---|---|---|
| Experience | 作者自称"我去过" | 10 条真实游客的一手叙述，含具体日期、花费、排队时长 |
| Expertise | 写手研究得来 | 多人交叉印证的细节（几点入园人少、哪些票不值） |
| Authoritativeness | 站点权威 | 引用原社区讨论（Reddit/TA）带出处，可回查 |
| Trust | 免责声明 | 有问必答、过时信息被纠正的痕迹、更新日期 |

**一手经验是编不出来的**——写手生成的内容天然缺这个 E。聚合真实社区讨论是唯一可规模化的路径。

---

## 数据来源（按可信度排序）

| 来源 | 抓取方式 | 优势 |
|---|---|---|
| Reddit（r/travel_china, r/chinatravel, r/ChinaTravelTips, r/travel） | `reddit` 技能（公开 JSON API，免 key） | 一手经验密度最高，有投票分（upvotes=可信度权重） |
| TripAdvisor 论坛 | `firecrawl-scrape` | 游客专属，结构化（城市→话题） |
| 站内已有 `requesthunt` 报告 | 直接 Read `opc/dispatch/*/evidence/` | 已挖好的需求，直接复用 |

**禁止**：自答自问、AI 生成"游客"发言、把推文当经验、把营销帖当 UGC。所有内容必须能回查到原文 URL + 抓取日期。

---

## 工作流（六步，全部真抓）

### 1. 选目标页（OPC 给定或按 GSC 排序）

从 GSC 拿「曝光高但点击低 / 排名 5-15 位」的目的地页，或每日运营给的目标。每次处理 **1 个目的地**，不批量。

### 2. 抓取（真上网，禁造数）

```
reddit 技能: 搜 "site:reddit.com {目的地英文名} {体验关键词}"
  → 筛选: 最近 24 个月、upvotes≥10 或评论≥5、非广告
firecrawl-scrape: TripAdvisor 论坛 {目的地} 版
```

每条记录字段：`{source_url, 抓取日期, 作者身份线索, upvotes, 发布时间, 原文摘录(≤80词), 语言}`

### 3. 审核过滤（合规红线）

- ❌ 剔除：广告/导流、政治敏感、与健康签证等 YMYL 断言（除非有官方源交叉）
- ✅ 保留：具体体验、可验证细节（价格/时间/路线）、有分歧的警示
- 去标识：不引用用户名，写 "a traveler on Reddit (2024-10)"
- 版权：摘录 ≤80 词 + 链接回原帖（fair use 范围），禁止整段复制

### 4. 改写整合（不是翻译，是编辑）

- 按主题分组：门票/排队/交通/避坑/最佳时间
- 每条注明：来源 + 时间 + （如有）佐证或反驳
- 有分歧处如实呈现："Two travelers disagree on X (2024-03 vs 2024-08) — likely seasonal"
- 站方补充：用官方源（景区官网、12306）交叉，标注 `[Official]`
- 末尾：`Last verified: {日期} · Sources: N links`

### 5. 发布到目的地页（新增版块，不动现有结构）

在目的地详情页「实用信息」之后插入版块：

```
## Traveler Q&A — Real Experiences
> Curated from Reddit & TripAdvisor discussions (Oct 2024). Links to original threads.
```

- 结构化数据：每条用 FAQPage schema（`Question`/`Answer`/`acceptedAnswer`）
- 内链：问答中提到的其他目的地/线路 → 站内链接
- 不改现有模板结构，只追加版块

### 6. 交付物

| 产物 | 位置 |
|---|---|
| 抓取原始记录 JSON | `opc/dispatch/<run_id>/evidence/ugc-raw-{slug}.json` |
| 审核后问答集 | `opc/dispatch/<run_id>/artifacts/ugc-curated-{slug}.md` |
| 目的地页版块 | 站点公开 URL |
| 回执 | 状态 + 版块 URL + 来源数 + 信号自检 |

---

## E-E-A-T 自检（发布前必过）

- [ ] 每条问答可回查原文 URL + 日期
- [ ] 无编造的用户发言（抽 3 条与原帖比对）
- [ ] 分歧如实呈现，未替用户下结论
- [ ] 有 `Last verified` 日期
- [ ] FAQPage schema 校验通过（Rich Results Test）
- [ ] 无 YMYL 断言无官方源
- [ ] 与页面已有内容不重复（去重）

---

## 与每日运营的关系

- **不替代**每日 5 篇博客（那是长期内容线）
- **互补**：每日运营的 `R3_demand` 挖痛点时可同步输出 UGC 候选目的地；本技能在单独回合跑（每目的地约 20-40 分钟）
- **频率建议**：每周 2-3 个头部目的地（GSC 排名 5-15 位的），跑 2-3 个月形成规模
- **效果观察**：发布后 2 周看 GSC 该页曝光/点击变化，2 个月看 AI 引用（Perplexity/ChatGPT 问该目的地是否引用本站）

---

## 红线

- 禁止把聚合内容冒充"本站用户评论"（虚假 UGC = Google 打击对象）
- 禁止移除原文出处
- 禁止对 YMYL（签证/健康/安全）做无源断言
- 图片：UGC 截图需原帖开放许可；无许可用文字摘录
- 本指令不改框架码，版块渲染问题交 `R_obc_pm`
