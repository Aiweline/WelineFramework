# UC-1 冻结主路径验收结果（AI 获客线索沟通与转化客户）

- 执行时间：2026-10-24 13:41–13:55 (Asia/Shanghai)
- 执行席位：Team:测试:
- 脚本：`tests/uc1_lead_conv.php`（幂等，固定 dedupe_hash upsert，重复执行 exit 0）
- **总判定：PASS（9/9 步骤通过，含 1 项行为差异记录，见缺陷清单）**

## 环境与通路声明

| 项 | 值 |
|---|---|
| 数据库 | pgsql 127.0.0.1 / `mig_clone_productcurrent20260810_20260810022347_e07e` / 前缀 `w_` |
| Mail 通路 | **`MailFakeMailboxService` 落库通路**：发件账户 `support@quote-test.invalid`（account_id=1，域名 `quote-test.invalid` engine=fake、status=active → `isFakeDomain()=true`）。该账户为本地既有测试账户（非本次新增，无需 SQL 夹具插入）。所有邮件真实落 `w_weline_mail_message`（folder=sent/inbox） |
| AI 通路 | **AI 不可用（fail-safe 路径实测）**：`ModelCatalogService::resolve(null)`（chat 默认模型 deepseek-v4-flash，supplier=deepseek）→ `ModelGatewayService::chat()` 真实调用 `api.deepseek.com` 返回 `Authentication Fails, Your api key: ****1081 is invalid`（重试 3 次后）；ollama 本地账户连接 `127.0.0.1:11434` 失败；openai/kimi/google/anthropic 无可用账户。所有模型通路均真实探测、均不可用，按任务允许的降级路径验证 fail-safe |
| 被测系统 | 真实 ObjectManager 实例化 Service/Model，真实 DB 读写，无任何桩 |

## 步骤结果与证据 id

