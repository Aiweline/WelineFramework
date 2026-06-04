# PageBuilder AI 建站工作台重构计划 v2.2

> 状态：已废弃。

本文档原先把 移除派生计划 设计为可执行主计划。该设计已经被简化状态模型取代，不能再作为实现依据。

当前有效架构只有一个持久化真相源：

```js
plan_json.pages.{page_type}.{block_key}
```

## 当前实现原则

- 阶段一 gate 只判断 `plan_json.pages.{page_type}` 是否覆盖所选页面类型。
- 页面下直接使用动态 `block_key` 保存 block 状态、HTML、字段和错误信息。
- block 状态使用数字：`0` 未生成、`2` 生成中、`1` 成功、`-1` 失败。
- 构建队列只扫描 `plan_json.pages` 选择 block 任务。
- 构建 prompt/context 在运行时按当前 page/block 加根部站点与主题上下文组装。
- 不再 hydrate 或持久化完整 移除派生计划 作为第二份执行计划。
- 移除旁路结构 不能参与是否生成、是否成功、是否缺失的判断。
- 既有数据不迁移，既有任务需要重新生成。

## 替代阅读顺序

1. `PageBuilder_第一阶段强契约流程图.md`
2. `PageBuilder_第二阶段强契约并发构建流程图.md`
3. `PageBuilder_第三阶段强契约发布流程图.md`
4. `PageBuilder_AI建站块级并发与图片插槽架构.md`

