# AI工作台

本目录用于沉淀 `Weline_Websites` AI 建站工作台的统一规划、接口草图、任务拆解与执行进度。

## 文件索引

- [Websites-AI建站工作台-总规划.plan.md](./Websites-AI建站工作台-总规划.plan.md)
  - 总体目标、分层架构、阶段设计、模块改动、风险与验收
- [Websites-AI建站工作台-接口草图.md](./Websites-AI建站工作台-接口草图.md)
  - provider、theme source、session/message/event/artifact、预览与物料化接口草图
- [Websites-AI建站工作台-任务拆解.task.md](./Websites-AI建站工作台-任务拆解.task.md)
  - 按阶段拆开的实施任务、目标文件、测试先行顺序与完成标准
- [Websites-AI建站工作台-进度.md](./Websites-AI建站工作台-进度.md)
  - 当前决策、里程碑状态、阻塞项、下一步入口
  - **纠偏**：文首已区分 **Epic 8 / Websites 硬化范围** 与 **`GuoLaiRen_PageBuilder` 原生工作区端到端**；后者阻塞与 P0～P2 路线以模块内 [`app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md`](../../../../app/code/GuoLaiRen/PageBuilder/doc/计划-AI建站中台-PageBuilder拓展流程与进展.md) 为准。

## 当前定位

- 平台入口归属：`Weline_Websites`
- 默认流程提供者：`websites_default`
- 外部扩展方式：`provider_code` + `extends/module/Weline_Websites/...`
- 当前重点：先把 `Websites` 自己的 AI 建站工作台做完整，再让 `PageBuilder` 等模块作为 provider 接入
