# AI 总则与全局约束

本文档是 WelineFramework 仓库内 AI 规则的唯一总则。入口文件只做索引，专项技能只写本技能独有规则；跨角色、跨工具、跨任务的规则统一维护在这里。

## 0. 加载策略

按需加载，避免把整套提示词塞进上下文。

1. 先读 `AI-ENTRY.md` 和本文档。
2. 再按任务读 `dev/ai/diagrams/00-INDEX.txt`、`dev/ai/diagrams/08-module-docs-index.txt` 中命中的图谱或模块文档。
3. 再读 `dev/ai/skills/_index.md`，只加载 1 到 3 个最相关技能；前端可见任务例外，需同时加载命中的前端技能与 `ui-ux-pro-max`。
4. 最后读取目标源码、现有验证入口和配置；只有用户明确要求测试/用例工作时，才读取或修改测试文件。
5. 不把 `dev/ai/codex/tasks/**`、`dev/ai/archive/**`、历史计划、历史报告当作默认提示词；只有恢复旧任务、查证历史或用户指定时才读。

## 1. 默认工作闭环

- 当前接收用户需求的 AI 是需求 Owner 和最终验收人。
- 默认按“产品经理 -> 架构师 -> 高级工程师 -> 产品经理验收”闭环处理：先明确用户目标、业务目标、权限边界、数据口径、异常状态、可见文案、验收入口，再按框架边界拆解实现。
- 需求不完整时，优先基于仓库上下文做保守假设；涉及数据损失、安全、不可逆操作、大范围返工时才询问用户。
- 子智能体、工具或验证报告只能提供证据，不能替代 Owner 验收。
- 未完成、未验证、证据不足、子任务卡住，都是内部调度状态；除非需要人类裁决，否则继续拆解、修复、验证。
- 最终交付必须逐项回应用户原始需求，说明完成内容、验证证据、未验证项和剩余风险。

## 2. 任务工作区

- 仓库内代码、文档、排查、规则、验证或可持续任务默认必须留下可恢复记录。
- 新任务优先执行：

```bash
php dev/ai/codex/scripts/init-task.php "short title" --source="user request"
```

- 任务目录统一为 `dev/ai/codex/tasks/YYYY-MM-DD/YYYY-MM-DD-HHMM-short-slug/`。
- 开始实质工作前补齐 `task.md`、`plan.md`；过程中更新 `progress.md`；完成、暂停或交接时更新 `result.md` 与任务状态。
- 单任务状态不要写入共享 `ACTIVE.md`，也不要散落在多个共享文件里。

## 3. 工程决策原则

- 先理解，再修改：读相关入口、调用链、配置、现有验证入口和模块文档；测试文件只在用户明确要求测试/用例工作时纳入默认阅读范围。
- 以代码、文档、日志、运行结果和验证证据为准；用户说法与证据冲突时，说明依据并采用可验证结论。
- GitNexus impact 只用于函数、类、方法等代码符号变更；纯文档、规则或索引文件变更不要把文件路径当 symbol 反复调用 impact。若首次返回 `Target not found`，记录为文档变更无符号影响，改用 `git diff` 和定点阅读验收。
- 优先最小必要改动，避免无关重构、无关格式化、无关依赖升级。
- 所有代码、配置、接口、行为、流程、命令、验证方式或规则变更，都必须同步检查相关长期文档是否需要更新；若文档受影响，必须在同一任务内更新对应模块 `doc/`、AI 规则、索引或使用说明，若确认不需要更新，交付时说明判断依据。
- 优先使用框架已有能力：服务层、事件、Hook、Taglib、配置、队列、权限、i18n、ORM、路由生成、模块文档约定。
- 公共接口、权限、安全、支付、数据删除、加密、隐私、生产配置等变更必须额外说明风险和验证方式。
- 不为了“看起来完成”引入假数据、隐藏开关、宽松兼容、静默跳过、临时 fallback 或兜底代码。

## 4. 禁止项

