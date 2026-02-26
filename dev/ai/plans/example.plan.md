# 示例计划

## 元信息
- **ID**: example_001
- **优先级**: normal
- **状态**: pending
- **创建时间**: 2026-02-26
- **开始时间**: -
- **完成时间**: -

<!--
状态说明：
  - pending: 计划已创建，等待启动（Watchdog 忽略）
  - ready: 计划已准备好，可以开始（Watchdog 忽略）
  - running: 计划进行中（Watchdog 监控并执行）⭐ 设为此状态后开始工作
  - paused: 计划暂停中（Watchdog 忽略）
  - done: 计划已完成
  - cancelled: 计划已取消

启动计划：将状态改为 "running" 或执行 `php bin/w cursor:plan:start example`
-->

## 需求描述

这是一个示例计划，展示如何编写计划文件。

当计划状态为 `running` 时，系统将：
1. 解析此文件中的任务
2. 将任务加入任务池
3. 派发给 Cursor 执行
4. 监控执行并运行测试
5. 备份修改的文件到 `dev/ai/code_backup`

**注意**：状态为 `pending` 或 `ready` 时，Watchdog 不会处理，只会在命令行提醒。

## 任务分解

- [ ] 创建示例模型 @Agent:DB @File:app/code/Example/Demo/Model/Demo.php [P1]
- [ ] 创建示例服务 @Agent:Logic @File:app/code/Example/Demo/Service/DemoService.php @Dep:Agent_DB_001 [P2]
- [ ] 创建示例控制器 @Agent:API @File:app/code/Example/Demo/Controller/Backend/Demo.php @Dep:Agent_Logic_002 [P2]
- [ ] 创建示例视图 @Agent:UI @File:app/code/Example/Demo/view/templates/Backend/Demo/index.phtml @Dep:Agent_API_003 [P3]

## 测试要求

- [ ] 单元测试: Test/DemoServiceTest.php
- [ ] HTTP 测试: /backend/demo 页面可访问

## 验收标准

1. 模型可正常 CRUD
2. 服务层逻辑正确
3. 控制器响应正常
4. 视图显示正确
5. 所有测试通过

---

*提示：将状态改为 `running` 后执行 `php bin/w cursor:plan:execute example` 开始任务*
