---
name: weline-routing-skill-update
overview: 菜单收集时 router 回退链（backend_router ?: router ?: 模块名）+ weline-routing 技能文档补充。
status: completed
todos:
  - id: menu-collector-fallback
    content: MenuCollector::replaceModuleAction 增加 backend_router ?: router ?: strtolower(module)
  - id: update-routing-skill
    content: weline-routing SKILL.md 新增「模块路由配置」章节并补充 keywords
---

# 路由与菜单 router 回退 + 技能更新计划

**状态**：已完成（status: completed）

## 目标

1. **菜单收集**：`MenuCollector::replaceModuleAction()` 中 `*` 替换使用回退链——后台菜单优先 `backend_router`，无则 `router`，再无则 `strtolower(module_name)`；与 `Module::getRouter()` 语义一致。
2. **技能文档**：在 weline-routing SKILL 中补充模块路由配置与默认行为、菜单 `*` 替换及回退规则。

## 实现步骤

### 1. MenuCollector::replaceModuleAction 回退链

文件：[app/code/Weline/Backend/Service/MenuCollector.php](app/code/Weline/Backend/Service/MenuCollector.php)

- 取 router 时：后台用 `backend_router ?: router ?: strtolower(module)`，前台用 `router ?: strtolower(module)`。
- 避免 `getModuleInfo` 缺项或空字符串时替换成空路径。

### 2. weline-routing SKILL.md

- 在「区域（Area）类型」之后、「URL 解析流程（WLS 模式）」之前新增「模块路由配置」章节。
- 说明：`router`/`backend_router` 配置位置、未配置时默认 `strtolower(module_name)`、菜单 `*` 在收集时被替换及回退顺序。
- frontmatter Keywords 增加：`router`, `backend_router`, `env.php`, `menu`, `action`, `模块路由`, `weline_order`。

## 涉及文件

- [app/code/Weline/Backend/Service/MenuCollector.php](app/code/Weline/Backend/Service/MenuCollector.php)
- [dev/ai/skills/weline-routing/SKILL.md](dev/ai/skills/weline-routing/SKILL.md)
