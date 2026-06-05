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
| image_contract_handoff | 非政策页的图文节奏、`needs_image`、`asset_requirements` 和 `asset_distribution_policy` 已写回当前 plan_json | Stage1 必须规划图文结合；Stage3 必须执行当前 block 的 verified image `<img>` 绑定 |
| image_verified_or_retry | 必需图片 block 成功时有 `final_url` / `verified_assets` / block `assets`；失败时有 retry 标记且不得伪造 URL | block prompt 必须先确认图片，未确认时只能临时 CSS/product UI fallback，不能宣称已满足生成图契约 |
| page_rollup | 页面 status 从 block status 汇总 | 不要求 AI 计算 page rollup |
| publish_ready | 所选页面全部有效 block `status=1` | 发布 prompt 不读取既有计划 |

## CTA 与事件配置

AI 建站的 CTA、表单、下载、联系、购买等可衡量动作，事件计划必须写在最近的 block 节点：

```js
plan_json.pages.{page_type}.{block_key}.analytics_events
```

`analytics_events` 是 block 自身的执行提示，不是新的真相源。Stage1 负责为有动作的 block 规划事件；Stage3 构建 prompt 负责把事件落到实际交互控件上：

- 普通点击 CTA 使用 `weline-pixel::<event_name>` 类名标记在真实 `<a>` 或 `<button>` 上。
- 无真实路由的 CTA 继续使用 `data-pb-ai-action="primary_cta"` 触发 PageBuilder 的 `pb:cta` 桥接，同时保留 `weline-pixel::<event_name>`。
- 表单提交等非普通点击动作使用组件内 scoped listener，并按默认 `weline-pixel-events` 技能调用 `window.WelinePixel.track(...)`。
- 禁止在 AI 生成块中注入 GA、Meta、TikTok、Bing 等第三方像素脚本或直接请求像素端点。

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
