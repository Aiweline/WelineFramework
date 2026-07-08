# AI 作图 — 需求说明

> 模块：`Weline_MediaManager`  
> 状态：需求已整理（待开发）  
> 关联入口：后台 `内容管理 > 媒体管理 > 文件管理`（`media/backend/manager`）  
> 关联模块：`Weline_Ai`（场景适配器与文生图/图生图能力）、`Weline_FileManager`（媒体根目录与安全约束）  
> 背景：在现有媒体文件管理器中增加 **「AI 作图」** 能力，支持 **文生图、图生图、多轮对话修图、批量生成**；通过 SSE 实时展示进度，保存时可 **覆盖原图** 或 **另存为新文件**。

---

## 1. 背景与目标

### 1.1 现状

| 能力 | 现状 | 缺口 |
|------|------|------|
| 文件管理 UI | `MediaManager` 提供浏览/上传/下载/删除/重命名/预览 | 无 AI 图片生成与修图入口 |
| 媒体目录 | 根目录 `pub/media`，已有 `ai-generated` 等业务子目录 | 无「生成/修图后直接落盘」闭环 |
| AI 文生图 | `Weline_Ai` 提供 `AiService::generateImage()`、`w_query('ai','generateImage')` | MediaManager 未接入 |
| AI 图生图 | 供应商层通过 `params.image` / `params.reference_image` 传入参考图（PageBuilder 已用） | MediaManager 未提供选图修图 UI |
| 场景适配器 | 各业务模块在 `extends/module/Weline_Ai/Adapter/` 声明适配器 | MediaManager **尚无专属适配器** |
| 实时反馈 | 框架提供 `SseWriter` | MediaManager 前端仅 XHR 轮询 connector |

### 1.2 目标

1. 工具栏增加 **「AI 作图」** 按钮；文件网格/右键菜单增加 **「AI 修图」**（选中图片时）。
2. 弹窗支持四种模式：
   - **文生图**：纯 prompt 生成新图；
   - **图生图**：基于当前选中媒体文件作为参考图生成；
   - **多轮对话修图**：在同一会话内连续输入修改指令，以上一轮结果为下一轮的参考图；
   - **批量生成**：一次提交多个 prompt（或同一 prompt 生成 N 张），SSE 逐张推送进度与预览。
3. 全流程 **SSE 实时反馈**：校验 → 读取参考图 → 生成中 → 预览就绪（批量时按 `batch_index` 推送）。
4. 保存时用户选择：
   - **覆盖原图**（仅图生图/修图且存在明确源文件时可用）；
   - **另存为新文件**（默认；写入当前目录，可改文件名）。
5. 生成走 `Weline_Ai` **场景适配器 + text2image 模型**；图生图通过 `params.reference_image` 等参数传递，不在 MediaManager 硬编码供应商。
6. 运维在 **AI → 场景适配器** 配置 MediaManager 适配器及 text2image 模型。

### 1.3 非目标（本期不做）

- 不在 MediaManager 内实现新的 AI Provider；供应商能力统一复用 `Weline_Ai`。
- 不做局部蒙版/inpainting 手绘选区（首期整图参考 + prompt 修图；若模型支持 mask 参数可二期扩展）。
- 不替代 PageBuilder / Theme 等业务模块各自的图片资产生成适配器。
- 不在 iframe 选择器模式强制暴露全部能力（首期可仅独立管理页；修图入口可后续同步）。

---

## 2. 用户故事

| 角色 | 场景 | 期望结果 |
|------|------|----------|
| 运营 | 在 `ai-generated` 下从零生成 Banner | 文生图 → SSE 进度 → 预览 → 另存为新文件 |
| 运营 | 选中已有产品图，改背景/风格 | 右键「AI 修图」→ 图生图 → 多轮输入「换成白色背景」「再亮一点」→ 满意后 **覆盖原图** 或另存 |
| 设计 | 一次生成 4 张候选封面 | 批量模式，输入 4 条 prompt 或「生成 4 张」→ SSE 逐张出预览 → 勾选满意的批量另存 |
| 运维 | 更换文生图模型 | AI 后台改适配器 `text2image` 绑定，无需改代码 |
| 开发 | 模型未绑定/余额不足 | SSE 返回明确错误码与中文提示，目录无脏文件 |

---

## 3. 信息架构与入口

### 3.1 入口一览

