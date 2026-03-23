---
name: weline-sticker
description: >-
  Weline_Sticker 非侵入式改模板：extends/module/Weline_Sticker 与主题下规则、w:sticker 语法、
  sticker:collect / sticker:refresh、fetch_file 拦截与 generated 产物。在用户提到 Sticker、贴纸、
  w:sticker、非侵入修改其它模块模板、sticker 编译或冲突时使用。
globs:
  - "**/extends/module/Weline_Sticker/**"
  - "**/extends/theme/*/Weline_Sticker/**"
  - "**/Weline/Sticker/**/*.php"
alwaysApply: false
---

# Weline_Sticker（极简版）

## 何时使用

- 不直接改目标模块源码，却要改其 **模板等文件** 的展示或片段
- 用户提到：`Sticker`、`Weline_Sticker`、`w:sticker`、`sticker:collect`、`sticker:refresh`、贴纸规则、编译合并、Sticker 冲突

## 概念

- **Sticker**：在己方模块或主题里写规则文件，由 **编译** 将「目标源文件 + 规则」合并，运行时通过事件 **`Weline_Framework_View::fetch_file`**（观察者 `Weline\Sticker\Observer\TemplateFetchFile`）优先加载 **生成目录** 中的合并结果。
- **不是** Hook：Hook 是插槽注入；Sticker 是 **按目标代码片段** 做 replace / before / after。

## 目录约定（必守）

- **模块级规则**：`<你的模块>/extends/module/Weline_Sticker/<目标模块名>/<与目标文件相同相对路径>`
  - 例（改 `Weline_Sticker` 自身模板）：`app/code/Weline/Sticker/extends/module/Weline_Sticker/Weline_Sticker/view/templates/Test/index.phtml`
- **主题级规则**：`<主题>/extends/theme/<主题名>/Weline_Sticker/<目标模块名>/<相同相对路径>`
- 规则文件路径必须与 **目标模块内该文件路径** 一致（从模块根起的相对路径结构对齐）。
- 扩展点定义见目标模块无关的 **`Weline_Sticker/extends.php`** 与 **`extends.md`**；完整语法与 FAQ 见模块内 **`app/code/Weline/Sticker/doc/usage.md`**、**`extends.md`**。

## 规则语法（w:sticker）

```html
<w:sticker action="replace|before|after" position="all|N|N-M">
    <w:sticker:target>
        （目标文件中要匹配的一段原文，空白会按编译器规则规范化后匹配）
    </w:sticker:target>
    <w:sticker:code>
        （替换内容，或 before/after 时插入的代码）
    </w:sticker:code>
</w:sticker>
```

- **action**：`replace` 替换 | `before` 在目标前插入 | `after` 在目标后追加  
- **position**：`all` 全部匹配；`N` 第 N 处；`N-M` 区间  
- **冲突**：多个 Sticker 对「同一目标片段 + 同一位置索引」的修改会判冲突；可能触发系统通知并写入 `StickerLog`（见 `NotificationService`）

## 编译与 CLI

- 开发环境可依赖 **文件修改时间** 做增量与注册表更新；生产环境应 **显式执行命令**。
- 收集/冲突检测并更新注册表：`php bin/w sticker:collect`
- 全量或按模块刷新编译输出：`php bin/w sticker:refresh`；仅某模块示例：`php bin/w sticker:refresh Weline_Sticker`
- **输出位置**（只读认知，排查用）：`generated/extends/module/Weline_Sticker/`、注册表 **`generated/sticker.php`**

## 硬性禁止（与全局约束一致）

- **禁止手改** `generated/sticker.php` 与 `generated/extends/module/Weline_Sticker/` 下编译产物；改 **源规则文件** 后执行 **collect/refresh**（或依赖开发模式自动流程）。
- 不在 Sticker 规则里用「改 generated」代替写规则。

## 排查「改了不生效」

1. 规则路径是否与目标文件路径 **逐段一致**（含目标模块目录名）。  
2. `generated/sticker.php` 是否包含该文件条目；必要时 `sticker:collect` / `sticker:refresh`。  
3. 目标片段是否与真实源文件一致（空白、属性顺序、小改动会导致匹配失败）。  
4. 是否存在 **冲突** 导致规则未采用（查后台通知 / Sticker 日志）。

## 与其它技能的关系

- 扩展点目录形态与 **extension-points** 中的 Extends 相关，但 Sticker **不要求实现 Interface**，而是 **路径镜像 + w:sticker 规则文件**。  
- 模板开发通用约定（`__()`、禁止在 `<w:*>` 属性里嵌 `<?=` 等）仍遵守 **theme-development** / **i18n-internationalization** / **frontend-components**。