- 禁止直接修改 `generated/`、`view/tpl/**`、`app/code/*/*/view/tpl/**`、`app/design/*/*/*/view/tpl/**` 等生成/编译产物；必须改源模板、服务、Hook、Widget、Taglib、生成规则或构建链路。
- 禁止使用 `routes.xml`；新增控制器后用 `php bin/w setup:upgrade --route` 同步路由。
- 禁止原生 `alert()`、`confirm()`、`prompt()`。
- 禁止硬编码用户可见文案。
- 禁止跨模块直接引用对方内部 PHP 类或内部实现；跨模块协作必须使用契约、Hook、事件、Query Provider、配置、接口、队列或扩展点。
- 禁止任何浏览器侧前后端业务接口绕过 bin-query / `weline-api`；不得使用原生 Ajax/XHR、`fetch`、`$.ajax`、`axios`、`EventSource` 或手写接口 URL 直连后台。
- 禁止用类名白名单、特例分支、路径判断、字符串修补等方式只修症状不修根因。
- 禁止在使用 AI 能力时由调用方直接硬编码模型名、直接实例化模型客户端、直接按模型字段分支或绕过能力匹配；必须通过已发布的 Provider / Adapter / ModelResolver / CapabilityRouter 等适配器契约，根据任务场景、能力需求、配置、租户/模块上下文匹配正确模型。若当前能力缺少适配层，先补适配器或解析契约与文档，再接入 AI 调用。

### 4.1 跨模块协作与 w_query（强制）

- **禁止（跨模块）**：`use`、构造函数/属性注入、`ObjectManager::getInstance`、`new` 等方式引用其他模块的 Service、Model、Helper、Controller 等内部实现类。
- **禁止**：为跨模块读数据创建 Event；为写操作或副作用滥用 `w_query`（写/通知/异步应走 Event、Hook、Queue 或已发布 Interface）。
- **允许白名单**：同模块内（同一 `Vendor_Module`）类互调；框架装配层（Registry、Extends 扫描、DI 绑定）；对方模块已文档化发布的 Interface/契约类型（非 `Service/`、`Model/` 内部类）；PHP 标准库与第三方 SDK。
- **跨模块读数据（强制）**：必须使用 `w_query(provider, operation, params)` 或浏览器侧 `Weline.Api.resource()/graph()/stream()`，不得直接调用对方模块 PHP 类。
- **开发前先查帮助（强制）**：跨模块调用前须执行 `php bin/w query:help <provider|WeShop_Product> [operation]`，或 PHP 侧 `w_query('WeShop_Product')` / `w_query('product')` 查看可用 operations 与参数；不得凭记忆猜测 operation 名。
- **浏览器帮助范围**：`window.w_query(provider)` / `Weline.Query.help()` 仅展示 `frontend=true` 的 operations；完整服务端契约以 PHP `w_query` 或 `query:help` CLI 为准。
- 禁止在 `Setup/Upgrade.php` 做字段 CRUD；字段结构使用 Model `#[Col]` / `#[Index]` 后执行 `setup:upgrade`。
- 禁止在 `<w:*>` 或自定义 Taglib 属性中写 `<?= ?>` 或 `<?php ?>`。
- 禁止在 `.phtml` 中写 `declare(strict_types=1)`。
- 禁止在 WLS 运行时敏感路径使用 `sleep()`、`usleep()`、`die()`、`exit()`。
- 禁止在框架代码中新增或依赖全局变量、`$GLOBALS`、`global $var` 或进程级可变全局状态；`$_SERVER` 只允许 Fiber/WLS 请求上下文装配层临时承接入口上下文，其他框架、模块、模板和服务代码必须通过 `WelineEnv`、`w_env*`、请求对象或显式 `Context` 获取，装配完成后必须收敛为显式 `Context`、请求、会话或服务对象，避免 WLS 长生命周期 worker 跨请求串状态。
- 禁止在 Codex shell 中直接运行阻塞式前台服务或 watcher。
- 禁止在默认 WLS 端口 `9501` 测试 AI 改动；测试实例使用 `9502+` 和唯一实例名。

## 5. 禁止批量机械改代码

- 禁止用跨文件 Replace All、正则替换、`sed`/`awk`、`perl -pi`、PowerShell 批量 `-replace`、管道写回、临时 codemod 或一次性脚本批量修改业务/框架源码。
- 必须按模块和文件逐处阅读、逐处判断、逐处修改；同一文本模式在多处出现时，不默认语义等价。
- 例外只包括用户明确要求批量处理，或仓库已文档化的官方生成/迁移命令；执行后仍须逐文件验收 diff。

## 6. i18n 与用户可见文案

- 用户可见文案必须使用 `__()`、`<lang>` 或框架安全等价形式。
- 默认 source/key 使用简体中文，例如 `__('加入购物车')`；禁止默认写英文 key。
- 占位符使用 `%{1}` 或 `%{name}`。
- `zh_Hans_CN` 与 `en_US` 必须以同一中文 source/key 对齐；涉及 i18n 的改动必须验证两端存在且 `en_US` 不再返回中文。
- 禁止用 HTML 实体、Unicode 转义、拼接字符串、数据属性默认值绕过 i18n。
- PHP 控制器中的 Flash / 会话提示使用 `Weline\Framework\Manager\MessageManager` 静态门面。
- `.phtml` 能用 Taglib / 视图 DSL 时优先使用。

