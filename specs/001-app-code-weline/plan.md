# Implementation Plan: Weline_Ai Phase 1 - 控制器单元测试补充

**Branch**: `001-app-code-weline` | **Date**: 2025-10-12 | **Spec**: [spec.md](./spec.md)  
**Input**: Feature specification from `C:\Users\Administrator\Desktop\Weline\WelineFramework\specs\001-app-code-weline\spec.md`

## Execution Flow (/plan command scope)
```
1. ✅ Load feature spec from Input path
2. ✅ Fill Technical Context
3. ✅ Fill Constitution Check section
4. ✅ Evaluate Constitution Check
5. ⏳ Execute Phase 0 → research.md
6. ⏳ Execute Phase 1 → contracts, data-model.md, quickstart.md
7. ⏳ Re-evaluate Constitution Check
8. ⏳ Plan Phase 2 → Describe task generation approach
9. 🎯 STOP - Ready for /tasks command
```

## Summary

**⚠️ 重要说明**: 本计划为 **Phase 1: 控制器单元测试补充**，是 Weline_Ai 模块完整实施计划的第一阶段。

**Phase 1 目标**: 为 Weline_Ai 模块的所有控制器 URL 补充完整的单元测试，确保符合 Constitution XX (路由测试要求) - 每个控制器必须进行双重测试：PHPUnit单元测试 + http:request HTTP测试。

**当前状态**:
- 控制器总数：22个（Backend: 18个，Frontend: 4个）
- 已测试控制器：1个（Model.php - 部分测试）
- 测试覆盖率：4.5%
- 目标覆盖率：90%+

**Phase 1 技术路径**:
1. 分析所有22个控制器的路由和方法
2. 为每个控制器方法创建 PHPUnit 单元测试
3. 为每个路由创建 http:request 集成测试脚本
4. 确保测试覆盖所有 CRUD 操作、错误场景和边界条件
5. 生成测试报告并验证覆盖率

**后续阶段**（Phase 2-4）:
- **Phase 2**: 完整功能实现（覆盖spec.md中的43个功能需求）
  - 计费系统（FR-025至FR-026）
  - 监控运维（FR-027至FR-028）
  - 国际化UI（FR-029至FR-030）
  - 移动端API（FR-031至FR-032）
  - 第三方集成（FR-033至FR-034）
  - 开发者工具SDK（FR-035至FR-036）
  - 客户支持工单（FR-037至FR-038）
  - 营销工具推广（FR-039至FR-040）
  - 默认模型管理（FR-015至FR-017）
- **Phase 3**: 扩展功能与优化
  - 模型版本管理、部署管理、基准测试
  - A/B测试、安全扫描、性能监控详细功能
  - 商业洞察报表前端展示
- **Phase 4**: 生产化与上线
  - 性能优化与压力测试
  - 安全审计与合规检查
  - 文档完善与培训材料

**参考模块**（Constitution XI.A）:
- `Weline_Queue`: 模型设计、ORM使用模式
- `Weline_Admin`: 控制器测试、Offcanvas UI实现

## Technical Context

**Language/Version**: PHP 8.2+  
**Framework**: WelineFramework (自研框架)  
**Primary Dependencies**: 
- PHPUnit 9.6+ (单元测试)
- WelineFramework ORM (数据访问)
- WelineFramework HTTP (路由和请求处理)

**Storage**: SQLite (开发/测试), MySQL/MariaDB (生产)  
**Testing**: 
- PHPUnit (单元测试框架)
- `php bin/w phpunit:run -b` (测试执行命令)
- `php bin/w http:request` (HTTP端到端测试命令)

**Target Platform**: PHP 8.2+ Web Application  
**Project Type**: Web (Backend + Frontend)  

**Performance Goals**: 
- 测试执行时间 < 30秒（所有测试）
- 测试覆盖率 ≥ 90%
- 每个控制器方法至少3个测试用例（成功、失败、边界）

