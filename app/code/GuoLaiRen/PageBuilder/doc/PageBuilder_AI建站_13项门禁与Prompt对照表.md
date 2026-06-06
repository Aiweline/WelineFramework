# PageBuilder AI 建站门禁与 Prompt 契约

本文描述当前有效 gate。所有构建完成、覆盖率和发布判断都以 `plan_json.pages.{page_type}.{block_key}` 为准。

## 状态源

| 范围 | 有效输入 | 禁止作为真相源 |
| --- | --- | --- |
| 全站上下文 | `plan_json.content_locale`、`plan_json.language_contract`、`plan_json.locale_context`、`plan_json.site_design_system`、`plan_json.theme_context_snapshot`、`plan_json.shared_prompt_context` | 复制到 `plan_json.pages.{page_type}` 或 `plan_json.pages.{page_type}.{block_key}` 的全站上下文字段 |
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
| root_context_not_duplicated | 全站上下文只保留在 `plan_json` root；page/block 节点不得持有 `content_locale`、`language_contract`、`locale_context`、`site_design_system`、`theme_context_snapshot`、`shared_prompt_context`、`site_context`、`website_profile`、`asset_manifest_ref` 或 `contract_summary` | Stage3 prompt 可以从 runtime context 读取全局契约，但不得要求 AI 把全局契约复制回当前 page/block |
| language_contract_handoff | `content_locale`、`language_contract`、`locale_context` 已写回 `plan_json` 根节点和 `plan_json.i18n`；page/block 节点不得持有独立语言契约 | Stage3 必须按全局 `source_of_truth_locale` 生成所有访客可见文案；`block_goal` / `task_goal` / `story_goal` / contract 字段只允许作为意图，不得粘贴为页面内容 |
| image_contract_handoff | 非政策页的图文节奏、`needs_image`、`asset_requirements` 和 `asset_distribution_policy` 已写回当前 plan_json | Stage1 必须规划图文结合；Stage3 必须执行当前 block 的 verified image `<img>` 绑定 |
| image_verified_or_retry | 必需图片 block 成功时有 `final_url` / `verified_assets` / block `assets`；未确认时必须让当前 block 失败/重试且不得伪造 URL | block prompt 必须先确认图片；未确认时不得生成最终 CSS-only 替代块，也不得宣称已满足生成图契约 |
| semantic_affordance_quality | 任何长得像按钮、Tab、pill、badge、步骤、轮播点、状态点、输入框、进度条、chip、指标或控件的元素，必须有目标语言可见文字，或有明确图标与可访问标签；纯装饰不得伪装成可操作控件 | Stage3 视觉强契约必须拒绝无标签圆点、空 pill、空横条、空输入框状条、孤立轮播点、无图标步骤点和占位控件；若出现这类结构，必须删除或改成有语义的证明、步骤、状态、Tab 或真实控件 |
| hero_banner_scale | `strict_hero_cover=1` 的 hero/banner/opening block 必须默认使用全屏宽幅或 viewport-width 的强视觉 Banner；图片是首屏主视觉，不是右侧小缩略图 | Stage3 prompt 必须要求 1920x750 风格、full-bleed `<img>` cover layer、可读遮罩和文字安全面板；禁止小侧图、窄图岛、居中 1200px 图块、上下色块留白和 CSS background-only 替代真实 `<img>` |
| visual_negative_prompt_coverage | 强契约和底座提示词必须覆盖常见失败族：占位/线框/骨架感、模板重复、无意义装饰、假控件、虚假事实/指标/联系方式/安全/支付承诺、低对比、层级混乱、CTA 不明确、图片缺失/伪造、响应式重叠/裁切/横向滚动 | Stage3 返回 JSON 前必须按负向族自查；发现任一问题时重写区块，而不是继续叠加装饰或用宽松 fallback 掩盖 |
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
  block: plan_json.pages.{page_type}.{block_key},
  language_contract: plan_json.language_contract,
  locale_context: plan_json.locale_context,
  visible_copy_contract: derived from plan_json.content_locale
}
```

不得为了构建 prompt 重新落库完整 移除派生计划、历史plan_json 生成流程 或其它派生大对象。

Stage2 内容 block 的返回示例必须是可解析 JSON 信封，示例本身也要能通过 `json_decode`。用于教学的最小结构为：

```json
{
  "extra_fields": "group:ai_content => AI editable content\ncontent.title => Title:text:Finished localized title\ncontent.description => Description:textarea:Finished localized body\ncta.text => CTA text:text:Start now",
  "php_variables": "$contentTitle = $getConfig('content.title', 'Finished localized title');\n$contentDescription = $getConfig('content.description', 'Finished localized body');\n$ctaText = $getConfig('cta.text', 'Start now');",
  "css_extra": "#componentId .pb-c-root{position:relative;padding:56px 24px;box-sizing:border-box;}",
  "css_responsive": "@media (max-width: 768px){#componentId .pb-c-root{padding:40px 18px;}}@media (max-width: 420px){#componentId .pb-c-root{padding:32px 14px;}}",
  "html_content": "<section class='pb-c-root'><h2><?= htmlspecialchars($contentTitle ?? 'Finished localized title', ENT_QUOTES, 'UTF-8') ?></h2><p><?= nl2br(htmlspecialchars($contentDescription ?? 'Finished localized body', ENT_QUOTES, 'UTF-8')) ?></p><button type='button' class='pb-c-cta' data-pb-ai-action='primary_cta'><?= htmlspecialchars($ctaText ?? 'Start now', ENT_QUOTES, 'UTF-8') ?></button></section>",
  "js_content": ""
}
```

Prompt 示例不得包含乱码占位、未闭合 PHP 字符串、裸 HTML/PHTML、markdown fence、第二个 JSON 对象，或会被 PHP 双引号插值吃掉的未转义 `$getConfig` 示例。