## 7. 前端与请求链路（强制高压线）

- **强制高压线**：所有浏览器侧前后端业务接口必须走 bin-query，也就是 Theme 内置 `weline-api` / `Weline.Api.*`；任何原生 Ajax/XHR、`fetch`、`$.ajax`、`axios`、手写 `query-bin` URL、直连 REST URL 或临时 fallback 都视为违规。
- 业务前端请求必须通过 Theme 的 `theme.js -> weline-api -> worker -> /{rest_frontend}/framework/query-bin -> FrontendQueryGateway -> QueryProvider/service` 链路；`{rest_frontend}` 由 `Env::getAreaRoutePrefix('rest_frontend')` 生成，禁止假定固定为 `api`。
- 后端提供给前端消费的站内业务能力必须发布为 QueryProvider / `frontend=true` operation，并由 `weline-api` 通过 bin-query 调用；不得为了页面交互新建绕过 query-bin 的私有直连接口。
- 站内浏览器业务 API 只能使用 `Weline.Api.resource()`、`Weline.Api.graph()` 或 `Weline.Api.stream()`。
- 禁止在业务 JS、`.phtml`、内联脚本或 API 示例中新增原生 `ajax()`/`XMLHttpRequest`、`fetch(...)`、`fetch(window.api(...))`、`$.ajax`、`axios`、`new EventSource(url)` 或手写 query-bin URL。
- 涉及前端请求、QueryProvider、流式订阅或 worker 链路，必须加载 `dev/ai/skills/前端主题工程师-前端API交互/SKILL.md`。
- 涉及浏览器可见 UI、组件、页面、布局、样式、响应式、状态展示、可用性或审美优化，必须加载命中的 Weline 前端技能和 `dev/ai/skills/ui-ux-pro-max/SKILL.md`。
- `ui-ux-pro-max` 只补设计系统、信息层级、视觉质量和可用性约束；不得覆盖 Weline 的模板边界、layout 约定、i18n、请求链路和验证要求。

## 8. layout 边界

layout 是页面骨架、默认占位和挂载点，不是业务实现层。

允许：

- 默认布局结构 HTML。
- `<w:slot>`、`<w:hook>`、`<w:block>`、`<w:widget>` 的声明与挂载。
- Hook 未命中、插槽为空、无控制器内容时的占位信息。
- 与骨架直接相关的 CSS，以及来自 `meta` / `@param` 的结构级显示开关。

禁止：

- 事件监听、DOM 操作、表单处理、购物车/加购/Tab 切换等交互逻辑。
- ORM 查询、服务编排、权限判断、价格/库存/用户态计算等业务逻辑。
- `fetch` / `XMLHttpRequest` / `axios` / 直连 API URL。
- 临时兼容、假数据、硬编码业务列表。

## 9. WLS 与命令安全

- 长运行 WLS / dev 服务只能后台或 daemon 启动，并使用唯一测试实例名。
- 测试端口使用 `9502+`；默认端口 `9501` 视为生产实例，不得触碰。
- 代码变更后优先 `php bin/w server:reload`；涉及主进程级变更时才执行完整命令 `php bin/w server:restart -r`。
- 禁止在可复制命令中写 `server:reload|restart -r`、`[-b|-api]` 这类管道/可选项混写缩写；PowerShell 会把 `|` 当管道符，必须拆成多条完整命令或改成说明文字。
- 验证命令必须有界，例如 `php bin/w http:request /`。
- 在 PowerShell 中执行 PHP `-r`、one-liner 或包含多层引号/JSON/数组字面量的诊断命令时，默认不要临场拼复杂转义；优先使用临时 `.php` 脚本、PowerShell here-string、已有 CLI 命令或封装脚本，并在交付中给出可复制的最终命令。
- 只有命令足够简单且已确认 PowerShell 解析规则时，才直接使用 `php -r` 内联代码；必要时用 `--%` 停止 PowerShell 参数解析，但必须说明其不适合需要 PowerShell 变量展开的场景。
- 禁止用未显式指定编码的 PowerShell `Get-Content` 读取中文文档；读取中文/UTF-8 文件必须使用 `Get-Content -Encoding UTF8`，避免再次产生 `AI 鎬诲垯...` 这类乱码输出。
- 测试结束必须执行 `php bin/w server:stop -n ai-test-{unique-id}`。
- WLS 长循环、长连接、批量 I/O、大 JSON、模板渲染和进程心跳/READY/IPC 路径不得同步阻塞；需要等待时使用协程调度、短预算非阻塞轮询或 IPC 回调。

