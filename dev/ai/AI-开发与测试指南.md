# WelineFramework AI 开发与测试指南（合并精简版）

> 合并自：AI 测试提示词、AI 提示词、AI-常犯错误、AI-监督智能体、AI-前端。仅保留核心规则与要点。

---

## 1. 角色与原则

- **角色**：WelineFramework 开发助手，按框架规范与高度抽象方式开发。
- **必读**：`开发文档.md`、本指南；开发/测试前必阅。
- **开发账号**：后台 `admin` / `admin`；命令行用 `-b` 或 `-api` 自动登录。

### 框架方法验证（最高优先级）

- **禁止创造方法**：所用方法必须能在框架代码/文档中查到。
- **禁止**：`fetchOne()`、`tableColumnExist()` 等不存在或已废弃方法。
- **正确**：Model 单条用 `find()->fetch()`；Connection 用 `query()->fetch()`；表结构用 `#[Col]`/`#[Table]` + `setup:upgrade`。

### ORM 与路由

- **ORM**：`select()`/`find()`/`update()`/`delete()`/`insert()` 必须链式 `->fetch()` 或 `->fetchArray()` 才执行；`save()` 无需 fetch。
- **路由**：禁止 `routes.xml`；控制器放对目录后执行 `php bin/w setup:upgrade --route`。方法名 camelCase→kebab-case；`get*`/`post*`/`delete*` 限定 HTTP 方法。

---

## 2. 测试

### 原则与类型

- 覆盖主流程、界面可用性、数据一致性、错误处理。
- 功能 / 界面 / 数据 / 错误处理 / HTTP 请求 均需验证。

### http:request 常用

```bash
# 前端
php bin/w http:request /
# 后台（自动登录）
php bin/w http:request admin -b
php bin/w http:request ai/backend/model -b
# API
php bin/w http:request rest/v1/module/action -api
# 响应校验与 PHP 错误检测
php bin/w http:request admin -b --filter=Warning
php bin/w http:request admin -b --filter=Fatal
# 并发
php bin/w http:request / -C -t=100
```

- 新控制器后执行：`php bin/w setup:upgrade --route`。

### 前端与质量

- **涉及前端的必须用浏览器 MCP**：navigate → snapshot → click，验证展示与导航。
- **质量**：功能未验证即交付会被责罚；遵守规则加分，重复问题/跳过测试减分；扣分须记入 `AI-常犯错误.md`。

---

## 3. 常犯错误与检查清单

### 代码与配置

| 类别 | 错误 | 正确 |
|------|------|------|
| 模块 | register.php 空或参数缺 | `Register::register(Register::MODULE, 'Name', __DIR__, ...)` 至少前三位 |
| 表结构 | 在 Model 里 hasField/alterTable/tableColumnExist | 用 #[Col]/#[Table]，`setup:upgrade` 同步 |
| 接口 | InstallInterface::setup 签名错 | `setup(Setup $setup, Context $context): void` |
| 子类属性 | 比父类更严或类型不一致 | 与父类一致或更宽松，类型完全一致 |
| 事件配置 | events.xml 或错误命名空间 | `etc/event.xml`，正确 xsd/observer 属性 |
| 类名冲突 | 控制器与 Model 同名 | 用 `use Model\X as XModel` |
| 删除 | 自定义 confirm/alert | 用 `w-delete` 组件，后端 `getParams()` 支持 JSON |
| 后台布局 | 继承 BackendController | 继承 `Weline\Admin\Controller\BaseController`，模板包在 `container-fluid` |
| 详情 | 独立详情页 | Block Offcanvas + AJAX 加载 |
| 配置 | getConfig/getData 读嵌套 | `Env::get('key.subkey', default)`，点号分隔 |

### PHP 8.2+

- `htmlspecialchars($x)` → `htmlspecialchars($x ?? '')`
- `json_decode($x, true)` → `json_decode($x ?? '', true)`
- `addColumn(..., $options)` → `$options` 用 `''` 或 `'not null'`，不用 `null`

### 模板与 i18n

- 模板：`<w:template>` / `<w:theme:template>`，禁止 `$this->fetch()` 直接引模板；禁止内联 style；颜色用主题变量。
- i18n：用户可见用 `__()` 或 `<lang>`；占位符 `%{1}` / `%{name}`；模块翻译放 `模块/i18n/zh_Hans_CN.csv`、`en_US.csv`，无需 register.php。
- 内联 JS：用 `@lang()`；外部 JS 用页面里定义的 `window.i18nTexts`。

### OffCanvas

- `action-params` 用 `{paramName:varName.fieldName}`，配合 `vars="varName"`，禁止 JSON 或 PHP 插值。

### 快速检查项

- [ ] 方法均已在框架中验证存在
- [ ] register.php / event.xml / 菜单 menu.xml 正确
- [ ] ORM 所有写操作与查询后均有 fetch/fetchArray
- [ ] 无 fetchOne、无 routes.xml
- [ ] 后台继承 BaseController；详情用 Offcanvas
- [ ] 删除用 w-delete；Env 用 `Env::get()`
- [ ] PHP 8.2+ 可能 null 的参数已用 `??` 或 `''`

---

## 4. 监督与质量（监督智能体）

- **职责**：提醒并确保其他智能体按规范完成任务并保证质量。
- **质量**：功能必须验证通过；全面运用单测、集成、手动、浏览器 MCP、代码审查等。
- **卓越标准**：以结果为导向、直接给方案；报错必修；一次性完成率与测试通过率为准；禁止“我会尽力”、连续三次不满意。
- **文档**：开发需求文档放模块 `doc/`；新知识写入开发文档。
- **扣分**：记录到 `AI-常犯错误.md`（原因、影响、修复）。

---

## 5. 前端响应式协议（PC / iPad / Mobile）

### 三端单属性

格式：`<selector> , <property> , <pc_value> , <ipad_value> , <mobile_value>`  
逗号可用中英文；无单位默认 `px`。  
断点建议：Mobile ≤767px，iPad 768–1024px，PC ≥1025px。

- **媒体查询**：默认写 Mobile，再按断点覆盖 iPad/PC。
- **clamp 插值**（如 font-size）：  
  `clamp(mobile, calc(mobile + (pc - mobile) * ((100vw - 375px) / (1280 - 375))), pc)`  
  流式区间可统一为 375px–1280px。

### 选择器 + 三段 CSS

输入：`<selector>` 后跟 `---PC---` / `---iPad---` / `---Mobile---` 三段 CSS，或单行 `||` 分隔。  
可插值属性（同单位数值）→ clamp；其余（颜色、display 等）→ 媒体查询。同一断点合并到一个 `@media` 块中书写。

---

## 6. 参考

- 开发文档.md  
- AI-常犯错误.md（扣分与详细错误记录）  
- .cursor/rules、dev/ai 下技能与规则

*合并精简版，详细示例与历史扣分见原单文件。*
