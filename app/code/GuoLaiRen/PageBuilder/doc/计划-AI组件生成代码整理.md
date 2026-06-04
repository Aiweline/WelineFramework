# AI 组件生成代码整理计划

## 现状与问题

- **AiGenerate.php** 体量过大（3400+ 行），混合了：流式编排、AI 调用、**JSON 提取/修复**、组件校验、草稿写入、调试日志等，大量为「补丁式」逻辑。
- **JSON 解析与修复**：约 400 行私有方法（`extractJsonFromResponse`、`decodeJsonWithRepair`、`fixControlCharsInJsonStrings`、`repairTruncatedJson`、`extractBalancedBraces`）全部堆在控制器内，可读性差、难以单测和复用。
- **调试日志**：约 20 处直接 `file_put_contents(..., debug.log)`，应统一为框架调试能力（如 Debug::env / agent_log），并支持开关。
- **Visual/Api/AiComponent.php**：`postRenderPreview`、`postPreviewById` 等存在重复的「取 body → 取模板内容 → prepareTemplateForPreview → 写临时文件 → renderFromFile」流程，可收敛为统一入口。

---

## 整理目标

1. **职责清晰**：控制器只做 HTTP/SSE 编排与参数传递；JSON 解析、组件 payload 标准化、预览渲染步骤放入 Service。
2. **可测试**：JSON 解析与修复可单独单元测试。
3. **少重复**：预览相关「按 id/按 content/按 token 取内容再渲染」统一一套逻辑；body 解析、日志统一封装。
4. **可维护**：减少控制器内补丁感，新修复策略加在 Service 内即可。

---

## 步骤一：新增 Service — AI 响应 JSON 解析（AiResponseJsonParser）

**位置**：`GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser`

**职责**：
- 从 AI 原始响应文本中提取并解析 JSON，并做常见修复（控制字符、尾逗号、截断）。

**迁移方法**（从 AiGenerate 迁出）：
- `extractJsonFromResponse(string $response): ?string`
- `extractBalancedBraces(string $str, int $start): ?string`
- `decodeJsonWithRepair(string $json): ?array`
- `fixControlCharsInJsonStrings(string $json): string`
- `repairTruncatedJson(string $json): ?string`

**对外接口**：
- `extractJson(string $response): ?string` — 仅提取 JSON 字符串。
- `decodeWithRepair(string $json): ?array` — 仅解析并修复。
- `extractAndDecode(string $response): ?array` — 先提取再解析（一步到位，供 parseComponentResponse 使用）。

**注意**：不包含「组件字段标准化」（extra_fields / html_content 等映射），仍由调用方做。

---

## 步骤二：组件 Payload 标准化收敛

**方案 A（推荐）**：在 **AiGenerate** 内保留私有方法 `normalizeComponentPayload(array $data): array`，只做字段映射（fieldMappings）；`parseComponentResponse` 改为：
1. `$raw = $this->aiResponseJsonParser->extractAndDecode($response);`
2. 若为 null，尝试既有逻辑（PHTML 代码块）或抛异常；
3. `return $this->normalizeComponentPayload($raw);`

**方案 B**：将 fieldMappings + 标准化逻辑迁到 `Service\AI\ComponentPayloadNormalizer`，AiGenerate 只调 `$this->normalizer->normalize($raw)`。若后续多处需要同一套标准化再采用。

**建议**：先采用方案 A，保持改动面小；若后续 Agent/其他入口也要用同一套标准化，再抽 Normalizer。

---

## 步骤三：AiGenerate 控制器瘦身与日志统一

- **依赖注入**：构造函数注入 `AiResponseJsonParser`（或 ObjectManager::getInstance 在方法内获取，视框架习惯）。
- **删除**：控制器内所有 `extractJsonFromResponse`、`extractBalancedBraces`、`decodeJsonWithRepair`、`fixControlCharsInJsonStrings`、`repairTruncatedJson` 实现，改为调用 `AiResponseJsonParser`。
- **调试日志**：
  - 去掉直接写 `.cursor/debug.log` 的 `file_put_contents`。
  - 改为使用框架约定：如 `Debug::env()` 判断是否调试、`agent_log()` 或统一写到一个 logger，并加 caller/key 便于过滤（参见 debug-logging skill）。若当前项目暂无统一入口，可先集中为一个私有方法 `logAgentDebug(string $location, array $data)`，内部根据 Debug::env() 或配置决定是否写文件，便于后续替换为 agent_log。