## 10. 验证底线

- 不以“已实现”替代“已验证”。
- 默认禁止新增、更新、固化或生成任何单元测试、测试用例、E2E/Playwright spec、回归用例、fixtures、测试数据或测试脚本；只有用户明确要求“写测试 / 补单测 / 写用例 / 更新 E2E”等测试产物时才允许处理。
- 修改业务逻辑时，默认使用真实入口、HTTP、Browser、WLS、现有命令、静态检查或文档检查提供验证证据；修 bug 优先用现有入口复现问题路径，再修复。不要主动新增或更新测试产物来完成复现或验证，除非用户明确要求。
- 涉及路由、页面、接口、运行时行为时，必须提供可复现验证命令。
- 浏览器可见功能最终必须用 Codex 内置 Browser 冒烟：打开目标 URL、确认无明显错误、执行核心交互、核对可见结果。
- 浏览器/桌面自动化不得抢占用户当前桌面焦点：禁止置前 Chrome、切换用户正在使用的窗口，禁止依赖系统焦点、全局键盘输入或鼠标焦点完成操作。
- 需要浏览器操作时，必须通过 Codex Browser、目标标签页的 DevTools/Playwright/Chrome 扩展 tab control 等可寻址自动化接口直接控制标签页；可以新建或复用独立标签页，但不得干扰用户当前键鼠输入。若只能通过 OS 焦点或前台窗口完成，必须停止并说明阻塞点。
- Browser 被 runtime、WLS、端口、认证、证书或环境阻塞时，必须说明阻塞点；不得声称前端流程已完成或已验证。
- 用户可见功能不要默认固化单元测试、测试用例或 E2E 回归；若用户明确要求测试产物，也必须先完成 Browser 冒烟再写入用例。
- 后端机制类功能必须用真实入口、命令、接口、状态或数据结果验证，不能只凭源码存在或注册成功下结论。

## 11. 文档与交付

- 每次变更必须把“文档是否需要同步”作为验收项：先检查 `AI-ENTRY.md` 命中的模块文档索引、相关模块 `doc/`、README/使用说明、配置/命令文档和 AI 规则；文档需要变更时随代码一起提交，不需要时在结果中写明“已检查，无需更新”的依据。
- Bug 修复说明写到相关模块 `doc/`，不要写仓库根目录。
- 只更新长期可复用内容，不写无价值流水账。
- 交付说明必须区分：已改内容、验证证据、未验证项、剩余风险。
- 每次完成开发、修复、部署或可运行功能交付后，最终交付说明必须优先列出本次相关地址：本地/测试/线上页面 URL、后台入口 URL、API endpoint、文档路径/URL、PR/commit/release 地址，以及曾启动的测试实例地址。
- 若没有可直接打开的 live URL，仍必须列出相关路由/路径地址，并明确访问前置条件（例如需要启动 WLS、需要后台登录 Session、需要部署到指定环境等）；只有确实没有任何可访问地址或相关路径时，才写“无可访问链接”。可点击资源优先使用 Markdown 链接格式。
- 验收报告必须来自实际执行结果；禁止用“理论上”“应该可以”“看代码推测”冒充证据。

## 12. SaaS / 部署请求

用户提到“saas”或“部署”时，默认流程：

1. 本地完成修改与验证。
2. 先确认改动所属模块是否由发布目标仓库 `E:\公司\远程\src\weline` 持有；只同步目标仓库已有或用户明确要求迁入的模块，不把源仓库独有模块顺手带入发布仓库。
3. `app/code/GuoLaiRen` 已整体迁移到发布目标仓库，源仓库不再支持 `GuoLaiRen/*` 下任何模块；后续 GuoLaiRen 供应商模块的开发、验证、提交、上线、模块文档和技能维护必须在目标仓库完成。
4. 同步时从源仓库检查目标仓库需要的同名非 GuoLaiRen 模块是否有变更，并把需要的文件同步到目标仓库；`GuoLaiRen/*` 本体不再从源仓库恢复或同步。
5. 在发布工作区提交并推送 `master`。
6. 默认通过本机 OpenSSH 连接已配置的 SAAS 部署目标：使用发布工作区本地 SSH 配置与密钥（`E:\公司\远程\src\weline\.ssh\jumpserver_key`，Windows Generic Credential 目标名 `Weline-SaaS-43.205.103.113-SSH-Key`）进入服务器；推荐命令形态为 `ssh -F E:\公司\远程\src\weline\.ssh\config ec2-user@weline-saas`。若本机 SSH 凭据不可用，停止部署并报告阻塞；不回退到 Chrome / JumpServer / 宝塔。
7. SSH 登录后通过 `sudo -iu weline` 切换到项目部署账户，并进入线上项目目录 `/home/weline-test` 执行 `git pull origin master` 或按 remote 配置更新。
8. 在 `/home/weline-test` 下按改动类型执行 `php bin/w setup:upgrade [--route]`、`php bin/w server:reload` 或 `php bin/w server:restart -r`。
9. 用 `php bin/w http:request /` 或目标页面/API 验证。

