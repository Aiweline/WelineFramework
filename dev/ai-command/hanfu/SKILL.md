---
name: hanfu-lead-generation
description: >-
  长安汉服（changanhanfu.com）B2B 获客闭环：买家线索挖掘、资格打分、单次个性化触达、
  30 天回复监控、报价澄清、失效升级。
  Triggers: 汉服获客 / 获客巡检 / 找客户 / 买家线索 / 汉服批发询盘 / hanfu lead /
  hanfu wholesale buyer / 获客 / 线索挖掘 / 回复监控.
  站点专属（app/design/Weline/hanfu + changanhanfu.com）。不要套用产品优化/分类修复。
---

# 长安汉服 · B2B 获客闭环（权威）

**站点专属技能。** 服务 `app/design/Weline/hanfu/` 与线上站 `https://changanhanfu.com`。
**不要**套用 `产品优化` / `产品分类修复` / `翻译优化`。

配套指令（触发词入口）：`dev/ai-command/hanfu/获客巡检.md`
运行态数据（不进 Git）：`~/Documents/HanfuLeadOps/`

**触发词（任一即执行）**

- `汉服获客`
- `获客巡检`
- `找客户`
- `买家线索`
- `汉服批发询盘`
- `线索挖掘`
- `回复监控`
- `获客`
- `hanfu lead`
- `hanfu wholesale buyer`

---

## 固定配置

| 项 | 值 |
|---|---|
| 产品线 | 汉服 + 相邻可辩护品类（见下） |
| 站点 | `https://changanhanfu.com` |
| 发件身份 | `contact@changanhanfu.com`（用户「获客」口令指定，自有邮局账户，**权威，不得质疑或替换**） |
| 简报收件人 | `aiweline@qq.com`（站点 `/faq`、`/policy/privacy`、`/policy/term-condition` 三处公示地址） |
| 状态目录 | `~/Documents/HanfuLeadOps/`（`state.json` / `leads.jsonl` / `outbox/`） |
| 时区 | Asia/Shanghai |

> 站点未在公开 HTML 里印 `contact@changanhanfu.com` **不代表它不存在**——域名所有者自建邮局，
> 该邮箱是口令指定的发件人。不要把「未公示」误判成「非官方」并擅自替换身份。

---

## 品类口径（必须逐条判定）

**纳入**（满足任一即合格）

1. 明确求购 hanfu / 汉服 / Chinese traditional clothing / Chinese historical costume
2. 相邻可辩护品类：qipao / cheongsam 旗袍、中式婚礼服（Chinese wedding dress / tea ceremony）、
   武侠或 Cosplay 古装、汉服节 / 文化节 / 高校社团活动批量采购、影视舞台服装租赁
3. 明确寻找「中国服装进口商 / 批发商 / 供应商」，且品类属女装成衣或传统服饰

**排除**：不提品类的泛采购帖、纯消费者零售询价、供应商侧营销页、目录站 / 聚合站、
校服 / 运动服 / 工装等制式服装、和服(kimono) / 韩服(hanbok)——除非买家同时明确要中式服饰

---

## 资格打分（取消「必须出现汉服二字」的一刀切）

每条候选 5 项各 0–2 分，**总分 ≥6 才触达**：

| 轴 | 2 | 1 | 0 |
|---|---|---|---|
| 品类匹配 | 明确汉服 / 中式传统服饰 | 相邻品类 | 泛服装 |
| 采购意图 | 明确求购或找供应商 | 有采购倾向的讨论 | 仅兴趣 |
| B2B 属性 | 企业 / 店主 / 进口商 / 活动主办 | 个人但量大 | 纯消费者 |
| 数量或时间 | 给了量或期限 | 模糊 | 无 |
| 渠道可用 | 公开商务邮箱或主页 | 仅平台私信 | 无公开渠道 |

打分与理由写入 `leads.jsonl`。**<6 分标 `screened_out` 并落盘，此后不得重复重筛**。

---

## 线索源优先级（**不得以通用网页搜索起手**）

