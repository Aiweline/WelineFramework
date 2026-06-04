# PageBuilder 第三阶段发布流程图

第三阶段不再生成内容，只发布第二阶段已经写回到 `plan_json.pages.{page_type}.{block_key}` 的页面 block 产物。

## 流程图

```mermaid
flowchart TD
    START["启动 Stage3 Publish"] --> LOAD["读取 plan_json.pages 与已生成页面产物"]
    LOAD --> GATE["P1: 所选 page_type 均存在"]
    GATE -- "失败" --> BACK["返回 Stage2 修复或重试"]
    GATE -- "通过" --> BLOCKS["检查每个动态 block.status"]
    BLOCKS --> STATUS["所有 block 必须 status=1"]
    STATUS -- "存在 0/2/-1" --> BACK
    STATUS -- "通过" --> MATERIALIZE["物化 page layout / AI HTML blocks"]
    MATERIALIZE --> QA["发布前 QA"]
    QA -- "失败" --> BACK
    QA -- "通过" --> SAVE["保存 Page / Layout / UrlRewrite"]
    SAVE --> DONE["发布完成"]
```

## 发布输入

- `plan_json.pages.{page_type}.{block_key}.html`
- `plan_json.pages.{page_type}.{block_key}.fields`
- `asset_manifest` / `verified_assets`
- `plan_json.pages.{page_type}.{block_key}` / `shared_components`

发布 gate 不读取 移除旁路结构 或 移除派生计划 来判断页面是否已经生成完成。
