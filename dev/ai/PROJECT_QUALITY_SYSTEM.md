# 项目质量体系构建文档（初版）

目标：让每次提交都具备“可验证、可追溯、可回滚”的质量闭环；验证优先使用真实入口、HTTP、Browser、WLS、现有命令和文档检查。

> 2026-06-05 覆盖规则：以 `dev/ai/global-constraints.md` 第 10 节为准，默认禁止新增、更新、固化或生成单元测试、测试用例、E2E/Playwright spec、回归用例、fixtures、测试数据或测试脚本。本文历史段落中出现的“单元测试 / e2e / 测试用例 / TestCase”默认解释为“可复现验证入口”，不得作为默认写测试产物的依据；只有用户明确要求测试/用例工作时才允许处理测试产物。

---

## 1. 体系适用范围

本体系覆盖以下变更类型（不限于以下目录）：

- 后端：`Controller/`、`Service/`、`Model/`、`Api/Rest`、`Event Observer`、`Extends`、`Hook`
- 路由与接口可用性：`php bin/w http:request ...`
- 数据库/Schema：使用 `#[Col]/#[Table]/#[Index]` 后执行 `php bin/w setup:upgrade`
- 前端：`view/**`、主题 `view/theme/**`、模板 `.phtml`、前端 `statics/js|css`
- WLS（长驻进程）运行时：代码变更默认 `php bin/w server:reload`，关键启动参数级别改动才 `php bin/w server:restart -r`
- PageBuilder：Workspace/模板/组件渲染链路的回归验证

---

## 2. 质量门禁的三个工程约束

你当前想要的“确保系统正常 + e2e 保障 + 验收完才提交”，可以抽象成三条工程约束：

1. 不把“开发完成”当成“系统正常”：必须有验收断言与可复现证据
2. 不把“局部改动”当成“全局稳定”：必须做分层验证（静态 -> 路由/HTTP -> 链路/集成 -> Browser/WLS/现有命令）
3. 不靠运气发现问题：关键链路必须有可复现验证入口；E2E 用例只在用户明确要求时处理

---

## 3. 质量分层（从快到慢）

质量验收按“成本递增”的顺序执行。每一层的输出都要写入该任务的 `result.md`（或同等验收记录文件）。

### 3.1 静态/约束层（快）

适用：任何改动都要过。

- 遵循仓库既有约束（严格类型、i18n、禁止发明框架能力、ORM 查询必须以 `->fetch()`/`->fetchArray()` 结尾等）
- 允许先做“最小验证”：运行修改涉及模块的真实入口、路由/HTTP、Browser、WLS、现有命令或文档检查。

> 说明：静态检查的自动化脚本我们后续补齐；本初版先把“必须执行哪些命令”固化成流程。

### 3.2 PHP 单元测试（仅用户明确要求）

适用：只有用户明确要求写、补、改或运行单元测试时启用。

- `php bin/w phpunit:run`
- 或更常用：`php bin/w phpunit:run --module=Vendor_Module`

必要时追加：`--coverage`（用于发现覆盖缺口，而不是为了“凑满覆盖率”）。

### 3.3 路由与接口验证（快-中）

适用：新增/修改控制器、路由参数处理、REST 响应格式、权限/认证逻辑、后台关键页面。

- 基础验证口：
  - `php bin/w http:request /`
  - `php bin/w http:request admin -b`
  - `php bin/w http:request rest/v1/module/action -api`
- 错误口过滤：
  - `php bin/w http:request admin -b --filter=Warning`
  - `php bin/w http:request admin -b --filter=Fatal`

> 新控制器（或路由口）相关变更后：务必执行 `php bin/w setup:upgrade --route`。

### 3.4 集成/链路烟测（中）

适用：跨模块协作、复杂页面交互、PageBuilder 的渲染链路、涉及多个服务的工作流。

- 用 `http:request` 组合验证关键链路（尽量覆盖“页面 -> 接口 -> 数据写入/读取 -> 响应格式”）
- 需要后台上下文的链路，使用 `-b` 自动登录

> 本初版不要求你必须写新的“集成测试脚本”；但要求你必须在验收记录里写明“做了哪些链路验证”。

### 3.5 e2e（仅用户明确要求）

适用：涉及用户主链路、后台关键操作链路、跨模块端到端流程，或你认为单元/接口验证不足以覆盖风险的场景。

执行方式（统一从框架约定入口）：

- `cd tests/e2e && npx playwright test --headed`
- 按模块/范围过滤（使用 `--module=Vendor_Module`，或通过环境变量 `MODULE_FILTER` / `PLAYWRIGHT_TEST_FILES`）
- 只有需要 UI/渲染健康时才强制 `--headed`；CI/本地回归默认尽量无头