通用网页搜索找 B2B 买家，结构性只能返回供应商侧 SEO 页（Alibaba / 1688 / newhanfu / accio）。
2026-10-08 至 10-20 的 200+ 轮零产出，全部来自这个失效模式。

1. **Reddit RSS**（首选）
   - 必须带浏览器 UA：`Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36`
   - 默认 UA 与 `/search.json` 均 403
   - 形态：`https://www.reddit.com/r/<sub>/new/.rss`、`https://www.reddit.com/search.rss?q=<kw>&sort=new`
   - 子版：Hanfu、hanfuglobe、wholesale_suppliers、importexport、Alibaba、ecommerce、smallbusiness、costumes
   - 检索词：hanfu wholesale / hanfu supplier / qipao wholesale / chinese traditional clothing supplier / import hanfu
   - 遇 429 退避 ≥60 秒，不得连续重打同一源
2. **Alibaba RFQ**：`rfq.alibaba.com` 公开搜索（实测可达）
3. **定向网页检索**：每组必须带「买家意向词 + `site:` 限定或引号」
   - 意向词：looking for supplier / sourcing / request for quotation / RFQ / where to buy wholesale / need manufacturer / bulk order / import
   - 限定：`site:reddit.com`、`site:facebook.com`、`site:linkedin.com`、`site:tradewheel.com`、`site:go4worldbusiness.com`
4. **海关进口商名录**（每周一次）：ImportYeti / Volza 公开页；403 记录后跳过，**不得绕过付费墙**

每条命中必须记录：公开 URL、作者 / 企业、国家、发帖日期、**证据原文摘录**、
所需产品 / 数量 / 时间、匹配理由、公开可用渠道。

---

## 分档运行（每小时触发，但不要每小时都做全量）

| 档 | 时点 | 做什么 |
|---|---|---|
| **A · 全量挖掘** | 每天 09:00、21:00 | 线索源 1→4 全跑 + 打分 + 触达 + 汇报 |
| **B · 监控** | 其余整点 | 只查回复、更新状态。**不做新检索，不重取贸易数据，不重读站点条款页** |
| **C · 周复盘** | 每周一 09:00（并入 A） | 额外跑海关名录 + 失效升级判定 |

无法判定档位时，默认 B 档。

---

## 触达规则（每轮最多 1 条，宁少勿多）

- 仅对 ≥6 分且渠道可用的线索做**一次**个性化首次联系
- 只用对方公开提供的**单一**渠道；用对方语言；引用其具体请求；附 `https://changanhanfu.com`
- **发信通道检查**：MailMaster `Account` 表是否含 `contact@changanhanfu.com`，或本会话有无可用发信工具
  - 可用 → 正常发送，并验证发件人、收件人与发送结果
  - 不可用 → 文案写 `outbox/`，`leads.jsonl` 标 `blocked:sender`，
    `state.json` 里把该阻塞标 `reported_to_user=true` 并**只在首次上报一次**，此后不重复唠叨
- 绝不：群发、跨渠道重复、联系已拒绝者、用个人身份顶替、编造价格 / 交期 / MOQ / 库存

---

## 监控（30 天，按线索计，不按巡检周期）

- 每条已触达线索记 `first_contact_at` / `monitor_until` / `channel` / `followup_due`
- 第 7 天仍无回复 → 同渠道发**一次**简短跟进；一旦有回复即停止跟进
- 收到拒绝 / 退订 / 勿扰 → 立即 `status=closed_optout` 并永久停止
- 30 天无回复 → `status=closed_no_reply`
- 报价对话进行中的线索不受 30 天限制，持续到报价交付或买家结束

---

## 报价

- 用 `~/Documents/HanfuLeadOps/quote-catalog-draft.md`；**只写已核实事实**；缺失字段一律 `待确认`
- 当前缺口：批发单价 / MOQ / 生产交期 / 样品政策 / Incoterm / 付款与信用条件 / 报价有效期
- 这些字段缺失时，**只发澄清性提问，不发任何报价数字**