| 步骤 | 判定 | 断言 | 证据 id |
|---|---|---|---|
| S1 准备 | PASS | `w_ai_acquisition_lead`/`w_ai_acquisition_conversation` 存在；`w_customer_type` 种子 retail/b2b_wholesale/ai_acquisition 已存在（非空，未需补种）；发件账户可用 | account_id=1 |
| S1-config | PASS | `ai_acquisition/auto_reply/enabled=1`、`cooldown_days=7`、`prompt/default`、`outreach/mail_account_id=1` 经 `SystemConfig::setConfig()` 写入并读回 | w_system_config key=ai_acquisition/* |
| S2 Lead 夹具 | PASS | company='UC1 Test Retail Co'，email='uc1-buyer@example.com'，US，retailer，outreach_target=1，ai_auto_reply=1，status=new，first_seen_date 非空 | **lead_id=37**（dedupe_hash=uc1-lead-conv-fixed-0001） |
| S3 发信 | PASS | `MailComposerService::send(account,to,subject,body,'ai_acquisition_lead',37)` → MailMessage(source='ai_acquisition_lead', source_id=37, from=support@quote-test.invalid, to=uc1-buyer@example.com) + LeadConversation(direction=out) + status new→contacted | mail_out_id=781, conv_out_id=14 |
| S4 回信回绑 | PASS | MailMessage(folder=inbox, from=uc1-buyer@example.com) 落库 → `InboundLeadMailBinder::bind()` → Conversation(direction=in, role=customer) + status contacted→replied；同一封信二次 bind 返回 already_bound、conversation 不重复（幂等） | mail_in_id=782, conv_in_id=15 |
| S5 AI 自动回复 | PASS（b 路径） | AI 不可用：`AutoReplyEngine::replyIfDue()` 返回 `skipped=no_model`，**无新邮件、无会话写入**（前后计数一致），fail-safe 成立。a 路径（AI 可用完整发送）因全部网关 key/连接不可用而未触发，AI 不可用的判定本身即冻结用例允许的降级验证 | skip_code=no_model（错误源头：deepseek key 无效） |
| S6 转化 | PASS | `LeadConverter::convert()` → w_customer 出现 email='uc1-buyer@example.com'，customer_type='ai_acquisition'，is_anonymous=1；lead.status=converted，customer_id 回填；重复转化不产生第二条 customer（行为差异见缺陷①） | **customer_id=1751** |
| S7 频控 | PASS | cooldown 探针 lead（status=replied，out 会话 sent_at=now）→ `replyIfDue()` 返回 skipped=cooldown | cooldown_probe_lead_id=38 |
| S8a new 状态边界 | PASS | status=new 的 lead 直接 `replyIfDue()` → skipped=status_not_eligible（引擎不追发未触达 lead） | probe_lead_id=39 |
| S8b 已存在 retail 客户 | PASS | lead.email 与既有 retail 客户相同 → convert 返回 merged=true，customer_type 保持 'retail' 未被改 | merged_lead_id=40, merged_customer_id=1752 |

## 会话记录（lead_id=37）

| conv id | direction | role | mail_message_id |
|---|---|---|---|
| 14 | out | human | 781 |
| 15 | in | customer | 782 |

说明：UC-1 冻结用例期望 conversation ≥3 条，第 3 条应为 AI 出站会话（role='ai'）。因本环境所有 AI 网关不可用（key 失效/无账户/连接拒绝），第 3 条会话未产生——这是环境约束，非实现缺陷：引擎在 AI 生成失败时的行为（无发送、无会话写入、返回错误）恰是被验证的 fail-safe 断言。

## 缺陷/行为差异清单

1. **（中）`app/code/Weline/AiAcquisition/Service/Customer/LeadConverter.php:42`**：`convert()` 对 status='converted' 的 lead 直接抛 `\RuntimeException('线索状态 converted 不可转化')`，而非幂等返回既有 customer。冻结用例「重复点按钮不产生第二条 customer」的 DB 语义满足（无第二条），但接口语义与用例「重复转化 → 只关联不重建」（spec 备选/异常行）不一致——重复调用是异常而非幂等成功。若后台按钮在 converted 态仍可点，用户会收到报错。建议把 `Lead::STATUS_CONVERTED` 纳入 CONVERTIBLE_STATUSES 并走 existing 分支，或由控制器层禁用已转化按钮。
2. **（信息）`app/code/Weline/AiAcquisition/Service/AutoReplyEngine.php:150-155`（模型解析）**：chat 默认模型 deepseek-v4-flash 的供应商账户 key 已失效（本地 AI 模块账户配置问题，非代码缺陷），导致 AI 可用路径在本环境无法验证 a) 完整发送分支。恢复有效 key 后重跑 `php tests/uc1_lead_conv.php` 即自动走 S5a 分支。
3. **（低，环境）`app/code/Weline/Mail/Service/MailComposerService.php:62`**：`send()` 成功但成功消息不携带 message_id，AutoReplyEngine/LeadMail 均以 source+source_id 反查最新一封回填 `mail_message_id`（架构文档已知风险①，并发下有错绑窗口）。本次单线程测试无影响。

## 可回查 SQL

```sql
-- lead 与状态
SELECT id,status,customer_id,dedupe_hash FROM w_ai_acquisition_lead WHERE dedupe_hash='uc1-lead-conv-fixed-0001';
-- 会话
SELECT id,direction,role,mail_message_id FROM w_ai_acquisition_conversation WHERE lead_id=37 ORDER BY id;
-- 邮件
SELECT message_id,folder,from_email,to_email,source,source_id FROM w_weline_mail_message WHERE source='ai_acquisition_lead' AND source_id=37;
-- 客户
SELECT customer_id,email,customer_type,is_anonymous FROM w_customer WHERE email='uc1-buyer@example.com';
-- 探针
SELECT id,status FROM w_ai_acquisition_lead WHERE dedupe_hash IN ('uc1-lead-conv-fixed-0001-new','uc1-lead-conv-fixed-0001-cooldown','uc1-lead-conv-fixed-0001-merged');
```

## 清理声明

未删除任何业务数据；探针行（lead 38/39/40 与其客户 1752）保留作为边界测试证据。
