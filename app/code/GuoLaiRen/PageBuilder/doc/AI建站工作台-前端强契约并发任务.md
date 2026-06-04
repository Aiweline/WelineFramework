# AI 建站工作台前端并发任务契约

本文只描述当前有效契约。PageBuilder AI 生成状态的唯一持久化真相源是：

```js
plan_json.pages.{page_type}.{block_key}
```

既有 移除旁路结构 不得保留为独立结构；前端、SSE、队列和发布 gate 只能读取 plan_json.pages.{page_type}.{block_key}。

## 状态模型

每个页面直接包含动态 block 节点：

```js
plan_json.pages.home_page.status
plan_json.pages.home_page.hero.status
plan_json.pages.home_page.hero.html
plan_json.pages.home_page.hero.fields
plan_json.pages.home_page.hero.error
```

block status 使用数字：

| status | 含义 | 前端行为 |
| --- | --- | --- |
| `0` | 未生成 | 可进入生成队列 |
| `2` | 生成中 | 展示运行中，禁用重复启动 |
| `1` | 成功 | 展示生成结果，构建队列跳过 |
| `-1` | 失败 | 展示失败原因和重试入口 |

`page.status` 是该页面所有 block 的 rollup，不是独立执行单元。前端展示页面状态时可以读 page rollup，但局部重试、失败提示和进度列表必须落到具体 block。

## 前端任务边界

| 编号 | 任务 | 输入字段 | 输出要求 |
| --- | --- | --- | --- |
| FE-01 | 工作台阶段总线 | workspace state、active operation、queue info、`plan_json.pages` summary | 阶段卡、按钮禁用、失败横幅、重试入口全部由接口状态驱动 |
| FE-02 | 阶段一计划 | `post-start-plan`、`post-confirm-plan`、`plan_queue_info` | 只在 `plan_json.pages` 覆盖所选 page_type 后允许进入 build |
| FE-03 | 页面/block 预览 | `plan_json.pages.{page_type}` | 按动态 block key 渲染页面结构，不读取既有 移除页面表 或 移除派生计划 |
| FE-04 | 构建与恢复 | `post-start-build`、`post-resume-build`、operation SSE、`plan_json.pages` | 进度、失败、重试以 block status `0/2/1/-1` 为准 |
| FE-05 | 局部再生成 | `page_type`、`block_key`、当前 block node | 只重试目标 block，成功后写回同一 block 节点 |
| FE-06 | 发布检查 | publish checklist、`plan_json.pages` | 只有所选页面所有有效 block 都为 `1` 时通过构建完成 gate |
| FE-07 | SSE 日志 | queue status、terminal status、latest workspace state | SSE 可以展示运行事件，但最终状态必须回读 `plan_json.pages` |

## 验收用例

- 新 plan 全部 block `status=0` 时，构建进度逐个显示 pending/running/done。
- 单个 block `status=1` 时，构建队列跳过该 block，前端不得显示为待生成。
- 单个 block `status=-1` 时，前端展示失败并允许只重试该 block。
- `plan_json.pages` 缺少选中的 `page_type` 时，阶段一 gate 明确失败。
- 只有 移除旁路结构 或 移除派生计划 时，前端不得当作有效计划展示或解锁 build。

