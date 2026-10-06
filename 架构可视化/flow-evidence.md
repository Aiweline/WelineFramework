# 请求流证据索引（flow-visualizer · 2026-10-05）

所有节点/边均可追溯到源码行。路径前缀 `app/code/Weline/Framework/`。

| 流步骤 | sourceRefs | 置信 |
|---|---|---|
| 入口链 index→bootstrap→runWithRuntime | `index.php:15`, `pub/index.php:12`, `app/bootstrap.php:31-58`, `App.php:1334` | high |
| WLS/FPM 双运行时选择 | `App.php:1334-1343`(Runtime::createRuntime), `Runtime/WlsRuntime.php:53,210,6517,9244`, `FpmRuntime.php:21,34,73` | high |
| 固定阶段序 bootstrap→pre_route_gate→URL→early→before→lazy session→router→after | `Runtime/RequestPipeline.php:12-23(类注释),59-243` | high |
| pre-route 门禁语义（观察者不得派发控制器） | `Framework/event.php` 事件元数据描述；`App.php:163` | high |
| 店面范围冻结先于缓存键 + scope gate 事件 | `App.php:394,459-476` | high |
| **请求上下文计算→冻结→只读**（ScopeIdentity / StorefrontCacheKeyContext 各算一次，下游 current() 直读） | 见 `flow-context-evidence.md` 全表：`App.php:143,491-515,744-792`, `RequestContext.php:541-585,681-697`, `StorefrontCacheKeyContextResolver.php:22-94` | high |
| FPC 探测/命中返回 | `Router/Core.php:1707-1712` | high |
| stale soft-serve（上代 receipt、env 开关 wls.performance.fpc_serve_stale_before_build） | `Router/Core.php:1716-1730` | high |
| 单飞建锁/等待发布 | `Core.php:1736,1757-1815`; `FullPageCacheCoordinator.php:251,321,365,511,535` | high |
| 路由匹配：generated 快路径+统一缓存+事件点 | `Core.php:306,396,408,419,594,618,646-681,724,797` | high |
| Attribute 链可短路 | `Core.php:1840-1852` | high |
| 控制器 DI 实例化 + call_user_func 执行 action | `Core.php:1875,1893` | high |
| Block 渲染：编译模板 include+extract+FiberOutputBuffer | `View/Block.php:76-116` | high |
| hook 执行（priority/sort/solo 元数据、Hooker 兜底） | `View/Template.php:2600-2615`, `Hook/readme.txt`, `SessionManager/view/hooks/account.sidebar.content.phtml`(@hook-priority 示例) | high |
| 回写发布+释放锁 | `Core.php:2006,2033`; `FullPageCacheCoordinator.php:773(canServe),846(canBuild),864(canPublish)` | high |
| after 仅正常路由响应；早退由 finally+RequestResetter 恰好一次清理 | `RequestPipeline.php:12-23 注释` | high |
| 异常→RendererFactory 500 | `app/bootstrap.php:66-77`, `WlsRuntime.php:8038` | high |
| 静态资源 nginx 直出不经 PHP | `pub/cli-server-router.php`(dev)、Server 模块 managed nginx 规则（git log fix(server) 系列）；本次未逐行核验生产 nginx 配置 | medium |

## 边界与未画内容

- **后台路由**（/backendKey/[currency]/[language]/module/controller/action，Core.php:498）结构不同但共享同一 pipeline；现已单独成图 `flow-backend.dot/svg`。
- **异步事件投递**（event.xml delivery=async → Queue）发生在响应后（PostResponseTaskQueue），本图只覆盖同步主链。
- **checkout/paypal 等业务流**属另一条 flow，需要时以 flow-visualizer 单独追。
- WlsRuntime.handle 内部 transport 细节（policy/static/FPC L1 在 pipeline 之外，RequestPipeline.php:12-14 注释）只取语义，未画 worker 纤程调度层。

## 产物与再生成

| 产物 | 说明 |
|---|---|
| `flow-request.dot` / `.svg` / `-preview.png` | 运行逻辑流（阶段图 + 数据面），浏览器/传输/**上下文冻结**/缓存短路/失败旁路一体 |
| `flow-request-sequence.mmd` / `.svg` | 同一链路的泳道时序图（10 个参与者，含 `RequestContext+Context` 与 `ScopeResolver` 两条上下文泳道；两个蓝色 rect 标出"只算一次"的计算段） |
| `flow-backend.dot` / `.svg` / `-preview.png` | 后台请求执行流 + 鉴权链（④b 标出后台走 legacy-default 冻结分支） |
| `flow-context-evidence.md` | **请求上下文生命周期专表**：计算窗口→冻结点→读侧直调证据→受控解冻例外 |
| `index.html` 第 3、4、5 个标签页 | http://127.0.0.1:8931/#flow-request.svg · #flow-backend.svg · #flow-request-sequence.svg |

```bash
cd 架构可视化
/opt/homebrew/bin/dot -Tsvg flow-request.dot -o flow-request.svg
/opt/homebrew/bin/dot -Tpng -Gdpi=130 flow-request.dot -o flow-request-preview.png
/opt/homebrew/bin/dot -Tsvg flow-backend.dot -o flow-backend.svg
/opt/homebrew/bin/dot -Tpng -Gdpi=130 flow-backend.dot -o flow-backend-preview.png
mmdc -i flow-request-sequence.mmd -o flow-request-sequence.svg \
     -p <(printf '{"executablePath":"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome","args":["--no-sandbox"]}')
```

### 绘图踩坑（改图前先看）

- **graphviz 15 要求含括号的属性值加引号**：`A([访客浏览器])`、`DB[("MySQL")]`、`FpcProbe{"..."}` 这类隐式 label 简写会报 `syntax error near '(' / ']'`；一律改成 `A [shape=ellipse, label="..."]` 显式写法。
- **不要给回流边（响应→浏览器）留 rank 约束**：一旦加 `{rank=same}` 打断主链，dot 会把单链摊成横向网，可读性崩坏。当前版本把「响应」统一收到 `Transport` 节点，再用一条 `constraint=false` 的边回客户端，画布从 26×17 收敛到 16.6×22.4 且无交叉。
- **mermaid SVG 根节点是 `width="100%"`**：viewer 里 `parseFloat` 会得到 100px，导致整图被缩到看不见；`index.html` 现在优先读 `viewBox.baseVal`，再回退到 width 属性。
- **上下文"读取边"不要指到渲染节点**：graphviz 会把跨多 rank 的长虚线沿画布边缘绕一大圈（曾把 Ctx→Render 甩成右侧孤立竖线）。改指到主链上较近的节点（前台 Ctrl、后台 CtrlResolve）后线条即收回可视范围。