e2e 的验收口必须明确：例如“首次加载渲染不报错”“关键按钮触发后端接口成功”“页面 URL 与关键 DOM 断言满足预期”等。

---

## 4. 每个任务的验收闭环（推荐固定模板）

每个功能任务必须形成：`plan.md -> 开发 -> result.md（含门禁通过证据）`。

### 4.1 plan.md（写清楚“什么时候算通过”）

最少包含：

- 变更范围：修改了哪些模块/功能点
- 风险点：最可能回归的地方
- 需求清单：把本次工作拆成“可验证的需求项（Requirement）”
- 每个需求项的验收标准（Acceptance Criteria）：每条标准必须能被验证，并且要能追溯到对应验证入口
- 对应验证入口（VerificationEntry）：每个需求项至少 1 个可执行的验证入口（真实入口/HTTP/Browser/WLS/现有命令/文档检查等；单元或 e2e 只在用户明确要求时出现）
- 需要执行的命令清单（从本体系挑）

> 不默认写自动化测试；把“失败条件、验收口、原因”写进 `plan.md` / `result.md`，并保持需求项与验收标准之间可追溯。

#### 4.1.1 需求 -> 验证入口 -> 验收标准（Traceability，强制）

对每个需求项，你必须填写以下三段信息，形成“端到端可追踪”：

1. `RequirementID`：需求项唯一标识（建议用 `REQ-xxx`）
2. `AcceptanceCriteria`：验收标准（建议用 `AC-xxx`，每条可被验证）
3. `VerificationEntry`：验证入口（建议至少写清“用什么命令 / Browser 路径 / 真实入口 / 验证什么断言”）

你可以直接在 `plan.md` 里按下面模板写：

```md
## Requirements

- RequirementID: REQ-001
  - What: （需求一句话描述）
  - AcceptanceCriteria:
    - AC-001: （可验证的标准 1）
    - AC-002: （可验证的标准 2）
  - VerificationEntry:
    - VE-001:
      - Type: http:req|route|link|browser|wls|existing-command
      - CommandOrPath: （例如 php bin/w http:request /path / Browser 路径 / 现有命令）
      - Assert: （命中的断言点，尽量具体）
```

#### 4.1.2 质量体系文档齐全性自检（必填）

为满足“每个计划要询问清楚整个质量体系的建设文档要齐全”的要求，在 `plan.md` 里加入自检区块（开始开发前填写并确认）：

- 已阅读/已确认：`dev/ai/PROJECT_QUALITY_SYSTEM.md`
- 已阅读/已确认：`dev/ai/skills/testing/SKILL.md`
- 已阅读/已确认：`dev/ai/skills/planning/SKILL.md`

如果本次变更还会涉及额外领域约束（例如 WLS、ACL、PageBuilder 等），也要补充“已阅读的对应技能/约束文档”。

### 4.2 开发完成后：执行门禁验收（Gate）

推荐按顺序执行（命令可根据变更类型选择子集）：

1. PHP 单元测试（必选：你改了后端逻辑就必须）
   - `php bin/w phpunit:run --module=Vendor_Module`
2. 路由验证（必选：你改了 Controller/REST/权限/路由口）
   - `php bin/w http:request admin -b`
   - `php bin/w http:request --filter=Warning/Fatal`（按需）
3. e2e（按风险必选）
   - 用户主链路/后台关键链路：运行对应 e2e spec 子集
4. WLS 策略验证（按需）
   - 默认代码变更：`php bin/w server:reload`
   - 只有启动参数级别变更才：`php bin/w server:restart -r`

### 4.3 result.md（写清楚“做了什么 + 结果是什么 + 证据在哪里”）

最少包含：

- 验收执行时间与环境（本地/CI、使用模块过滤条件等）
- 逐需求的验收结果：至少按 `RequirementID -> AC-*` 列出状态 `PASS/FAIL/NEEDS_FIX`
- 执行命令清单与关键输出摘要（不用贴全日志，但必须能证明通过）
- 证据：如果 e2e/链路验证失败，必须写明失败用例名、关键断言点，以及可复现的补充信息（例如 trace/screenshot 的位置或你能再跑一遍的命令）

---

#### 4.3.1 result.md 的可追溯格式（推荐）

```md
## Verification

- RequirementID: REQ-001
  - AC-001: PASS （证据：命令... / spec... / 关键输出...）
  - AC-002: NEEDS_FIX （原因：... / 风险：... / 下一次补测：...）
```

---

## 5. “验收完才提交”的工程化约定（Git 监听点）

