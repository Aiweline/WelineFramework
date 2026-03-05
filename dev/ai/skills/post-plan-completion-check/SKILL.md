---
name: post-plan-completion-check
description: |
  Post-plan completion verification for Weline Framework.

  MUST trigger when:
  - User says task/plan is done: 都处理了, 都搞了, 都改了, 做完了, 搞定了, 完成了, 处理完了, 完了
  - User says plan completed: 计划完成, 阶段性完成, 完成计划后, 需求完成
  - User asks for review: 还有问题么, 有没有纰漏, 和别的模块有区别么, 看下还有没有问题
  - User asks about quality: 布局, 主题, 标签, 属性, 有没有遗漏
  - After implementing multiple changes across files (multi-file refactoring)
  - After fixing errors/bugs (post-fix verification)

  ALSO trigger skills update:
  - 更新技能, 更新架构图, 记录更改, 技能命中率, 自学习
  
  Keywords: 完成, 搞定, 处理完, 都处理了, 都改了, 做完了, done, complete, finished,
  还有问题, 纰漏, 遗漏, review, check, 校验, 验证, 质量
globs:
  - "**/SKILL.md"
  - "**/register.php"
  - "**/*.mdc"
alwaysApply: false
---

# 计划/阶段完成后的校验清单

本技能在**计划完成**或**阶段性完成**时触发，对本次改动做一轮与框架规范、一致性、可维护性相关的检查，并给出「已符合」与「待修复/待确认」结论。

## 何时触发

- 用户表示「计划完成了」「阶段性完成」「按计划修完了」
- 用户追问「还有问题么」「有没有纰漏」「和别的模块有区别么」「布局/主题/配色/标签/属性有没有不当」

触发后，按下面清单逐项核对**本次改动涉及的文件与模块**，并输出简要报告。

---

## 一、技能冲突与规范符合性

对照以下技能，只检查**本次改动范围内的代码**：

| 技能 | 检查要点 |
|------|----------|
| **database-model-standards** | 所有 `select/insert/update/delete` 是否链上 `fetch()`；是否出现业务层手写 SQL 或方言；`count()` 若文档未明确「已执行」则标注待确认 |
| **i18n-internationalization** | 用户可见文案是否都用 `__()` 或 `<lang>`；占位符是否用 `%{1}`/`%{name}` 而非 `%1`；模板静态无参文案是否优先 `<lang>` |
| **friendly-notifications** | 是否仍使用 `alert/confirm/prompt`；确认/提示是否改为 AdminToast 或自定义弹窗 |
| **code-generation-standards** | 是否 `declare(strict_types=1);`；是否使用框架提供的 API，未自造方法 |
| **framework-method-validation** | 使用的框架方法（如 Model、Controller、BackendConfig）是否在框架中存在 |

**输出**：列出「已符合」项；若有违反或存疑，标出文件与行号并建议修改。

---

## 二、模块依赖与配置

- **register.php**：本次涉及的模块是否在 `register.php` 的依赖数组中声明了**实际用到的**其他模块（如使用 `Website` 则声明 Weline_Websites，使用 `AiService` 则声明 Weline_Ai，后台页则通常需 Weline_Backend 等）。
- **Schema 变更**：若本次包含表结构变更，是否已在 Model 上用 #[Col]/#[Table] 声明并执行 `php bin/w setup:upgrade`（不再依赖 register.php 版本号触发 Model upgrade）。

**输出**：若缺少依赖或未升版，指出并建议补全/升版。

---

## 三、后端页面一致性（仅当本次改动含后台模板时）

- **布局**：是否与同项目其他后台页一致（如使用 `Weline_Admin::Backend/page-layout/main-content-before.phtml` 与 `main-content-after.phtml`）。
- **主题/配色（theme-development）**：是否仍有硬编码颜色（如 `#fff`、`rgba(0,0,0,0.4)`）；弹窗、卡片、背景等是否改用 `var(--backend-color-*)`、`var(--backend-modal-shadow)` 等主题变量以适配亮/暗色。
- **标签与属性**：  
  - 表单：`<label>` 是否通过 `for="..."` 关联到对应 `id` 的控件。  
  - 自定义弹窗：是否具备 `role="dialog"`、`aria-modal="true"`、`aria-labelledby`（或 `aria-describedby`）等无障碍属性。  
  - 消息/提示区域：若为动态插入内容，是否使用 `role="alert"` 等合适语义。

**输出**：与上述不一致处列出文件与片段，并建议改为与框架/其他模块一致。

---

## 四、逻辑与可维护性建议（可选）

在时间允许下，可顺带检查并简要建议（不强制在本阶段全部实现）：

- **N+1 / 批量查询**：列表页是否存在按行 `load(id)` 取关联数据，可否改为按 ID 列表一次 `where(..., $ids, 'in')` 再映射。
- **重复写入/去重**：定时任务或同步逻辑是否会为同一实体重复插入，是否需要「每键每天一条」等去重或唯一约束。
- **外部请求**：调用第三方 API 时是否有失败重试、限流（如批次间 sleep）、以及错误日志（如 `trigger_error` 或框架 Logger）。
- **失败可追溯**：关键流程（如自动发文）在 catch 中是否至少打日志（含关键 id/关键词等），便于排查。

**输出**：每条建议一句话 + 涉及文件/逻辑位置；标为「建议」而非「必须」。

---

## 五、报告格式

按下面结构输出，只包含**与本次改动相关**的结论：

```markdown
## 计划/阶段完成校验报告

### 一、技能与规范
- 已符合：…
- 待修复/待确认：…（文件:行 或 模块名）

### 二、模块依赖与配置
- 已符合 / 待修复：…（若缺依赖或未升版则说明）

### 三、后端页面一致性（若适用）
- 布局 / 主题 / 标签与属性：已符合 或 待修复（具体位置）

### 四、建议优化（可选）
- 列表项…

### 结论与建议操作
- 优先修复：…
- 可选：…
```

---

## 执行原则

- **范围**：只校验本次计划/阶段**改动到的模块与文件**，不扩大至无关代码。
- **引用技能**：检查时若需具体写法，可引用对应技能（如 database-model-standards、i18n-internationalization）中的规则，不重复抄写全文。
- **不替代专项技能**：本清单是「完成后的综合扫一遍」，具体实现仍须遵守各专项技能（如写 SQL 时遵守 database-model-standards，写样式时遵守 theme-development）。