---

## 步骤四：AiComponent API 预览逻辑统一

**文件**：`Controller\Backend\Visual\Api\AiComponent.php`

**重复点**：
- `postRenderPreview`：refine_token / template_content → 得到 phtml 路径或内容 → prepareTemplateForPreview → 写临时文件 → renderFromFile。
- `postPreviewById`：draft_id / component_id → 从 DB 取 template_content → prepareTemplateForPreview → 写临时文件 → renderFromFile。
- 两处都包含「写临时 phtml → PreviewRenderer::renderFromFile → 可选包一层 HTML 壳」。

**收敛方式**：
- 新增私有方法：`resolveTemplateContentFromRequest(array $body): string`  
  - 入参为已解析的 body；  
  - 若 `draft_id` 则从草稿表取；若 `component_id` 则从 Component 表取；若 `template_content` 则直接用；若 `refine_token` 则读临时文件内容（若仅需 path 可返回 path，由下层区分）；  
  - 返回待渲染的 phtml 内容（或约定返回 ['content' => ..., 'path' => ...] 若需区分）。
- 新增私有方法：`renderPhtmlToPreviewHtml(string $phtmlContent, bool $wrapFullDocument = true): array`  
  - 内部：prepareTemplateForPreview → 写临时文件 → PreviewRenderer::renderFromFile → 可选包 DOCTYPE+style+body；返回 `['success' => ..., 'html' => ..., 'error' => ...]`。
- `postPreviewById`：body → resolveTemplateContentFromRequest（仅 draft_id/component_id）→ renderPhtmlToPreviewHtml → fetchJson。
- `postRenderPreview`：body → 若 refine_token 则取路径直接 renderFromFile；否则 resolveTemplateContentFromRequest（template_content）→ renderPhtmlToPreviewHtml。

这样「按 id 取内容」与「按 content/token 取内容」在「解析 body → 得到 phtml → 渲染」这条线上共用一套实现，减少复制粘贴。

---

## 步骤五：可选 — 草稿写入与 Complete 构建

- **component-stream** 与 **agent-component-stream** 中「写草稿表」「构建 complete 事件 payload」若仍有重复，可抽成小方法，例如：
  - `saveDraftAndGetId(string $phtmlCode, array $meta): int`
  - `buildStreamCompletePayload(array $component, string $preview, string $refineToken, int $draftId, ...): array`
- 不强制新建 Service，只要控制器内方法短、命名清晰即可。

---

## 执行顺序建议

1. **步骤一**：新增 `AiResponseJsonParser`，实现提取与修复方法（可从 AiGenerate 复制后改为 public/适当入参）。
2. **步骤二**：AiGenerate 中 `parseComponentResponse` 改为调用 Parser + `normalizeComponentPayload`，删除控制器内 JSON 相关私有方法。
3. **步骤三**：替换 debug 日志为统一写法（或先收敛到 `logAgentDebug`）。
4. **步骤四**：在 AiComponent 中实现 `resolveTemplateContentFromRequest` 与 `renderPhtmlToPreviewHtml`，并重构 `postPreviewById`、`postRenderPreview`。
5. **步骤五**：视时间做 stream 内草稿/complete 小方法抽取。

---

## 验收

- AiGenerate 不再包含 `extractJsonFromResponse`、`decodeJsonWithRepair`、`fixControlCharsInJsonStrings`、`repairTruncatedJson`、`extractBalancedBraces` 的实现。
- 现有「component-stream」「agent-component-stream」行为不变（可手动或 E2E 跑一遍：生成组件 → 预览 → 按 id 预览）。
- 预览相关 API（render-preview、preview-by-id）行为不变，返回格式与状态码一致。
- 调试日志不再散落写死 `.cursor/debug.log`，由统一入口或配置控制。
