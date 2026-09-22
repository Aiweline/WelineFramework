# 提示词优化-design：browser_strip_automation_flags

- 日期：2026-09-22
- 触发：用户明示「自动化测试要把自动化测试标志抹掉，避免被人机验证拦住」
- 性质：**用户新增义务**（非重复压缩）；提示词席做语义复审

## 权威落点

| 层 | 路径 | 动作 |
|----|------|------|
| hard_constraints | `HardConstraintsCatalog` id=`browser_strip_automation_flags` | 新增 |
| 席位镜 | `McpSkillCatalog` 测试席 `prompt_increment` | 增 HARD |
| 指令 | `dev/ai-command/ai/工程团队.md` 原则 6c | 新增 |
| 门禁文档 | `WebUI浏览器验收与交付地址门禁.md` A·3b | 新增 |
| 索引 | `AI硬规则索引.md` 交付行 | 指针 |
| 机器契约 | `closeout_delivery_reminder.browser_open_order` | 增 `strip_automation_detection_flags` |
| 正式 runner | `tests/e2e/playwright.config.js` + `framework/runtime.js` | 默认抹标志 |

## 不削弱

- `tester_tests_must_be_real`：假数据自验禁止仍在
- `browser_operator_self_test`：真 Browser WB-OP 仍强制
