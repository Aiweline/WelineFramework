# 对齐冻结 — ui-prototype-gate-before-test

## 冻结契约

1. 硬规则 id：`ui_prototype_gate_before_test`
2. 硬顺序：专席复审 pass → UI+原型审查（可打回/resume 开发）→ 测试执行 → 项目经理汇审 → 用户汇报
3. 产物：`acceptance-ui.md` / `acceptance-prototype.md` 双 pass 前禁止测试执行
4. 红灯骨架仍可在施工期落盘；**执行**须等过签

## 范围

- `dev/ai-command/ai/工程团队.md`
- `HardConstraintsCatalog` / `GuidanceWorkflowCatalog` / `McpSkillCatalog`
- `AI工程交付流程.md` / `AI硬规则索引.md`
- MCP 契约测

## N/A

电商 / 支付 / Visitor / 业务功能码。
