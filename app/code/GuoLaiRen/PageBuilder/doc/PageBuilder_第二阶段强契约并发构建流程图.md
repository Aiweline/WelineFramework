# PageBuilder 第二阶段生成流程图

第二阶段只把 `plan_json.pages.{page_type}.{block_key}` 当作生成状态真相源。
移除派生计划、移除工作台缓存、移除阶段契约、移除页面表 等既有结构不得参与是否生成、是否成功、是否缺失的判断。

## 状态模型

```text
plan_json.pages.{page_type}.{block_key}.status
plan_json.pages.{page_type}.{block_key}.html
plan_json.pages.{page_type}.{block_key}.fields
plan_json.pages.{page_type}.{block_key}.error
```

Block 状态：

- `0`：未生成，可被队列选择
- `2`：生成中，队列视为运行中
- `1`：成功，队列跳过
- `-1`：失败，可被队列重试

Page 状态是该页面下所有动态 block 的 rollup，block 仍是最小执行单元。

## 流程图

```mermaid
flowchart TD
    START["启动 Stage2 Build"] --> LOAD["读取 plan_json.pages"]
    LOAD --> GATE["G1: 所选 page_type 必须存在于 plan_json.pages"]
    GATE -- "缺失" --> FAIL["失败: 返回阶段一重新生成"]
    GATE -- "通过" --> SCAN["扫描 page 下动态 block"]
    SCAN --> PICK["选择 status=0 或 status=-1 的 block"]
    PICK --> SKIP_DONE["status=1: 跳过"]
    PICK --> SKIP_RUNNING["status=2: 视为运行中"]
    PICK --> BUILD["按需组装当前 page + 当前 block + plan_json 根部上下文"]
    BUILD --> AI["生成当前 block 的 html / fields"]
    AI -- "成功" --> WRITE_OK["写回同一 block: html fields status=1 error清空"]
    AI -- "失败" --> WRITE_FAIL["写回同一 block: error status=-1"]
    WRITE_OK --> ROLLUP["更新 page.status rollup"]
    WRITE_FAIL --> ROLLUP
    ROLLUP --> NEXT{"是否还有可执行 block"}
    NEXT -- "有" --> PICK
    NEXT -- "无" --> DONE["生成队列结束"]
```

## 关键约束

- Build 队列不得 hydrate 或持久化完整 移除派生计划 作为第二份执行计划。
- Prompt/context 运行时按当前 block 组装，不落库为第二份 plan_json。
- 既有数据不迁移；仅有既有 历史 blocks 数组、移除页面表、移除工作台缓存 或 移除阶段契约 时，阶段 gate 必须失败。