| 入口 | 触发条件 | 默认模式 |
|------|----------|----------|
| 工具栏「AI 作图」 | 任意时刻可点 | 文生图；若已选中 1 张图片则默认 **图生图** 并预填参考图 |
| 右键「AI 修图」 | 选中 1 张 `image/*` 文件 | 图生图，源文件锁定为选中项 |
| 预览面板「AI 修图」 | 预览区当前为图片 | 同右键 |
| 批量：工具栏 / 弹窗 Tab | 用户切换到「批量生成」 | 批量文生图（可选是否绑定同一参考图） |

### 3.2 路由与文件

- 页面：`media/backend/manager`
- 模板：`view/templates/Backend/Manager/manager.phtml`
- 脚本：`view/statics/js/manager.js`
- 工具栏按钮：`#mmf-btn-ai-draw`

### 3.3 按钮可见性

- 独立管理页默认显示。
- 只读存储源或无写权限时，**保存/覆盖** 置灰；生成预览仍可进行（若 ACL 允许生成）。
- 选中多个文件时：工具栏打开弹窗为 **批量/文生图**；右键「AI 修图」仅在 **恰好选中 1 张图片** 时显示。

---

## 4. AI 场景适配器（本地适配器）

### 4.1 适配器路径

```
app/code/Weline/MediaManager/extends/module/Weline_Ai/Adapter/MediaManagerAiDrawAdapter.php
```

由 `php bin/w ai:adapter:scan` 或 `setup:upgrade` 注册。

### 4.2 适配器规约

| 属性 | 值 |
|------|-----|
| `getCode()` | `media_manager_ai_draw` |
| `getName()` | 媒体管理 AI 作图适配器 |
| `getDescription()` | 为文件管理器提供文生图、图生图、多轮修图与批量生成的场景约束与模型绑定 |
| `getSupportedModelTypes()` | `['text2image']` |
| `getDefaultModelBindings()` | `['text2image' => '<环境默认模型>']` |

> **说明**：框架当前以 `text2image` 模态承载文生图与图生图；图生图通过 `params.reference_image` / `params.image` 传入参考图 URL 或 base64，与 PageBuilder `AiSiteAutoAssetGenerationService` 一致。

### 4.3 提示词适配

`adaptPrompt()` 按 `params.mode` 分支：

| mode | 契约要点 |
|------|----------|
| `text2image` | 单张可用素材；无水印/UI 截图；按 size 构图 |
| `image2image` | 保留参考图主体结构与构图意图，仅按 prompt 修改指定方面 |
| `edit_turn` | 多轮修图：在上轮结果基础上做 **增量修改**，避免无关重绘 |
| `batch` | 与 text2image/image2image 相同，追加「本批次第 N/M 张，保持系列风格一致」（可选） |

`validateParams()`：

- `prompt` 非空（批量时每条非空），上限 2000 字符/条。
- 图生图/修图：`source_file_hash` 或 `reference_image` 至少其一有效。
- 批量：`batch_count` 1–8，或 `prompts[]` 长度 1–8。
- `size` / `aspect_ratio` / `output_format` 在允许列表内。

### 4.4 后台配置

```
后台 → AI → 场景适配器 → 「媒体管理 AI 作图适配器」
```

必须：适配器启用 + `text2image` 绑定激活模型 + 供应商账户可用。

未就绪时 SSE `error`：

> AI 场景适配器未就绪，请先在后台「AI → 场景适配器」为「媒体管理 AI 作图适配器」绑定 text2image 模型。

---

## 5. 功能需求

### 5.1 作图弹窗（Modal）

#### 5.1.1 布局

| 区域 | 内容 |
|------|------|
| 标题栏 | 「AI 作图」+ 模式 Tab（文生图 / 图生图 / 批量）+ 关闭 |
| 参考图区（图生图/修图） | 源图缩略图、文件名、更换参考图、清除 |
| 对话区（多轮修图） | 会话消息列表（用户指令 + assistant 预览缩略图）；最新一轮为大预览 |
| 输入区 | 当前轮 prompt（多行）、尺寸、比例、格式、负面提示词；批量 Tab 为 prompt 列表或「生成数量」 |
| 预览区 | 当前结果大图；批量时为网格缩略图 + 选中态 |
| 底栏 | 「生成」「继续修图（下一轮）」「重新生成」「保存…」「取消」 |

#### 5.1.2 模式说明

**文生图**

- 无参考图；保存仅 **另存为新文件**。

**图生图**

- 参考图来源（优先级）：
  1. 右键/预览进入时的 `source_file_hash`；
  2. 弹窗内「从当前目录选择」；
  3. 多轮修图时的 **上一轮生成结果**（内存预览，未保存前作为 reference）。
