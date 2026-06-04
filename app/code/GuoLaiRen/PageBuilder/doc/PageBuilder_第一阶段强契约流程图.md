# PageBuilder 第一阶段生成流程图

第一阶段的持久化输出是当前 `plan_json`，其中页面与 block 状态必须落在：

```text
plan_json.pages.{page_type}.{block_key}
```

既有 移除旁路结构 不得保留为独立结构；阶段一完成 gate 只能读取 plan_json.pages。

## 流程图

```mermaid
flowchart TD
    START["用户启动 Stage1"] --> INPUT["读取 brief / page_types / locale / 技能 / 参考图"]
    INPUT --> ROOT["生成 plan_json 根部站点、主题、语言、共享上下文"]
    ROOT --> PAGES["为所选 page_type 写入 plan_json.pages.{page_type}"]
    PAGES --> BLOCKS["为每个页面写入动态 block 节点"]
    BLOCKS --> INIT["初始化 block.status=0"]
    INIT --> VALIDATE["验证 plan_json.pages 覆盖所选 page_type"]
    VALIDATE -- "缺失" --> FAIL["Stage1 gate 失败"]
    VALIDATE -- "通过" --> READY["Stage1 完成，可进入 Build"]
```

## 输出示例

```json
{
  "pages": {
    "home_page": {
      "status": 0,
      "hero": {
        "status": 0,
        "fields": {},
        "html": "",
        "error": ""
      }
    }
  }
}
```

## Gate 规则

- 只判断 `plan_json.pages.{page_type}` 是否覆盖所选页面类型。
- 移除旁路结构 存在但 `plan_json.pages` 缺失时仍然失败。
- 既有 `pages.{page_type}.{block_key}` 不转换、不迁移、不作为有效输入。
