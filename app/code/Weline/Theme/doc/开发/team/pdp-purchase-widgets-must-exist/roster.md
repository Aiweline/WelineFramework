# roster — pdp-purchase-widgets-must-exist

- slug: `pdp-purchase-widgets-must-exist`
- 模式: team
- 父会话席位: 项目经理
- work_kind: feature
- plan_complexity: 非简单（Theme required 注入架构 × Product 嵌套槽 × Cart/Checkout/Payment 三件套；关联停工项 `required-default-injection` / `REQ-THEME-0036`）
- 规格: `app/code/Weline/Theme/doc/开发/spec/pdp-purchase-widgets-must-exist.md`
- fe_be_scope: both

| 波次 | 席位 | 状态 |
|------|------|------|
| 立项波 | 需求分析 | closed（规格 + EARS + UC 已落盘；需求纠偏已写） |
| 立项波 | 领域探查 | 待召（嵌套槽 / 发布实体 / 0036 overlay 覆盖面） |
| 立项会 | 项目经理 | 待召 |
| 技术方案波 | 架构师 | 未启动（须对齐 REQ-THEME-0036 与版本卸载键） |
| 施工波 | 后端 | 未启动 |
| 施工波 | 前端 | 未启动 |
| 施工波 | 测试 | 未启动 |
| 施工波 | 文档 | 未启动 |

## 关联

- 通用架构队：`Theme/doc/开发/team/required-default-injection/`（曾停工；本 slug 为 PDP 购买切片）
- 需求：`REQ-THEME-0036`；边界：`REQ-THEME-0012`（编辑器草稿）、`REQ-THEME-0014`（应用 Tab / user_deleted）

## 停工门禁

未完成立项会确认前：禁止改业务 PHP/phtml/CSS 以「修丢失」；允许续写规格与探查证据。