**Constraints**: 
- 严格遵循 Constitution XX 路由测试要求
- 每个路由必须有 PHPUnit 单元测试 + http:request 测试
- 测试必须使用 Mock 对象隔离外部依赖
- 禁止创建临时测试文件（Constitution II）
- 必须使用 `php bin/w phpunit:run -b` 运行测试

**Scale/Scope**: 
- 22个控制器类
- 估计 100+ 个控制器方法
- 估计 300+ 个测试用例
- 估计 100+ 个 http:request 测试场景

## Constitution Check
*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

### ✅ I. 框架一致性 (Framework Compliance)
- **Status**: PASS
- **Rationale**: 所有测试将严格遵循 WelineFramework 的测试规范和 ORM 使用模式
- **Evidence**: 参考 `Weline_Queue` 等成熟模块的测试实现

### ✅ II. 测试驱动开发 (Test-Driven Development - NON-NEGOTIABLE)
- **Status**: PASS
- **Rationale**: 本计划的核心目标就是补充单元测试，完全符合 TDD 原则
- **Requirements**:
  - ✅ 所有测试文件必须放在 `Test/Unit/Controller/` 目录
  - ✅ 测试类命名遵循 `{ControllerName}Test` 格式
  - ✅ 测试方法命名遵循 `test{MethodName}{Scenario}` 格式
  - ✅ 禁止创建临时测试脚本（如 `test_*.php`）
  - ✅ 使用 `php bin/w phpunit:run -b` 生成详细HTML报告

### ✅ VIII. 测试组织规范 (Test Organization Standards)
- **Status**: PASS
- **Rationale**: 所有测试将按照 PSR-4 规范组织在模块的 Test/Unit 目录
- **Structure**:
  ```
  app/code/Weline/Ai/Test/Unit/Controller/
  ├── Backend/
  │   ├── ModelTest.php (已存在，需完善)
  │   ├── AbTestingTest.php (新建)
  │   ├── AdapterTest.php (新建)
  │   ├── ApiKeyTest.php (新建)
  │   ├── AssistantTest.php (新建)
  │   ├── ContentSafetyTest.php (新建)
  │   ├── CustomerSupportTest.php (新建)
  │   ├── DefaultModelTest.php (新建)
  │   ├── DeveloperToolsTest.php (新建)
  │   ├── InsightsTest.php (新建)
  │   ├── MarketingToolsTest.php (新建)
  │   ├── ModelBenchmarkTest.php (新建)
  │   ├── ModelDeploymentTest.php (新建)
  │   ├── ModelVersioningTest.php (新建)
  │   ├── SecurityScanTest.php (新建)
  │   ├── TestTest.php (新建)
  │   ├── ThirdPartyIntegrationTest.php (新建)
  │   └── TrainingDataTest.php (新建)
  └── Frontend/
      ├── AssistantTest.php (新建)
      ├── CenterTest.php (新建)
      ├── ChatTest.php (新建)
      └── IndexTest.php (新建)
  ```

### ✅ XX. 路由测试要求 (Controller Route Testing Requirements - NON-NEGOTIABLE)
- **Status**: PASS
- **Rationale**: 本计划完全遵循双重测试策略
- **Requirements**:
  #### A. 单元测试要求（PHPUnit - 隔离测试）
  - ✅ 每个控制器路由必须编写对应的单元测试
  - ✅ 测试覆盖：成功请求、参数验证失败、资源不存在、权限验证失败、服务器内部错误、边界条件
  - ✅ 测试文件必须放在 `Test/Unit/Controller/` 目录
  - ✅ 使用 Mock 对象隔离外部依赖

  #### B. HTTP请求测试要求（http:request - 端到端测试）
  - ✅ 每个控制器路由必须使用 `php bin/w http:request` 进行实际HTTP请求测试
  - ✅ 验证HTTP状态码、Content-Type、响应结构、关键字段
  - ✅ 测试场景：GET、POST、PUT/PATCH、DELETE、错误情况
  - ✅ 在PR中附上测试命令和响应内容验证记录

  #### C. 路由收集要求（setup:upgrade - 系统信息更新）
  - ✅ 新建或修改路由后运行 `php bin/w setup:upgrade -m Weline_Ai`
  - ✅ 在 http:request 测试前确保路由已收集