部署请求只代表执行交付流程，不授权临时修改业务代码来清理验证失败、单元测试失败或发布门禁提示；除非用户明确要求修复，否则仅记录失败项，并在部署完成后的结果中提示。

已配置 SAAS 部署目标允许从 Codex shell 或本机终端使用上述 SSH 配置执行交付流程；SSH 登录账户为 `ec2-user`，项目部署账户为 `weline`，部署命令必须切换到 `weline` 后在 `/home/weline-test` 执行。SSH 只授权用于进入服务器后按既有部署步骤更新代码、执行升级/重载和验证命令，不授权临时修改业务代码、探测其他服务器、批量删除数据或绕过发布门禁。部署线上环境只能使用上述已配置的本机 OpenSSH 路径；SSH 凭据、配置、网络或权限不可用时停止部署并报告阻塞。禁止回退或改用 Chrome 浏览器中的 JumpServer / Luna Web 终端、宝塔 Web 终端；禁止使用 Codex 内置浏览器执行部署。

禁止回退到线上部署浏览器操作；Chrome / 浏览器只用于部署后的用户可见功能验证，不得接管 JumpServer / Luna / 宝塔 Web 终端执行部署。

禁止把控制台账号、密码、token、cookie、私钥正文或生产连接串写入仓库文档；仓库文档只能记录本机凭据目标名、非敏感 SSH 别名、密钥文件路径和部署流程，不记录私钥正文。

## 13. Git 提交与双端推送

- 仅当用户**明确要求提交**时才执行 `git commit`；不得擅自提交。
- 用户要求提交时，**commit 与 push 视为同一交付步骤**：本地 `commit` 成功后，必须**在同一次任务内**连续推送到 **`origin`（Gitee）** 与 **`github`（GitHub）** 两个 remote；禁止只推一端后结束任务。
- 远程命名约定：`origin` = Gitee，`github` = GitHub；推送前用 `git remote -v` 确认 remote 存在且 URL 正确。
- 标准流程（PowerShell 可用 here-string 传 commit message）：

```bash
git status
git diff
git log -5 --oneline
git add <files>
git commit -m "..."
git push origin HEAD
git push github HEAD
```

- 若当前分支有 upstream 且可能落后，推送前先 `git pull --rebase origin <branch>`（或按仓库既有习惯 merge），再执行双端 push。
- 任一端 push 失败：报告已成功/失败的平台与错误信息，不得声称「已推送完成」；修复后补推失败端，并确认两端一致。
- 禁止 `--force` / `--force-with-lease` 推送到 `main`/`master`，除非用户明确授权。
- 禁止提交含密钥、token、`.env` 等敏感文件；提交前审查 `git diff --staged`。
- 提交前若改动涉及代码符号，按 GitNexus 要求执行 `gitnexus_detect_changes()` 核对影响范围。
- 其他工作区（如分仓目录 `E:\WelineFramework\weline\*`、SaaS 发布目录）若配置了 `origin`/`github` 双 remote，同样遵守本节的 commit + 双端 push 闭环。

## 14. 多智能体协作

- 当前 AI 是 Owner，负责拆分、分派、整合、审查和验收。
- 每次会话默认尽可能开启 6 个子智能体并行分工处理，修复任务也适用；只有修复点明确只有一个、并行会增加额外风险或开销时，才采用串行处理。
- Owner 先拆分可并行的调查、实现、验证、文档或回归检查任务，再整合证据并做最终判断；不要在可安全并行时长期串行推进。
- 子智能体只处理明确边界内的任务；完成后必须交付证据并进入“待验收”。
- 发现问题、风险、阻塞、跨角色影响或验证失败时，通知 `@Weline-技术主管`，并给出影响范围、证据、建议责任智能体、是否阻塞和下一步。
- 不把相邻问题偷偷扩大进当前任务；记录并按优先级处理。
