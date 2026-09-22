# channel/align-freeze — ui-prototype-gate-before-test

## msg-001 | from:项目经理 | 2026-09-22

用户要求：MCP 工程团队技能中，UI 与原型对开发成果有审查/打回权限；打回后 resume 开发子智能体继续调；UI+原型过签后才给测试；测试完交项目经理；项目经理验收后才可汇报。

已落权威：`ui_prototype_gate_before_test` + `工程团队.md` 流水线改写。请提示词优化工程师做语义复审（禁丢义、禁乱加），写 `meetings/提示词优化-review.md`。

---
from: 提示词优化工程师
agent_id: 6f2d9cd3-0e87-4c11-b015-d3b91d0c802a
kind: reply
---
语义复审 closed：verdict=pass。清单见 `meetings/提示词优化-review.md`。最小修补：流水线「可测面就绪」歧义、项目经理席镜补门禁、GuidanceWorkflow surface/norm/forbidden/required 对齐硬顺序。未削弱 acceptance_substantive_signoff / one_seat_one_agent / findings_wake_pm。