### ⚠️ Complexity Tracking
| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| 无 | N/A | N/A |

## Project Structure

### Documentation (this feature)
```
specs/001-app-code-weline/
├── plan.md              # ✅ This file
├── research.md          # ⏳ Phase 0 output
├── data-model.md        # ⏳ Phase 1 output
├── quickstart.md        # ⏳ Phase 1 output
├── contracts/           # ⏳ Phase 1 output
│   └── controller-tests.json
└── tasks.md             # 🎯 Phase 2 output (/tasks command)
```

### Source Code (repository root)
```
app/code/Weline/Ai/
├── Controller/              # 控制器层 (22个控制器)
│   ├── Backend/            # 后台控制器 (18个)
│   │   ├── Model.php        # ✅ 已部分测试
│   │   ├── AbTesting.php    # ❌ 无测试
│   │   ├── Adapter.php      # ❌ 无测试
│   │   ├── ApiKey.php       # ❌ 无测试
│   │   ├── Assistant.php    # ❌ 无测试
│   │   ├── ContentSafety.php # ❌ 无测试
│   │   ├── CustomerSupport.php # ❌ 无测试
│   │   ├── DefaultModel.php # ❌ 无测试
│   │   ├── DeveloperTools.php # ❌ 无测试
│   │   ├── Insights.php     # ❌ 无测试
│   │   ├── MarketingTools.php # ❌ 无测试
│   │   ├── ModelBenchmark.php # ❌ 无测试
│   │   ├── ModelDeployment.php # ❌ 无测试
│   │   ├── ModelVersioning.php # ❌ 无测试
│   │   ├── SecurityScan.php # ❌ 无测试
│   │   ├── Test.php         # ❌ 无测试
│   │   ├── ThirdPartyIntegration.php # ❌ 无测试
│   │   └── TrainingData.php # ❌ 无测试
│   └── Frontend/           # 前端控制器 (4个)
│       ├── Assistant.php    # ❌ 无测试
│       ├── Center.php       # ❌ 无测试
│       ├── Chat.php         # ❌ 无测试
│       └── Index.php        # ❌ 无测试
└── Test/Unit/Controller/   # 单元测试目录
    ├── Backend/
    │   └── ModelDeleteTest.php # ✅ 已存在 (4个测试方法)
    └── Frontend/
```

**Structure Decision**: WelineFramework 单体应用架构，所有功能模块按 PSR-4 规范组织在 `app/code/` 目录下。测试文件必须放在模块的 `Test/Unit/` 目录并与源文件结构保持一致。

## Phase 0: Outline & Research
*Status: ⏳ In Progress*

### 1. 控制器分析任务
- **Task**: 分析所有22个控制器的路由定义、方法签名和业务逻辑
- **Output**: 控制器方法清单，包含路由URL、HTTP方法、参数、返回类型

### 2. 现有测试模式研究
- **Task**: 研究 `ModelDeleteTest.php` 的测试模式和最佳实践
- **Decision**: 使用 PHPUnit Mock 对象隔离依赖
- **Rationale**: 符合 Constitution XX 单元测试隔离要求

### 3. WelineFramework 测试工具研究
- **Task**: 研究 `php bin/w phpunit:run` 和 `php bin/w http:request` 命令的用法
- **Decision**: 
  - 单元测试：`php bin/w phpunit:run -b --module=Weline_Ai`
  - HTTP测试：`php bin/w http:request GET /ai/backend/model/index`
- **Rationale**: 框架提供的命令更规范且符合 Constitution XIII

### 4. 测试数据准备策略
- **Task**: 确定测试数据的创建和清理策略
- **Decision**: 
  - 使用 PHPUnit 的 `setUp()` 和 `tearDown()` 方法
  - 测试数据使用唯一标识符（如时间戳）避免冲突
  - 测试完成后清理创建的数据
