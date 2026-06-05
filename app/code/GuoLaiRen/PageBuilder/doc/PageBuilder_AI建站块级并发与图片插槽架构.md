# PageBuilder AI 建站块级并发与图片插槽架构

块级并发的最小执行单元是：

```text
plan_json.pages.{page_type}.{block_key}
```

语言、可见文案和媒体强契约必须在提取 build 任务前写到同一个 block 节点。队列 prompt 只读取 `plan_json.pages.{page_type}.{block_key}.language_contract`、`.locale_context`、`.visible_copy_contract`、`.block_contract`、`.image_intent` 和 `.asset_requirements`，不得从 UI 提示、历史工作表或宽泛 workspace fallback 重新推导这些契约。

图片插槽、HTML、字段配置和错误信息都必须回写到同一个 block 节点，避免形成第二份执行状态。

## 架构图

```mermaid
flowchart TD
    A["用户确认 plan_json.pages"] --> B["Build 队列扫描动态 block"]
    B --> C["status=0/-1 进入生成"]
    C --> D["当前 block 按需解析图片插槽"]
    D --> E["生成或复用 asset final_url"]
    E --> F["生成 block HTML/CSS/fields"]
    F --> G{"质量 gate 通过"}
    G -- "否" --> H["写回 error status=-1"]
    G -- "是" --> I["写回 html fields status=1"]
    H --> J["更新 page.status rollup"]
    I --> J
```

## 关键约束

- 队列状态只认数字 block status：`0`、`2`、`1`、`-1`。
- 图片生成是当前 block 的内部步骤，不能通过 历史plan_json block 工作表 或 移除派生计划 形成长期状态源。
- 构建任务进入 block 队列前，必须把 `block_contract`、`image_intent`、`asset_requirements` 和页面级 `asset_distribution_policy` 写回 `plan_json.pages.{page_type}.{block_key}` / `plan_json.pages.{page_type}`，后续 prompt 只读取这份冻结上下文。
- 语言契约也是 block 契约：`language_contract.source_of_truth_locale` 和 `locale_context.required_visible_script` 必须复制到每个动态 block；`block_goal`、`task_goal`、`story_goal`、`why_this_block`、`block_contract`、`visual_signature`、`image_intent`、`asset_requirements`、`execution_script` 只能作为意图来源，不能作为访客可见文案。
- `image_intent.needs_image=true` 的 block 必须在 block 生成前尝试生成并确认图片 `final_url`；确认成功后写入 `verified_assets`、`_required_image_assets`、`media.image_url` 和 block `assets`。不可用时当前 block 必须失败/重试，不能写入成功 HTML，也不能把原始必需图片契约解释为 CSS-only 设计选择。
- 已成功的 block 保留 `html`、`fields` 和资产引用；失败 block 保留 `error` 并允许单 block 重试。