- 读取参考图：经 connector `cmd=file` 或后端按 hash 读 `pub/media`（服务端读盘，不把绝对路径暴露给前端）。

**多轮对话修图**

- 会话 ID：`session_id`（UUID），服务端保留最近 N 轮（建议 N≤10）的 prompt + 生成结果临时 ID。
- 流程：
  1. 第一轮：图生图或文生图；
  2. 用户输入新 prompt → 点「继续修图」→ 以上一轮 `generation_id` 对应图片为 `reference_image` 再生成；
  3. 对话区展示历史轮次，可点击某轮预览设为「当前工作版本」（不回滚已保存文件）。
- 关闭弹窗未保存：丢弃会话临时文件；已保存轮次不影响磁盘原文件。

**批量生成**

- 两种方式（UI 二选一或并存）：
  - **多 prompt**：文本框内多条 prompt（每行一条，最多 8 条）；
  - **同 prompt 多张**：同一 prompt + `batch_count`（1–8）。
- 可选：整批共用一张参考图（图生图批量）。
- SSE 按序生成（**串行**，避免压垮供应商；UI 展示 `2/8 已完成`）；单张失败不中断整批，记录失败项可重试。
- 保存：勾选多张 → **批量另存**；不支持批量覆盖（覆盖仅单张且明确源文件）。

#### 5.1.3 默认参数

| 参数 | 默认值 | 说明 |
|------|--------|------|
| `size` | `1024x1024` | 与参考图比例差异大时提示可能裁切 |
| `aspect_ratio` | `1:1` | 与 size 联动 |
| `output_format` | `png` | png / webp / jpeg |
| `target_directory` | 当前 `CWD_HASH` | 只读展示 |
| `batch_count` | `1` | 批量模式 |

#### 5.1.4 交互规则

- 生成中禁用保存；批量时允许取消剩余未开始的任务。
- 「继续修图」仅在当前轮 `complete` 后可用。
- 关闭弹窗前若有未保存生成结果，二次确认（框架统一确认组件，禁止 `confirm()`）。

---

### 5.2 保存策略（覆盖 / 另存）

#### 5.2.1 保存对话框

生成成功后点击「保存」，弹出保存选项：

| 选项 | 条件 | 行为 |
|------|------|------|
| **覆盖原图** | 存在 `source_file_hash` 且用户有写权限 | 用新字节替换原文件；扩展名可随 `output_format` 变化时需提示「覆盖后文件名/类型可能变化」 |
| **另存为新文件** | 始终可用 | 写入 `target_directory`，默认名 `ai-draw-{timestamp}-{id}.png`，可编辑 |

批量保存：

- 仅 **另存为新文件**；
- 默认前缀 `ai-draw-batch-{index}-`；
- 可统一改目录（仍默认当前 CWD，弹窗内不改目录）。

#### 5.2.2 覆盖原图规则

1. 仅允许覆盖 **本次会话声明的源文件**（`source_file_hash`），禁止跨文件覆盖。
2. 走 `ConnectorService` 路径校验；写前可选备份至 `var/tmp/media-ai-draw/backup/`（实现建议，便于误操作恢复）。
3. 覆盖成功后刷新列表，预览指向同一 hash（文件内容已变）。
4. SVG 等非位图参考源：**不允许覆盖**（仅允许另存为），避免破坏矢量源文件。

#### 5.2.3 接口

| 项 | 说明 |
|----|------|
| 路由 | `media/backend/ai-draw/save`（`POST`） |
| 请求体 | 见下表 |

| 字段 | 必填 | 说明 |
|------|------|------|
| `generation_id` | 是 | 单次保存 |
| `generation_ids[]` | 批量 | 与 `generation_id` 二选一 |
| `save_mode` | 是 | `overwrite` \| `save_as` |
| `source_file_hash` | 覆盖时 | 必须与生成时一致 |
| `target` | 另存时 | 目录 hash |
| `filename` | 否 | 另存文件名 |
| `filenames[]` | 批量 | 与 generation_ids 对应 |

响应：

- 另存：`{ added: [fileInfo, ...] }`
- 覆盖：`{ updated: [fileInfo] }`

---

### 5.3 SSE 实时作图

#### 5.3.1 接口

| 项 | 说明 |
|----|------|
| 路由 | `media/backend/ai-draw/stream` |
| 方法 | `POST` |
| 响应 | `text/event-stream`（`SseWriter`） |
| 前端 | `Weline.Api.stream()` |

#### 5.3.2 请求体（核心字段）

