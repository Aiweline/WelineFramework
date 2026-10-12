# AI 流程总览（压缩脊柱）

> **唯一入口顺序**。细节仍以分册为准；本文件消重、钉闭环。  
> 配套硬规则：`intent_lane_router` → `requirement_engineering_grade_gate` → … → `real_machine_usecases_not_unit_tests` / `requirement_use_case_closed_loop_acceptance` / `requirement_acceptance_always`。

## 0. 我们有什么 → 先路由（勿重复进队）

| lane | 入口 | 闭环是什么 |
|------|------|------------|
| `engineering` | `weline-engineering-team` + `prepare_project` | **真机 UC / WB-OP / 正式 e2e**（UT 只锁正确性，≠交付） |
| `opc_obc` | `my-opc-os` | 运营证据 + 决策；改框架 → `R_obc_pm` 交工程后再真机双层验收 |
| `content_ops` | Read `dev/ai-command/**`（跳过 MCP） | 各内容技能自带验收（真页/真图），不冒充工程收口 |
| `website_ops` | `weline-website-deploy` | 站柜探活 + 部署后真机复验（有授权时） |
| `chat` | — | 无交付闭环 |

命令：`/lane-route`。权威细表：[意图路由.md](./意图路由.md)。

## 1. 工程主链（一条，勿再平行发明）

```text
lane=engineering
  → prepare_project + hard_constraints
  → request_grade / work_kind / 澄清+UC（EARS）
  → Plan（简单可 skip，仍须验收）→ 自审通过即开发
  → 复杂：Team:项目经理: 一席一真子智能体
       P0 立项 → P1 对齐冻结(UC+contracts+deps) → P2 方案
       → P3 施工 → P4 专席复审 → P5 UI∥原型过签
       → P6 真机测试闭环（硬）→ P7 文档工程师 → P8 汇审+用户汇报
  → 简单：监工: 同闭环，无多席扮演
```

操作壳阶段文件（仅顺序）：插件 `lifecycle.yaml`。波次正文：[工程团队.md](./工程团队.md)。阶段语义：[AI工程交付流程.md](../../app/code/Weline/Ai/doc/AI工程交付流程.md)。

**禁止**：再维护第二套「LAO 式可选验收闸」与测试席并行；证据闸门 = **测试席职责**，不是第三席。

## 2. 闭环定义（所有可交付流程的终点）

| 必须 | 禁止冒充 |
|------|----------|
| 规格 `UC-*` 全部有真机证据（Browser WB-OP 和/或 `php bin/w e2e:run`） | 仅 PHPUnit / 契约绿 / curl / CDP evaluate |
| Web/UI：禁缓存导航 → 真点选 → **截图 Read 审图** | 截图未 Read、只报尺寸 |
| 用户明示「固化用例」→ 落模块 `test/e2e`/`Test/e2e` + 正式 runner | `node -e` / 临时 launch 当交付 |
| 汇审 + SESSION 计划项 closed 后才向用户宣称完成 | 「代码已改」当 PASS |

权威：`real_machine_usecases_not_unit_tests`、`requirement_use_case_closed_loop_acceptance`、`tester_tests_must_be_real`、`e2e_ai_record_into_module_formal_path`。

## 3. 已消重对照（审查结论）

| 曾重复 / 易混 | 正确处理 |
|---------------|----------|
| `intent_lane` vs `request_grade` | **先后配套**：先 lane 再 grade；不互替 |
| 交付流程 §阶段 vs 工程团队波次 vs `lifecycle.yaml` | **一层权威**：总览钉顺序；yaml=操作壳；工程团队=编制细则；交付流程=门禁语义 |
| LAO `acceptance-validation` 席 vs `Team:测试:` | **并入测试席**；删除「验收闸门」独立操作壳专席 |
| UT / 契约 vs 交付 | UT 可红灯锁正确性；**交付只认真机 UC 闭环** |
| 运维 vs 发布 | 站柜 SSH/主题 = 运维；`Weline_Deploy` = 发布席 |

## 4. 用户说「固化测试」时

1. 从已冻 UC 录/写正式用例到归属模块 `test/e2e|Test/e2e/{frontend|backend}/`  
2. 优先 `data-testid`  
3. 只跑 `php bin/w e2e:run …`  
4. 收口报真机证据（订单号/截图路径等），禁止只报 passed 数  

## 5. 一句话

**先路由进对的队 → 工程按一席一智能体流水线 → 一切可交付收口必须真机验收闭环；UT≠完成；重复席位与平行流程删掉。**