---

## 汇报

- 实质变化 = 新增 ≥6 分线索 / 收到买家回复 / 需用户决策的报价请求 / **新增阻塞项**
- 无实质变化 → 只更新 `leads.jsonl` + `state.json` + 当日 md，**不发邮件**（不发「无变化」通知）
- 发信前必须验证发件人与发送结果；发不出就写清确切原因，**绝不在未发送时声称已发送**

---

## 失效升级（闭环关键，不得省略）

维护 `state.json` 的 `consecutive_zero_runs`（连续 0 合格线索的**挖掘轮**数）：

- 达到 **3** → 本轮必须**更换检索组合**（换子版 / 换语种 / 换意向词），`discovery_strategy` 推进到下一字母，
  汇报写一行「策略 A 连续 3 轮 0 产出，已切换为 B」
- 达到 **6** → **暂停新线索挖掘**，发一封「需要你定 ICP」的短简报，列出已试渠道与各自失败原因，
  请用户给出：目标国家、目标买家类型、可接受最小订单量。**用户回复前 A 档只做监控**

此规则用于避免再次出现连续 200 轮静默空转。

---

## 状态文件

`~/Documents/HanfuLeadOps/state.json`

```json
{
  "sender_identity": "contact@changanhanfu.com",
  "report_recipient": "aiweline@qq.com",
  "consecutive_zero_runs": 0,
  "discovery_strategy": "A",
  "open_blockers": [],
  "counters": { "runs_total": 0, "leads_qualified": 0, "contacts_made": 0 }
}
```

`leads.jsonl` 每行一条线索，按规范化 URL 的 hash 去重（不靠读散文日志）：

```json
{"hash":"…","url":"…","author":"…","country":"…","posted_at":"…","evidence":"…",
 "score":{"category":2,"intent":2,"b2b":2,"qty":1,"channel":2},"total":9,
 "status":"qualified|screened_out|contacted|replied|closed_optout|closed_no_reply|blocked:sender",
 "channel":"…","first_contact_at":null,"monitor_until":null}
```

---

## 录入渠道标记（强制，HARD）

本技能（含 `获客巡检.md` 指令与 `push_leads_pg.py` 录入脚本）写入
`w_ai_acquisition_lead.entry_channel` 时**必须**取 `skill`（获客技能录入）。
该列由后台 `Weline_AiAcquisition` 维护，四个合法值：

| 值 | 含义 | 谁负责写 |
|---|---|---|
| `default` | 后台手工录入 / 旧数据兜底 | 后台表单（不显示徽章） |
| `browser_model` | 浏览器端侧/本地模型录入 | 浏览器桥 `POST /ai_acquisition/frontend/bridge/lead` 自动判定 |
| `ai_select` | AI 模块模型经智能体 `save_lead` 录入 | `SaveLeadTool` 自动写入 |
| `skill` | 获客技能录入 | **本技能，写库时必须显式带上** |

- `push_leads_pg.py` 的 INSERT 列清单必须含 `entry_channel`，值固定 `'skill'`；
  upsert 的 `DO UPDATE` 子句**不得**把 `entry_channel` 改回 `default`（避免覆盖已有标记）。
- 若某条线索是技能先发现、后由 AI/浏览器补录，以**先到的非 default 标记为准**，
  不要互相覆盖（后端 `applyMergeFields` 已对 `default` 做空值保护，脚本侧同样遵守）。
- 未经用户确认，不得把本技能产出标成 `browser_model` 或 `ai_select`。

---

## 禁止

- 以通用网页搜索起手找线索
- 把供应商营销页 / 目录站 / 聚合站当成买家线索
- 写库时不带 `entry_channel='skill'`，或把他人标记覆盖成 `default`
- 用「必须出现汉服二字」一刀切排除相邻品类
- 群发、跨渠道重复、联系已拒绝者
- 编造价格 / MOQ / 交期 / 库存 / 认证
- 未验证发送结果就声称已发送
- 连续多轮零产出却不升级、不改策略
