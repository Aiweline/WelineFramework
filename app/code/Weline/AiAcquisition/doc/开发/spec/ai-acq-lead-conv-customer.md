---
status: ready-for-plan
work_kind: feature
feature_slug: ai-acq-lead-conv-customer
module: Weline_AiAcquisition
updated: 2026-10-24
plan_skip: false
---

# 规格：AI 获客线索沟通与转化客户（AiAcquisition）

## 背景

AI 获客模块（`app/code/Weline/AiAcquisition`）需求已与用户对齐：

1. 先本地跑通：本地库 pgsql、表前缀 `w_`，表 `w_ai_acquisition_lead` 已存在且已手工加列；自动化 push 脚本已存在。
2. AI 自动发送默认开、不停；只有配置开关能停；模型选择与获客运行一致；提示词按线索类型 `lead_type` 区分，走系统配置 website 级「AI 获客」配置页。
3. 转化客户为匿名客户（`is_anonymous=1`，不给登录）。
4. 每天找 5 家，查询不重复即不停。

## 目标

- 线索（Lead）全生命周期闭环：录入 → 企业邮箱沟通（人工 + AI 自动）→ 转化为匿名客户。
- 全链路在本地环境可跑通、可断言（冻结主路径 UC-1）。

## 范围

- 模块：`Weline_AiAcquisition`（Lead / 沟通 / 转化）、`Weline_Mail`（复用现有发信与收信回绑链路）、`Weline_Customer`（`customer_type` 列与类型注册表）。
- 后台 UI：Lead 列表行操作「邮件」弹窗、AI 沟通记录页（UI 对齐智能体运行记录）、系统配置 website 级「AI 获客」配置页。
- FE/BE: BE 为主 + 后台 UI。

## 需求明细（EARS + 验收标准）

### 需求一：线索录入（Lead）

- Lead 表加列：`lead_type`（retailer/rental_studio/supplier_factory/marketplace/media_guide/directory/social/unknown）、`outreach_target`、`ai_auto_reply`（bool，默认开）、`customer_id`（可空外键）、`first_seen_date`（date）。
- 自动化 push 脚本已存在，本需求不重写，只保证新列在 push 时可写。
- WHEN 新线索入库 THEN `ai_auto_reply` SHALL 默认为开，`first_seen_date` SHALL 为当日；`lead_type` 未知时 SHALL 落 `unknown`。
- 验收标准：本地插入 1 条 lead（UC-1 前置），新列均有默认值且可读写。

### 需求二：企业邮箱沟通（人工）

- Lead 列表行操作栏新增「邮件」弹窗：
  - 发件账户默认 `contact@changanhanfu.com`；
  - 收件人预填 lead 的 `outreach_target`（或 lead email）；
  - 下方时间线展示往来邮件，读 `w_weline_mail_message where source='ai_acquisition_lead'`（按 `source_id` = lead id）。
- 发送走 `Weline_Mail` 模块现有链路，不另起 SMTP 实现。
- 收信按 `In-Reply-To` / `References` 回绑 `source_id`（同一 thread 继续归属 lead）。
- status 流转：`new → contacted`（首次人工或 AI 发出）`→ replied`（收到回信回绑成功时）。
- 验收标准：发送后 `w_weline_mail_message` 出现 `source='ai_acquisition_lead'` 记录且时间线可见；模拟回信后 lead.status=`replied`。
- ⚠ 本地 Mail 服务可用性标注「需工程确认」。

### 需求三：AI 自动沟通

- 开关：Lead 级 `ai_auto_reply` + 全局开关（系统配置），默认开；AI 自动发送默认开、不停，只有配置开关能停。
- 提示词：按 `lead_type`（8 类）选提示词模板，模板存于系统配置 website 级「AI 获客」配置页；`unknown` 落默认模板。
- 模型选择与获客运行一致（复用现有模型解析链路）。
- WHEN AI 生成完成 THEN 系统 SHALL 直接发送（无人工确认步骤）。
- 频控（可配置）：
  - 未回复状态下第 7 天发一封跟进；
  - 每发件账户日上限 50 封；
  - 3 轮未成交自动停（该 lead 停自动发）。
- 硬约束注入提示词：不报价格、不报 MOQ、不报交期。
- 沟通记录表 `w_ai_acquisition_conversation`（lead_id、direction、channel、content、model、tokens/cost、status、created_at 等）；UI 对齐智能体运行记录（Agent 运行记录）样式。
- 验收标准：AI 回复触发后 conversation 新增 ≥1 条记录，且邮件实际发出。

### 需求四：转化客户