| 字段 | 说明 |
|------|------|
| `mode` | `text2image` \| `image2image` \| `edit_turn` \| `batch` |
| `prompt` | 当前轮 prompt |
| `prompts[]` | 批量多 prompt |
| `batch_count` | 同 prompt 批量数量 |
| `session_id` | 多轮修图会话 |
| `parent_generation_id` | 多轮时上一轮结果 ID |
| `source_file_hash` | 图生图源文件 |
| `target` | 目录 hash（用于上下文展示） |
| `size`, `aspect_ratio`, `output_format`, `negative_prompt` | 生成参数 |

#### 5.3.3 服务端流程

```
1. 鉴权 + sse.start()
2. sendEvent('start', { mode, session_id, batch_total? })
3. 校验适配器与模型
4. 若图生图：sendEvent('progress', { stage: 'loading_reference' })
   → 从 source_file_hash / parent_generation_id 解析参考图 bytes → params.reference_image
5. 批量：loop i in 1..batch_total
     sendEvent('progress', { stage: 'generating', batch_index, batch_total })
     → AiService::generateImage($prompt, null, 'media_manager_ai_draw', $params)
     → 暂存 generation_id[i]
     → sendEvent('preview', { batch_index, generation_id, data_url, ... })
6. sendEvent('complete', { generation_id | generation_ids[], session_id })
   或 sendEvent('error', { code, message, batch_index? })
7. sse.close()
```

#### 5.3.4 SSE 事件契约

| 事件 | 载荷 | 说明 |
|------|------|------|
| `start` | `mode`, `session_id`, `batch_total`, `source_file` | 流开始 |
| `progress` | `stage`, `message`, `batch_index`, `batch_total`, `percent?` | `validating` / `loading_reference` / `generating` / `normalizing` |
| `preview` | `generation_id`, `batch_index`, `data_url`, `mime_type`, `revised_prompt?` | 单张就绪 |
| `complete` | `generation_id` 或 `generation_ids[]`, `session_id` | 本轮/整批完成 |
| `error` | `code`, `message`, `batch_index?` | 失败 |
| `heartbeat` | （框架默认） | 保活 |

#### 5.3.5 临时存储

- 单次/批量/多轮结果：`var/tmp/media-ai-draw/{session_id}/`
- `generation_id` → 临时文件 + meta.json（mime、source_file_hash、prompt、mode）
- TTL 建议 2 小时；定时或请求结束时清理未保存文件
- 多轮会话：服务端 `AiDrawSessionStore`（内存+文件，绑定 admin_id）

---

## 6. 权限与安全

### 6.1 ACL 建议

| 权限 | 说明 |
|------|------|
| `Weline_MediaManager::file_manager` | 文件管理入口 |
| `Weline_MediaManager::ai_draw` | 发起生成/修图/批量 |
| `Weline_MediaManager::ai_draw_save` | 另存为新文件 |
| `Weline_MediaManager::ai_draw_overwrite` | 覆盖原图（可单独收紧） |

### 6.2 安全红线

- 参考图读取必须经 hash 解析，禁止客户端传绝对路径。
- 覆盖仅允许 `source_file_hash` 与生成时备案一致。
- 批量上限 8，防止滥用。
- 会话隔离：仅创建者可读写 `session_id`。
- 提示词不回显为 HTML；预览用 data URL 或受信临时 URL。

---

## 7. 技术方案摘要

### 7.1 新增/修改文件

| 类型 | 路径 |
|------|------|
| 场景适配器 | `extends/module/Weline_Ai/Adapter/MediaManagerAiDrawAdapter.php` |
| 控制器 | `Controller/Backend/AiDraw.php`（`postStream`, `postSave`, `getConfig`） |
| 服务 | `Service/AiDrawService.php` |
| 会话存储 | `Service/AiDrawSessionStore.php` |
| 模板/样式/脚本 | `manager.phtml`, `manager.css`, `manager.js` |
| i18n | `zh_Hans_CN.csv`, `en_US.csv` |
| 测试 | `test/e2e/backend/Weline_MediaManager-ai-draw.spec.js` |

### 7.2 调用链

**图生图 / 多轮**

```
postStream(image2image | edit_turn)
  → resolveReferenceBytes(source_file_hash | parent_generation_id)
  → params.reference_image = base64 或内部 URL
  → AiService::generateImage(..., 'media_manager_ai_draw', params)
```

**批量**

```
postStream(batch)
  → for each item: generateImage → preview event
  → complete with generation_ids[]
```

**保存**

```
postSave(save_mode=overwrite)
  → verify source_file_hash == meta.source_file_hash
  → write bytes to resolved path

postSave(save_mode=save_as)
  → ConnectorService 同级校验 → 新文件
```

