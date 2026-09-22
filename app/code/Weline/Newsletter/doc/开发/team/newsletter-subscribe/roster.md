# roster — newsletter-subscribe

| seat | agent_id | status | wave | notes |
|------|----------|--------|------|-------|
| 项目经理 | parent | running | 立项波收口→对齐冻结 | 父会话交换机 |
| 需求分析 | 51be498a-9c93-4ca6-b177-14746d7adef2 | closed | 立项波收口 | 规格 clarified；Q1–Q5 跨境常规已拍板 |
| 领域探查 | 866b2e54-ea17-4fb0-834b-ae489e09d1d3 | closed | 立项波 | 只读；未改仓 |
| 架构师 | architect-align-freeze | closed | 对齐冻结会 | msg-5 reply：contracts/deps 与 tech-scheme 一致，确认可冻结 |
| 扩展点 | extension-point-construction-1 | closed | 施工-1·Marketing valid_days=A | issue 支持 context.valid_days；缺省 30；Marketing 1.3.4；UT 2/2 OK；channel msg-7/msg-9 closed |
| 原型 | — | idle | — | UI in_scope |
| 前端 | newsletter-frontend-d9-d10 | closed（施工-2·D9+代主题D10） | 施工-2 | D9 Widget 迁入+BinQuery JS；代主题 D10 删壳同发；UT 7/7 OK；channel construction-2 msg-2 |
| 主题 | theme-seat-newsletter-d3-construction-1 | closed（施工-1·D3）；D10 由前端代发 | 施工-1→2 | D3 开槽已 closed；D10 删壳由前端席同发（见 construction-2） |
| UI | — | idle | — | UI in_scope |
| 后端 | newsletter-backend-d11-d12 | closed | 施工·D11/D12 | T1 接线修正 + T2 Observer（identity/guest after）+ Interface；D9 由前端席 closed |
| 测试 | test-seat-align-freeze | closed（本波） | 对齐冻结会主持 | UC+contracts+deps 已钉死；stance 同意冻结；采纳 valid_days=A；规格 ready-for-plan |
| i18n | — | idle | — | 新文案 |
| ACL | — | idle | — | 后台入口；contracts §6 |
| Setup | — | idle | — | 新模块；deps D1 |
| 合规 | — | idle | — | 优惠券/结账触点 |
| Hook | — | idle | skip候选 | 主路径不走 Hook |
| 事件 | newsletter-backend-d11-d12 | closed（T2） | 施工·D12 | 复用 Checkout identity/guest after；doc/event 已钉 |
| 查询 | — | idle | — | BinQuery 订阅提交 |
| Taglib | — | idle | skip候选 | 无领域 select |
| 安全 | — | idle | 复审波 | |
| 文档 | — | idle | 收口波 | |

## 立项结论摘要

- 模块：新建落地 `Weline_Newsletter`（≠ `Weline_Subscription` 周期订购；≠ `Weline_Mail` 企业邮箱）
- 机制：Newsletter 拥有业务 Widget（保留 code）+ required default_injections + Marketing 随机券 + Smtp MailChannel；Theme 壳退役/空引用
- 硬阻塞（施工）：D3 已解除；**D9/D10 已 closed**（前端席同发布单元，registry 唯一 owner=`Weline_Newsletter`）
- 规格：`status=ready-for-plan`；欢迎礼 10%/14 天；T1+T2；弹窗全站 cookie 14；Newsletter 拥有 Widget
- 对齐冻结会 **closed**：测试主持 UC/contracts/deps + 架构师同意 + 扩展点 valid_days=A；产物见 `meetings/align-freeze.md`；无用户待问项
- 下一步：唤醒 D14 前端收尾（若需）/ D15 视觉 / D16 i18n / D11–D13 后端并行 / 测试 D17–D18