- `w_customer` 加列 `customer_type varchar(32) default 'retail'`。
- 类型注册表 `customer_type`：`retail` / `b2b_wholesale` / `ai_acquisition`。
- Lead「转为客户」按钮（status ∈ `replied, qualified` 时可用）：
  - 插入 customer：`email = lead.email`，`customer_type='ai_acquisition'`，`is_anonymous=1`，不给登录（不设密码/不产生 session）；
  - `lead.status → converted`，回填 `lead.customer_id`；
  - 同 email 已存在 customer 则只关联（回填 customer_id）不重建。
- 验收标准：转化后 `w_customer` 存在该 email 且 `customer_type='ai_acquisition'`、`is_anonymous=1`；重复点按钮不产生第二条 customer。

### 隐性需求

1. **频控 / 退订识别**：回信含 `unsubscribe`（或退订链接点击）→ lead 终态 `rejected`，永久停发。
2. **Bounce 处理**：同一 lead 累计 3 次 bounce → 停发。
3. **同域发信校验**：禁止向与本方发件账户同域名的邮箱发信（防自嗨/误发）。
4. **转化前必填校验**：lead.email 非空且格式合法方可转化。
5. **运行记录打通**：conversation 与智能体运行记录（Agent run）可互跳/关联。
6. **成本台账**：conversation 记录 tokens/成本，支持按 lead / 按日汇总。
7. **ACL**：Lead 列表、邮件弹窗、配置页、转化按钮均有后台 ACL 资源控制。
8. **失败回执**：发送失败写 conversation（direction=outbound, status=failed）并可见于时间线。
9. **重复客户保护**：转化幂等；并发下同 email 只建一条（唯一约束或事务内再查）。

## 数据变更

| 表 | 变更 |
|---|---|
| `w_ai_acquisition_lead` | 已存在；加列 `lead_type`、`outreach_target`、`ai_auto_reply`、`customer_id`、`first_seen_date`（本地已手工加列，Setup 需补齐增量脚本） |
| `w_ai_acquisition_conversation` | 新表（见需求三字段） |
| `w_customer` | 加列 `customer_type varchar(32) NOT NULL DEFAULT 'retail'` |
| `w_weline_mail_message` | 复用既有 `source` / `source_id` / `In-Reply-To` 回绑机制，不改结构 |

## 接口变更

- 后台路由：Lead 列表（现有）+ 行操作「邮件」弹窗接口、AI 沟通记录页、Lead「转为客户」action。
- 系统配置：website 级「AI 获客」配置页——全局 AI 自动回复开关、按 lead_type 的 8 组提示词模板、频控参数（跟进天数 7 / 日上限 50 / 未成交轮数 3）。
- Mail：复用 `Weline_Mail` 发送链路与收信回绑（`source='ai_acquisition_lead'`），不新增协议。

## 非目标

- 不部署生产。
- 不做贸易数据（价格/MOQ/交期均不进 AI 话术与数据面）。
- 不做前台登录升级（匿名客户不给登录）。

## 风险

- 本地 Mail 服务（收信 + In-Reply-To 回绑）可用性未确认 —— UC-1 中标注「需工程确认」，必要时用本地 Mailpit/桩收信。
- 本地 AI 模型可用性 —— UC-1 允许「本地模型桩或真实模型」。
- pgsql + `w_` 前缀下 Setup 增量脚本须与手工加列幂等兼容（重复执行不报错）。
- AI 自动发送默认开 → 依赖频控与退订/bounce 停发逻辑先行落地，避免骚扰与封域。

## 冻结用例（UC-1 主路径）

| 字段 | 内容 |
|------|------|
| 角色 | 后台运营（含 AI Cron） |
| 前置 | 本地库 pgsql、前缀 `w_`；`w_ai_acquisition_lead` 已存在并加列；本地 Mail 服务可用（需工程确认）；本地模型桩或真实模型可用 |
| 主成功步骤 | 1. 本地插入 1 条 lead 2. 后台 Lead 列表「邮件」弹窗发送邮件（发件 `contact@changanhanfu.com`，收件人预填）3. 模拟回信落 inbox，按 In-Reply-To 回绑 `source_id`，lead.status=`replied` 4. AI 自动回复（按 lead_type 提示词，模型与获客一致，生成即发送）5. 点「转为客户」6. 全链路断言 |
| 期望结果 | `lead.status=converted`；`w_customer` 存在该 email（`customer_type='ai_acquisition'`、`is_anonymous=1`）；`w_ai_acquisition_conversation` 记录 ≥3 条 |
| 备选/异常 | Mail 服务不可用 → 用例标 BLOCKED（需工程确认）；重复转化 → 只关联不重建 |
| 冻结 | 本用例为验收主路径，冻结不改动 |
