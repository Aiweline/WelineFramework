# PageBuilder AI 建站门禁与 Prompt 契约

本文描述当前有效 gate。所有构建完成、覆盖率和发布判断都以 `plan_json.pages.{page_type}.{block_key}` 为准。

## 状态源

| 范围 | 有效输入 | 禁止作为真相源 |
| --- | --- | --- |
| 页面覆盖 | `plan_json.pages.{page_type}` | 移除页面表、移除阶段契约、移除工作台缓存 |
| block 执行 | `plan_json.pages.{page_type}.{block_key}.status` | 移除派生任务、历史plan_json block 工作表 artifact、历史plan_json 生成流程 |
| block 内容 | `html`、`phtml`、`fields`、`assets`、`error` | 既有 `pages.{page_type}.{block_key}` |
| 发布完成 | 所选页面所有动态 block `status=1` | 任何派生 plan 或历史 contract |

## Gate 对照

| Gate | 判定规则 | Prompt 关系 |
| --- | --- | --- |
| page_type_coverage | 所选 `page_type` 必须存在于 `plan_json.pages` | 阶段一 prompt 必须直接输出页面总表 |
| block_presence | 每个页面必须有至少一个动态 block 节点 | 阶段一 prompt 必须直接用 block key 建节点 |
| block_pending | `status=0` 可进入构建 | 不进 prompt，由队列调度判断 |
| block_running | `status=2` 视为运行中 | 不重复启动同一 block |
| block_done | `status=1` 且写回 html/fields | 生成 prompt 只负责当前 block |
| block_failed | `status=-1` 且写回 error | 重试 prompt 只携带当前 block 和必要上下文 |
| page_rollup | 页面 status 从 block status 汇总 | 不要求 AI 计算 page rollup |
| publish_ready | 所选页面全部有效 block `status=1` | 发布 prompt 不读取既有计划 |

## Prompt 组装

构建 prompt 只能按需组装当前任务上下文：

```js
{
  site_context: plan_json.root_context,
  page: plan_json.pages.{page_type},
  block: plan_json.pages.{page_type}.{block_key}
}
```

不得为了构建 prompt 重新落库完整 移除派生计划、历史plan_json 生成流程 或其它派生大对象。