- **Rationale**: 保证测试环境的独立性和可重复性

### Research Output Document
将在 `research.md` 中记录：
1. 控制器方法清单（100+个方法）
2. 测试覆盖策略（优先级、测试场景）
3. Mock 对象设计模式
4. HTTP测试脚本模板
5. 测试数据管理策略

**Output**: ⏳ `research.md` (待生成)

## Phase 1: Design & Contracts
*Prerequisites: research.md complete*

### 1. Data Model (测试数据模型)
将在 `data-model.md` 中定义：
- 测试用例结构（Test Case Entity）
- 测试场景分类（Scenario Types）
- Mock 数据规范（Mock Data Schema）
- 断言规则（Assertion Rules）

### 2. API Contracts (控制器测试契约)
实际存在的 contract 文件：
- `contracts/chat_post.json` - Chat API POST 请求契约
- `contracts/model_get.json` - Model API GET 请求契约
- `contracts/model_copy.json` - Model API 拷贝请求契约
- `contracts/api_key_post.json` - API Key POST 请求契约

**Contract 示例** (`contracts/model_get.json`):
```json
{
  "endpoint": "/api/v1/model/{id}",
  "method": "GET",
  "description": "获取AI模型详细信息",
  "request": {
    "params": {
      "id": "integer"
    }
  },
  "response": {
    "success": {
      "statusCode": 200,
      "body": {
        "model_code": "string",
        "name": "string",
        "supplier": "string",
        "version": "string",
        "is_active": "boolean"
      }
    },
    "error": {
      "statusCode": 404,
      "body": {
        "error": "Model not found"
      }
    }
  }
}
```

### 3. Test Scenarios (测试场景)
从用户故事提取测试场景：
- **模型管理**: 创建、编辑、复制、删除、切换状态、设置默认
- **API密钥管理**: 创建、编辑、删除、配额管理
- **助手管理**: 创建、编辑、删除、MCP工具配置
- **场景适配器**: 注册、应用、验证、性能监控
- **商业洞察**: 数据查询、报表生成、导出

### 4. Quickstart (快速测试指南)
将在 `quickstart.md` 中提供：
```bash
# 1. 运行所有控制器测试
php bin/w phpunit:run -b --path=app/code/Weline/Ai/Test/Unit/Controller

# 2. 运行单个控制器测试
php bin/w phpunit:run -b --name=ModelTest

# 3. 运行单个测试方法
php bin/w phpunit:run --name=ModelTest::testSaveNewModel

# 4. HTTP端到端测试
php bin/w server:start -b
php bin/w http:request GET /ai/backend/model/index
php bin/w http:request POST /ai/backend/model/save --data "name=test&supplier=openai"
php bin/w server:stop

# 5. 查看测试报告
# 访问 http://localhost:9980
```

**Output**: ⏳ `data-model.md`, `contracts/`, `quickstart.md` (待生成)

## Phase 2: Task Planning Approach
*This section describes what the /tasks command will do - DO NOT execute during /plan*

### Task Generation Strategy
1. **从现有 contracts/ 文件生成任务**:
   - 每个 contract 文件 → 对应的测试任务（已在 tasks.md T006-T009 中定义）
   - 每个控制器 → 1个测试文件创建任务 [P]（已在 tasks.md T103-T124 中定义）
   - 每个方法 → 3-5个测试场景任务

2. **任务分组**:
   - **P0 (Critical)**: 核心 CRUD 控制器（Model, ApiKey, Assistant）
   - **P1 (High)**: 业务功能控制器（DefaultModel, Adapter, Insights）
   - **P2 (Medium)**: 扩展功能控制器（AbTesting, ModelBenchmark, SecurityScan）
   - **P3 (Low)**: 管理功能控制器（MarketingTools, CustomerSupport, DeveloperTools）

3. **任务顺序**:
   - 按优先级排序（P0 → P1 → P2 → P3）
   - 同优先级内按依赖关系排序
   - Backend 控制器优先于 Frontend 控制器
   - 单元测试任务优先于 HTTP测试任务