### 7.3 前端要点

- 右键菜单新增 `data-action="ai-edit"`（单选图片）。
- 弹窗状态机：`idle → generating → preview → saving`。
- 多轮：`session_id` 持久于弹窗生命周期，每轮 `parent_generation_id` 链式传递。
- 批量：监听 `preview` 累积缩略图 grid；保存时收集勾选项的 `generation_id`。
- 保存弹窗：Radio「覆盖原图 / 另存为新文件」，覆盖时展示源文件名确认。

---

## 8. 国际化（示例）

| 场景 | 中文 |
|------|------|
| 按钮 | AI 作图 |
| 右键 | AI 修图 |
| Tab | 文生图 / 图生图 / 批量生成 |
| 多轮 | 继续修图 / 对话历史 |
| 进度 | 正在读取参考图… / 正在生成第 2/4 张… |
| 保存 | 覆盖原图 / 另存为新文件 |
| 确认覆盖 | 将用新图片替换「{filename}」，此操作不可撤销。 |
| 成功 | 已覆盖原图 / 已保存 3 个文件到当前目录 |

---

## 9. 错误处理

| 场景 | 处理 |
|------|------|
| 未选参考图却走图生图 | 前端校验；服务端 `REFERENCE_REQUIRED` |
| 源文件非图片 | `INVALID_SOURCE_MIME` |
| 覆盖 SVG | 拒绝，`OVERWRITE_NOT_ALLOWED` |
| 批量中单张失败 | 该张 `error`，其余继续；complete 带 `partial: true` |
| 会话过期 | `SESSION_EXPIRED`，提示重新打开 |
| 同名另存 | 冲突错误，提示改名 |

---

## 10. 验收标准

### 10.1 文生图

- [ ] 工具栏打开弹窗，纯 prompt 生成并另存成功。

### 10.2 图生图

- [ ] 选中 1 张图片 → 右键 AI 修图 → 参考图正确 → 生成结果可预览。
- [ ] 保存可选覆盖或另存；另存后原文件不变。

### 10.3 多轮修图

- [ ] 第一轮生成后，输入「继续修图」第二轮 prompt，结果基于上一轮。
- [ ] 对话历史可查看；关闭未保存不改动磁盘。

### 10.4 批量生成

- [ ] 4 条 prompt 或 count=4，SSE 逐张 preview，进度 1/4…4/4。
- [ ] 勾选 2 张批量另存，目录出现 2 个新文件。

### 10.5 覆盖原图

- [ ] 图生图模式下覆盖原图，文件 hash 不变、内容更新、预览刷新。
- [ ] 无源文件时覆盖选项不可见或置灰。

### 10.6 安全

- [ ] 路径穿越、跨文件覆盖、越权 session 均被拒绝。

---

## 11. 参考实现

| 参考 | 路径 | 借鉴点 |
|------|------|--------|
| 图生图参数 | `GuoLaiRen/PageBuilder/Service/AiSiteAutoAssetGenerationService.php` | `reference_image` / `image` |
| 文生图 | `Weline/Ai/Service/AiService.php::generateImage()` | 模型选择 |
| 图片字节 | `GuoLaiRen/PageBuilder/Queue/AiSiteAssetQueue.php` | b64 / url 解析 |
| SSE | `Weline/Framework/Http/Sse/SseWriter.php` | 事件推送 |
| 写盘安全 | `Weline/MediaManager/Service/ConnectorService.php` | hash 与 sanitize |
| 场景适配器 | `GuoLaiRen/PageBuilder/extends/.../AiSiteAssetsAdapter.php` | 适配器结构 |

---

## 12. 里程碑建议

| 阶段 | 内容 |
|------|------|
| M1 | 适配器 + 文生图 + 另存 |
| M2 | 图生图 + 选图入口 + 覆盖/另存对话框 |
| M3 | 多轮修图会话 + SSE |
| M4 | 批量生成 + 批量另存 |
| M5 | i18n / ACL / E2E |

---

## 13. 待确认项

1. **iframe 模式**是否同步开放图生图/修图？
2. **批量上限** 8 是否满足？是否需要可配置？
3. **覆盖前备份**是否默认开启？

---

**维护者**：WelineFramework Team  
**创建日期**：2026-07-08  
**修订日期**：2026-07-08（扩展：图生图、多轮修图、批量、覆盖/另存）  
**关联文档**：`doc/README.md`、`doc/AI-INDEX.md`、`Weline_Ai/doc/开发/AI模块开发文档.md`
