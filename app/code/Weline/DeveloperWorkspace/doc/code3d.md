# 代码 3D 图（code3d）

把 `app/code` 全部 PHP 渲染成**可查询定位的 3D 层级图**：模块层（国家）→ 命名空间第 N 层（省/市）→ 类（楼）。
属于开发空间（DeveloperWorkspace）的本地开发工具，不参与站点运行时。

## 命令

```bash
php bin/w code3d:analyze                          # 分析并生成（约 30s）
php bin/w code3d:open                              # 缺数据先分析，然后用系统浏览器打开
php bin/w code3d:open --rebuild                    # 强制重建后打开
php bin/w code3d:open --no-open                    # 只生成并打印路径
php bin/w code3d:query --stats --json              # 图统计
php bin/w code3d:query --find=App --limit=5        # 模糊查类
php bin/w code3d:query --class="Weline\Framework\App"
php bin/w code3d:query --method="Weline\Framework\App::runResponse"
php bin/w code3d:query --callers="Weline\Framework\App::dispatch"
php bin/w code3d:query --callees="Weline\Framework\App::dispatch"
php bin/w code3d:query --modules --limit=10
php bin/w code3d:query --flow --limit=20                 # 模块间有向调用流（含互调）
php bin/w code3d:query --flow=namespace --limit=20       # 命名空间粒度
php bin/w code3d:query --coupling="Weline.A>Weline.B"    # 指定两模块的类/方法调用明细
php bin/w code3d:query --layering                        # 分层规则违规（未声明依赖的跨模块调用）
php bin/w code3d:query --layering=Weline.Theme           # 只看某个模块相关的违规
```

所有 query 都支持 `--json`，供脚本 / AI 消费。

## 分层规则

- 允许：任意模块 → `Weline.Framework`（框架是公共依赖）。
- 允许：同模块内部调用。
- 允许：模块 A → 模块 B，当且仅当 A 的 `register.php` 依赖数组里显式声明了 B。
- 其余跨模块调用视为**违规**，并按证据分级：
  - `[直连]` 红：调用目标为非 `\Api\` 命名空间（Service/Model/Block/Controller 等实现类）
  - `[契约]` 橙：调用目标为 `\Api\` 命名空间（接口契约，建议补依赖声明）
  - 测试/夹具文件（`/Test/`、`/test/`、`.script.php`、`/fixtures/`）里的调用**不计违规**，单独统计 `test_calls_ignored`
- `--layering` 输出违规对（次数、静态/实例、互调、类对与方法级样例）；3D「调用流」同色标（红=直连、橙=契约、灰=仅测试、青=已声明、粉=互调）。
- 依赖声明来源：`app/code/{Vendor}/{Module}/register.php` 的 `Register::register(Register::MODULE, …, [deps])`，解析结果写入 `graph.json.meta.mod_deps`。

## 文件

| 路径 | 说明 |
|------|------|
| `Service/Code3d/CodeGraphBuilder.php` | 扫描 + php-parser AST 建图 |
| `Service/Code3d/CodeGraphVisitor.php` | 节点/边/方法调用抽取 |
| `Service/Code3d/CodeGraphStore.php` | 落盘 + 查询（stats/find/class/method/deps/modules） |
| `Console/Code3d/Analyze.php` | `code3d:analyze` |
| `Console/Code3d/Open.php` | `code3d:open` |
| `Console/Code3d/Query.php` | `code3d:query` |
| `view/code3d/template.html` | 查看器模板（3d-force-graph 离线内联） |
| `view/code3d/lib/3d-force-graph.min.js` | 本地库（three 内置） |
| `var/code3d/graph.json` | 查询数据（git 忽略） |
| `var/code3d/code3d.html` | 自包含页面（约 13MB，双击即用，git 忽略） |

## 手工刷新（当前策略）

改码后重跑 `php bin/w code3d:analyze`，浏览器刷新页面即可；不做文件监听。

## 数据口径

- 节点：class / interface / trait / enum / 顶层函数 + 方法清单 + SLOC。
- 继承边 e：extends / implements / use-trait。
- 依赖边 d：`new`、参数/返回/属性类型标注、`Foo::class` 等常量引用、`instanceof`、`catch`。
- 静态调用边 s：`Class::method()`（self/static/parent 已解析）。
- 方法级调用：静态调用 + `$this->method()` + 动态实例调用（推断出接收者类型：属性/参数/构造器提升/`new`/`ObjectManager::getInstance(Foo::class)`/返回类型/docblock `@var/@param/@return`）。
- 实例调用边 `i`（精确类型）与 `u`（接口按 implements/extends 分发到全部实现）；`meta.dyn_calls_resolved` / `meta.dyn_calls_unknown` 给出解析与未知计数。
- **有向调用流**：`--flow[=module|namespace]` 按方向聚合调用次数（静态/实例/接口分列）并标记互调（`mutual` + `reverse_total`）；3D 页面勾「调用流」显示带流向粒子的弧线，互调对为粉色（↺），最多画 Top 400 条。

## 已知边界

- 动态调用：能推断接收者类型的实例调用已解析（属性/参数/构造器提升/`new`/容器 `getInstance(Foo::class)`/返回类型/docblock）；接口类型按实现分发（`u`，会放大到全部实现）。**仍抓不到**：字符串拼方法名、`call_user_func` 变量、模板/Hook 里的调用、反射——这类计入 `meta.dyn_calls_unknown`，「无记录」不代表不存在。
- vendor / PHP 内置类引用不进图（只计数）。
- 语法解析失败的文件会被跳过并在 `code3d:analyze` 输出里列出。
- 图是快照：`graph.json.meta.gen_at` 是生成时间，过期需重跑。

## MCP

`prepare_project` 之后可直接调 MCP 工具 `code_graph_query`（action: stats|find|class|method|callers|callees|deps|modules），
读取同一份 `var/code3d/graph.json`；快照缺失时返回 `CODE_GRAPH_MISSING` 并提示重跑 `code3d:analyze`。