### Ordering Strategy
```
Phase 3.1: Setup (1-2 tasks)
  - T001: 创建测试基础设施和 Mock 工具类
  - T002: 配置 PHPUnit 测试套件

Phase 3.2: P0 Critical Tests (15-20 tasks)
  - T003-T007: Model 控制器完整测试 [P]
  - T008-T012: ApiKey 控制器完整测试 [P]
  - T013-T017: Assistant 控制器完整测试 [P]

Phase 3.3: P1 High Priority Tests (20-25 tasks)
  - T018-T021: DefaultModel 控制器测试 [P]
  - T022-T025: Adapter 控制器测试 [P]
  - T026-T029: Insights 控制器测试 [P]

Phase 3.4: P2 Medium Priority Tests (30-40 tasks)
  - T030-T033: AbTesting 控制器测试 [P]
  - T034-T037: ModelBenchmark 控制器测试 [P]
  - T038-T041: SecurityScan 控制器测试 [P]
  - [... 其他 P2 控制器 ...]

Phase 3.5: P3 Low Priority Tests (20-30 tasks)
  - [MarketingTools, CustomerSupport, DeveloperTools 等]

Phase 3.6: Frontend Controller Tests (15-20 tasks)
  - T0XX-T0XX: Frontend 控制器测试

Phase 3.7: HTTP Integration Tests (30-40 tasks)
  - 每个控制器的 http:request 测试脚本

Phase 3.8: Polish & Validation (5-10 tasks)
  - 测试覆盖率验证
  - 测试报告生成
  - 测试文档完善
```

**Estimated Output**: 150-200 numbered, ordered tasks in tasks.md

**IMPORTANT**: This phase is executed by the /tasks command, NOT by /plan

## Phase 3+: Future Implementation
*These phases are beyond the scope of the /plan command*

**Phase 3**: Task execution (/tasks command creates tasks.md)  
**Phase 4**: Implementation (execute tasks.md following constitutional principles)  
**Phase 5**: Validation (run tests, execute quickstart.md, coverage validation)

### Success Criteria
- ✅ 所有22个控制器都有对应的测试文件
- ✅ 测试覆盖率达到 90%+
- ✅ 所有测试通过 `php bin/w phpunit:run -b --module=Weline_Ai`
- ✅ 每个路由都有 http:request 测试验证
- ✅ 测试文档完整（quickstart.md 可直接使用）

## Complexity Tracking
*Fill ONLY if Constitution Check has violations that must be justified*

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| 无 | N/A | N/A |

## Progress Tracking
*This checklist is updated during execution flow*

**Phase Status**:
- [ ] Phase 0: Research complete (/plan command) - ⏳ Next
- [ ] Phase 1: Design complete (/plan command)
- [ ] Phase 2: Task planning complete (/plan command - describe approach only)
- [ ] Phase 3: Tasks generated (/tasks command)
- [ ] Phase 4: Implementation complete
- [ ] Phase 5: Validation passed

**Gate Status**:
- [x] Initial Constitution Check: PASS ✅
- [ ] Post-Design Constitution Check: PASS
- [ ] All NEEDS CLARIFICATION resolved
- [x] Complexity deviations documented: NONE ✅

**Execution Progress**:
- [x] Step 1: Load feature spec ✅
- [x] Step 2: Fill Technical Context ✅
- [x] Step 3: Fill Constitution Check ✅
- [x] Step 4: Evaluate Constitution Check ✅
- [ ] Step 5: Execute Phase 0 → research.md ⏳
- [ ] Step 6: Execute Phase 1 → contracts, data-model.md, quickstart.md
- [ ] Step 7: Re-evaluate Constitution Check
- [ ] Step 8: Plan Phase 2 → Describe task generation approach
- [ ] Step 9: STOP - Ready for /tasks command

---
*Based on Constitution v2.13.3 - See `.specify/memory/constitution.md`*
