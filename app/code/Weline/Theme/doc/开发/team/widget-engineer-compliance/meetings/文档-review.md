# 文档席对齐检查

- agent_id: ced12e10-ac91-4e3c-b852-f8befc6574a9
- result: closed
- verdict: **fail**（rework → 项目经理/文档）
- aligned: false

## findings

1. `AI硬规则索引.md` 主题行反例仍写「layout 内嵌非 Weline_Theme 部件」，易误读为任何布局只能内嵌 Theme。
2. `AI工程交付流程.md` §6 只显式写 Theme 布局仅可内嵌 Weline_Theme，缺「同模块自有布局亦可内嵌本模块部件」肯定句。

## already aligned

- 工程团队.md / 部件开发指南.md / Theme开发总指南.md / 两边开发日志

## rework（项目经理代文档席落盘）

- 已改 `AI硬规则索引.md` 反例列：明确「Theme 布局内嵌非 Weline_Theme」非法；业务模块自有布局内嵌本模块部件合法。
- 已改 `AI工程交付流程.md` §6：补同模块肯定句（含 Customer/Product 自有布局）。