本初版先把“必须遵守什么规则”写清楚；脚本化落地（自动识别改动并自动跑对应测试）我们后续再做。

### 5.1 提交前规则（人审门禁，先能执行）

在 `git commit` 前，你必须满足以下任意一种：

- 已更新 `result.md`：并明确标注关键验收标准 `PASS`
- 允许 `result.md` 标注 `NEEDS_FIX`，但必须同步说明原因、风险与后续补测时间点；否则禁止合入主干

### 5.2 推荐的 Git hook / CI 分工（后续可自动化）

你可以把“监听”拆为三层：

1. `pre-commit`（尽量快）：只跑最小集（例如相关模块单元测试）
2. `pre-push`（中等成本）：跑路由验证 + 必要 e2e 子集
3. CI（全量责任）：对 PR/合并请求运行稳定回归集（单元 + 路由 + e2e 必选项）

> 实现细节我们后续补齐：比如从 `git diff` 推断影响模块，然后选择对应 `phpunit/http:request/playwright` 子集执行。

---

## 6. 变更类型 -> 推荐验收组合（速查表）

用于让开发者快速选“该跑哪些”。你写在 `plan.md` 里，并在 `result.md` 里对照填写。

- 只改 i18n/文案：路由验证（如 `http:request admin -b`，必要时按更小范围）
- 只改模板/主题渲染：路由验证 + 涉及关键入口时加 e2e
- 改了 Controller/REST：路由验证（必选）+ 相关模块单元测试（强烈建议）
- 改了 Service/Model：相关模块单元测试（必选）+ 路由/接口验证（按风险）
- 改了 DB Schema：`setup:upgrade`（必选）+ 相关模块单元测试 + 必要 e2e
- 改了 WLS 相关逻辑：必须明确 reload/restart 策略并跑关键链路烟测
- 改了 PageBuilder 工作台/组件渲染：优先跑对应 e2e 断言（首屏与关键交互）

---

## 7. e2e 稳定性要点（你关心的“确保系统正常”）

为了让 e2e 在每次提交后都能稳定复现，验收记录必须强调：

- 运行 e2e 之前保证后端服务可达：必要时先 `php bin/w server:reload` 并等待稳定
- e2e 使用框架封装的统一入口（避免手拼 url 导致断言脆弱）
- 断言尽量基于稳定元素/文本/状态，而不是过度依赖动态 UI 细节
- e2e 失败时：必须把失败用例名、关键断言点、截图/trace（如有）写进 `result.md`，否则下一次定位成本会爆炸

---

## 8. 本初版的交付物与后续迭代方向

初版文档已经把“现有的测试能力入口”和“门禁流程”固化成可执行规范。

后续建议优先补齐：

- 自动从 `git diff` 推断影响模块，并只跑相关子集（减少等待时间）
- 对 e2e 做“关键链路集”与“每次全量回归集”的分层策略
- 对 `result.md` 做结构化校验（例如要求必须包含执行命令与 PASS/FAIL）
- 将 hook/CI 的执行命令与本体系文档绑定，避免文档与脚本漂移

---

## 9. 开发者快速开始（最短动作）

- 新任务开始：先写 `plan.md`，把验收标准与需要执行的命令选出来
- 开发认为完成：跑最小门禁集（单元 + 路由），高风险再加 e2e
- 提交前：没有通过门禁就不要让它处于“可合入状态”（允许失败，但必须明确写缺口与补测计划）

---

## 10. 需求调整的联动更新规则（对子计划/子任务强制）

当你在开发过程中发现需求需要调整（包括但不限于 AC 变更、范围变更、发现缺口导致新增需求项），必须按以下规则同步更新计划涉及的模块的子计划/子任务：

1. 识别变更影响：列出发生变化的 `RequirementID` 与新增/删除的 `AC-*`
2. 影响范围定位：确认哪些模块（以及它们各自的子 plan/task）承接了这些需求
3. 更新计划：对每个受影响模块的 `plan.md`：
   - 更新需求项与验收标准（AC）
   - 更新对应验证入口（VE：命令 / Browser 路径 / 现有入口）
4. 更新验收口：对每个受影响模块的 `result.md`：
   - 标记哪些 AC 需要重新验证（通常重跑对应最低层 + 必要 Browser/WLS/现有命令）
5. 重新执行 Gate：至少重跑“受影响需求项”的最小可验证层（路由/接口/链路/Browser/WLS/现有命令视风险；单元/e2e 仅用户明确要求）

> 简化原则：需求项改了，验收标准和验证入口也必须一起改；只改代码不改计划，视为“缺失验收口”。

