---
name: extension-points
description: 事件/Hook/Extends 扩展点。事件 dispatch+event.xml；Hook hook.php+<w:hook>；Extends 定义 extends.php、实现 extends/module/。模块间查询用 unified-query-provider。
globs:
  - "**/event.php"
  - "**/Observer/**/*.php"
  - "**/etc/event.xml"
  - "**/hook.php"
  - "**/view/hooks/**/*.phtml"
  - "**/extends.php"
  - "**/extends/**/*.php"
alwaysApply: false
---

# extension-points（极简版·事件+Hook+Extends）

## 何时使用

- 事件、观察者、dispatch、event.xml
- Hook、视图扩展、hook.php、<w:hook>
- 定义/实现扩展点、extends.php、extends/module/

## 1) 事件（Event）

- 命名：`模块名::事件名`；dispatch 第二参数必须变量；观察者 ObserverInterface + event.xml
- 模块间读数据用 **unified-query-provider**，禁止用事件
- 示例：`$d = ['data'=>$x]; $this->eventsManager->dispatch('event', $d);`

## 2) Hook（视图扩展）

- 命名：`{Module}::{area}::{type}::{component}::{position}`；hook.php 定义；模板 `<w:hook name="Name"/>`；实现 view/hooks/
- **命名规范（必守，否则 setup:upgrade 报致命错误）**：
  - `area`: 仅 `frontend` 或 `backend`（小写）
  - `type`: 仅 `partials` 或 `layouts`（小写）
  - `component` / `position`: **仅小写字母和连字符**（`[a-z-]+`），**禁止下划线**。例如用 `ai-usage-stats`，不要用 `ai_usage_stats`
  - 实现文件路径与 hook 名对应：`view/hooks/{Module}/...` 下文件名用连字符，如 `ai-usage-stats.phtml`

## 3) Extends 定义（create-extends）

- 模块根目录 extends.php + extends.md；path/type/interface/description；占位符 {ModuleName}

## 4) Extends 实现（implement-extends）

- extends/module/{目标模块}/{扩展点名}/ 下实现类；实现 Interface；执行 setup:upgrade

## 禁止

- 为查询/获取数据创建事件；dispatch 传字面量数组；定义扩展点不写 doc；实现不实现 Interface
