# PageBuilder AI 建站懒加载与存储架构

当前 PageBuilder AI 生成状态只有一棵持久化树：

```js
plan_json.pages.{page_type}.{block_key}
```

ArtifactStore、队列日志、SSE 事件和临时 session artifact 可以继续用于传输大文本、图片、日志和调试材料，但不能成为生成状态的第二份真相源。

## 存储层级

| 层级 | 用途 | 是否状态真相源 |
| --- | --- | --- |
| `plan_json` root | 站点、主题、语言、共享上下文 | 部分是 |
| `plan_json.pages` | 页面总表，key 为动态 `page_type` | 是 |
| `plan_json.pages.{page_type}.{block_key}` | block 状态、HTML、fields、error | 是 |
| session artifact | 大文本、AI 原始响应、图片结果、日志 | 否 |
| queue runtime | 排队、运行、终止事件 | 否 |

## 写入规则

- 生成开始时写 `status=2` 到当前 block。
- 生成成功时写 `status=1`、`html`、`fields` 和相关结果到同一 block。
- 生成失败时写 `status=-1`、`error` 到同一 block。
- 重试只选择 `status=-1` 或 `status=0` 的 block。
- 每次 block 状态变化后更新所在 page 的 rollup status。

## 懒加载规则

构建阶段不提前 hydrate 完整执行计划。队列读取当前 page/block 后，只在内存中组装当前 block 所需 prompt/context：

```js
current_page = plan_json.pages[page_type]
current_block = current_page[block_key]
root_context = plan_json without pages
```

图片、组件片段和调试数据可以作为 artifact 暂存，但最终可见状态仍必须回写到 `plan_json.pages.{page_type}.{block_key}`。

