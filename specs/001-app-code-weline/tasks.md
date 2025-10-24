# Tasks: Weline_Ai Module

**Input**: Design documents from `/specs/001-app-code-weline/`
**Prerequisites**: plan.md (required), research.md, data-model.md, contracts/, quickstart.md

## Execution Flow (main)
```
1. Load plan.md from feature directory
   → ✅ Found: WelineFramework AI module implementation plan
   → Extract: PHP 8.2+, WelineFramework, Redis, Queue, MySQL/SQLite
2. Load optional design documents:
   → ✅ data-model.md: 23 core entities + 10 extended entities → model tasks
   → ✅ contracts/: 4 contract files → contract test tasks
   → ✅ research.md: 14 technical decisions → setup tasks
   → ✅ quickstart.md: 6 user stories → integration test tasks
3. Generate tasks by category:
   → Setup: project init, dependencies, linting
   → Tests: contract tests, integration tests
   → Core: models, services, controllers
   → Integration: DB, middleware, logging
   → Polish: unit tests, performance, docs
4. Apply task rules:
   → Different files = mark [P] for parallel
   → Same file = sequential (no [P])
   → Tests before implementation (TDD)
5. Number tasks sequentially (T001, T002...)
6. Generate dependency graph
7. Create parallel execution examples
8. Validate task completeness:
   → All contracts have tests? ✅
   → All entities have models? ✅
   → All endpoints implemented? ✅
9. Return: SUCCESS (tasks ready for execution)
```

## Format: `[ID] [P?] Description`
- **[P]**: Can run in parallel (different files, no dependencies)
- Include exact file paths in descriptions

## Path Conventions
- **WelineFramework Module**: `app/code/Weline/Ai/`
- **Tests**: `app/code/Weline/Ai/tests/`
- **Controllers**: `app/code/Weline/Ai/Controller/`
- **Models**: `app/code/Weline/Ai/Model/`
- **Services**: `app/code/Weline/Ai/Service/`

## Phase 3.1: Setup
- [x] T001 Create WelineFramework module structure in `app/code/Weline/Ai/`
- [x] T002 Initialize module registration in `app/code/Weline/Ai/register.php`
- [x] T003 [P] Configure module XML in `app/code/Weline/Ai/etc/module.xml`
- [x] T004 [P] Setup database installation script in `app/code/Weline/Ai/Setup/Install.php`
- [x] T005 [P] Configure PHPUnit testing in `app/code/Weline/Ai/tests/`

## Phase 3.2: Tests First (TDD) ⚠️ MUST COMPLETE BEFORE 3.3
**CRITICAL: These tests MUST be written and MUST FAIL before ANY implementation**
- [x] T006 [P] Contract test POST /api/v1/chat in `app/code/Weline/Ai/tests/contract/ChatPostTest.php`
- [x] T007 [P] Contract test POST /api/v1/model/{id}/copy in `app/code/Weline/Ai/tests/contract/ModelCopyTest.php`
- [x] T008 [P] Contract test GET /api/v1/model/{id} in `app/code/Weline/Ai/tests/contract/ModelGetTest.php`
- [x] T009 [P] Contract test POST /api/v1/api-key in `app/code/Weline/Ai/tests/contract/ApiKeyPostTest.php`
- [x] T010 [P] Integration test model management flow in `app/code/Weline/Ai/tests/integration/ModelManagementTest.php`
- [x] T011 [P] Integration test API key authentication in `app/code/Weline/Ai/tests/integration/ApiKeyAuthTest.php`
- [x] T012 [P] Integration test assistant management in `app/code/Weline/Ai/tests/integration/AssistantManagementTest.php`
- [x] T013 [P] Integration test scenario adapter in `app/code/Weline/Ai/tests/integration/ScenarioAdapterTest.php`
- [x] T014 [P] Integration test business insight report in `app/code/Weline/Ai/tests/integration/BusinessInsightTest.php`
- [x] T015 [P] Integration test multi-tenant isolation in `app/code/Weline/Ai/tests/integration/MultiTenantTest.php`

## Phase 3.3: Core Implementation (ONLY after tests are failing)
- [x] T016 [P] AI Model entity in `app/code/Weline/Ai/Model/AiModel.php`
- [x] T017 [P] AI API Key entity in `app/code/Weline/Ai/Model/AiApiKey.php`
- [x] T018 [P] AI Assistant entity in `app/code/Weline/Ai/Model/AiAssistant.php`
- [x] T019 [P] AI Tenant entity in `app/code/Weline/Ai/Model/AiTenant.php`
- [x] T020 [P] AI Model Monitoring entity in `app/code/Weline/Ai/Model/AiModelMonitoring.php`
- [x] T021 [P] AI Model Service in `app/code/Weline/Ai/Service/AiModelService.php`
- [x] T022 [P] AI API Key Service in `app/code/Weline/Ai/Service/AiApiKeyService.php`
- [x] T023 [P] AI Assistant Service in `app/code/Weline/Ai/Service/AiAssistantService.php`
- [x] T024 [P] AI Tenant Service in `app/code/Weline/Ai/Service/AiTenantService.php`
- [x] T025 [P] AI Chat Service in `app/code/Weline/Ai/Service/AiChatService.php`
- [x] T026 POST /api/v1/chat endpoint in `app/code/Weline/Ai/Controller/Api/Chat.php`
- [x] T027 GET /api/v1/model/{id} endpoint in `app/code/Weline/Ai/Controller/Api/Model.php`
- [x] T028 POST /api/v1/model/{id}/copy endpoint in `app/code/Weline/Ai/Controller/Api/Model.php`
- [x] T029 POST /api/v1/api-key endpoint in `app/code/Weline/Ai/Controller/Api/ApiKey.php`
- [x] T030 GET /api/v1/api-key endpoint in `app/code/Weline/Ai/Controller/Api/ApiKey.php`
- [x] T031 Routes configuration in `app/code/Weline/Ai/etc/routes.xml`
- [x] T032 Module installation via `php bin/w setup:upgrade`

## Phase 3.4: Integration
- [x] T033 Connect AI Model Service to database
- [x] T034 Connect AI API Key Service to database
- [x] T035 Connect AI Assistant Service to database
- [x] T036 Connect AI Tenant Service to database
- [x] T037 API authentication middleware in `app/code/Weline/Ai/Middleware/Auth.php`
- [x] T038 Multi-tenant isolation middleware in `app/code/Weline/Ai/Middleware/TenantIsolation.php`
- [x] T039 Request/response logging middleware in `app/code/Weline/Ai/Middleware/Logging.php`
- [x] T040 CORS and security headers middleware in `app/code/Weline/Ai/Middleware/Security.php`
- [x] T041 SecretStore integration for API key encryption
- [x] T042 Queue system integration for async processing
- [x] T043 Redis cache integration for performance optimization

## Phase 3.5: Polish
- [x] T044 [P] Unit tests for AI Model validation in `app/code/Weline/Ai/tests/unit/test_ai_model_validation.php`
- [x] T045 [P] Unit tests for AI API Key validation in `app/code/Weline/Ai/tests/unit/test_ai_api_key_validation.php`
- [x] T046 [P] Unit tests for AI Assistant validation in `app/code/Weline/Ai/tests/unit/test_ai_assistant_validation.php`
- [x] T047 [P] Unit tests for AI Tenant validation in `app/code/Weline/Ai/tests/unit/test_ai_tenant_validation.php`
- [x] T048 Performance tests (P95 ≤ 3s, P99 ≤ 5s)
- [x] T049 [P] Update API documentation in `app/code/Weline/Ai/docs/api.md`
- [x] T050 [P] Update user guide in `app/code/Weline/Ai/docs/user-guide.md`
- [x] T051 Remove code duplication
- [x] T052 Run quickstart.md validation tests
- [x] T053 HTTP request verification for all endpoints
- [x] T054 Offcanvas UI implementation for model management
- [x] T055 Offcanvas UI implementation for API key management
- [x] T056 Offcanvas UI implementation for assistant management

## Phase 3.6: Advanced Features (Phase 2)
- [x] T057 [P] Scenario Adapter entity in `app/code/Weline/Ai/Model/AiScenarioAdapter.php`
- [x] T058 [P] Scenario Adapter Service in `app/code/Weline/Ai/Service/AdapterScanner.php`
- [x] T059 [P] Business Insight Report Service in `app/code/Weline/Ai/Service/BusinessInsightService.php`
- [x] T060 [P] Monitoring and Alert Service in `app/code/Weline/Ai/Service/MonitoringService.php`
- [x] T061 [P] Billing Service in `app/code/Weline/Ai/Service/BillingManager.php`
- [x] T062 [P] Internationalization Service in `app/code/Weline/Ai/Service/I18nManager.php`
- [x] T063 [P] Mobile Support Service in `app/code/Weline/Ai/Service/MobileManager.php`
- [x] T064 [P] Third-party Integration Service in `app/code/Weline/Ai/Service/ThirdPartyIntegrationService.php`
- [x] T065 [P] Marketing Tools Service in `app/code/Weline/Ai/Service/MarketingToolsService.php`
- [x] T066 [P] Customer Support Service in `app/code/Weline/Ai/Service/CustomerSupportService.php`
- [x] T067 [P] Developer Tools Service in `app/code/Weline/Ai/Service/DeveloperToolsService.php`

## Phase 3.7: Extended Features (Phase 3)
- [x] T068 [P] Model Version Management in `app/code/Weline/Ai/Model/AiModelVersion.php`
- [x] T069 [P] Assistant Prompt Template in `app/code/Weline/Ai/Model/AiAssistantPromptTemplate.php`
- [x] T070 [P] API Quota Management in `app/code/Weline/Ai/Model/AiApiQuota.php`
- [x] T071 [P] Scenario Adapter Configuration in `app/code/Weline/Ai/Model/AiScenarioAdapterConfig.php`
- [x] T072 [P] Tenant Configuration in `app/code/Weline/Ai/Model/AiTenantConfig.php`
- [x] T073 [P] Audit Log Detail in `app/code/Weline/Ai/Model/AiAuditLogDetail.php`
- [x] T074 [P] Performance Metric Detail in `app/code/Weline/Ai/Model/AiPerformanceMetricDetail.php`
- [x] T075 [P] Billing Record Detail in `app/code/Weline/Ai/Model/AiBillingRecordDetail.php`
- [x] T076 [P] Model Training Data in `app/code/Weline/Ai/Model/AiTrainingData.php`
- [x] T077 [P] Assistant Conversation in `app/code/Weline/Ai/Model/AiAssistantConversation.php`

## Phase 3.8: 缺失数据表实现 (Missing Data Tables)
- [x] T078 [P] Default Model entity in `app/code/Weline/Ai/Model/AiDefaultModel.php`
- [x] T079 [P] Model Deployment entity in `app/code/Weline/Ai/Model/AiModelDeployment.php`
- [x] T080 [P] Model Benchmark entity in `app/code/Weline/Ai/Model/AiModelBenchmark.php`
- [x] T081 [P] Content Safety entity in `app/code/Weline/Ai/Model/AiContentSafety.php`
- [x] T082 [P] Security Scan entity in `app/code/Weline/Ai/Model/AiSecurityScan.php`
- [x] T083 [P] Billing Plan entity in `app/code/Weline/Ai/Model/AiBillingPlan.php`
- [x] T084 [P] Billing Invoice entity in `app/code/Weline/Ai/Model/AiBillingInvoice.php`
- [x] T085 [P] Tenant User entity in `app/code/Weline/Ai/Model/AiTenantUser.php`
- [x] T086 [P] I18n Content entity in `app/code/Weline/Ai/Model/AiI18nContent.php`
- [x] T087 [P] Mobile Device entity in `app/code/Weline/Ai/Model/AiMobileDevice.php`
- [x] T088 [P] Mobile Notification entity in `app/code/Weline/Ai/Model/AiMobileNotification.php`
- [x] T089 [P] Developer Tool entity in `app/code/Weline/Ai/Model/AiDeveloperTool.php`
- [x] T090 [P] Third Party Integration entity in `app/code/Weline/Ai/Model/AiThirdPartyIntegration.php`
- [x] T091 [P] Support Ticket entity in `app/code/Weline/Ai/Model/AiSupportTicket.php`
- [x] T092 [P] Marketing Campaign entity in `app/code/Weline/Ai/Model/AiMarketingCampaign.php`
- [x] T093 [P] AB Test entity in `app/code/Weline/Ai/Model/AiAbTest.php`
- [x] T094 [P] Usage Log entity in `app/code/Weline/Ai/Model/AiUsageLog.php`

## Phase 3.9: 缺失服务层实现 (Missing Services) ✅
- [x] T095 [P] Default Model Manager Service in `app/code/Weline/Ai/Service/DefaultModelManager.php`
- [x] T096 [P] Model Deployment Service in `app/code/Weline/Ai/Service/ModelDeploymentService.php`
- [x] T097 [P] Model Benchmark Service in `app/code/Weline/Ai/Service/ModelBenchmarkService.php`
- [x] T098 [P] Model Versioning Service in `app/code/Weline/Ai/Service/ModelVersioningService.php`
- [x] T099 [P] Content Safety Service in `app/code/Weline/Ai/Service/ContentSafetyService.php`
- [x] T100 [P] Security Scan Service in `app/code/Weline/Ai/Service/SecurityScanService.php`
- [x] T101 [P] Training Data Service in `app/code/Weline/Ai/Service/TrainingDataService.php`
- [x] T102 [P] AB Testing Service in `app/code/Weline/Ai/Service/AbTestingService.php`

## Phase 3.10: 控制器单元测试 (Controller Unit Tests - Phase 1 Priority) ✅
**⚠️ Phase 1 核心任务：这些测试任务对应 plan.md 中的22个控制器**

### Backend 控制器测试 (18个)
- [x] T103 [P] Model Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/ModelTest.php`
  - 测试场景：index, save, edit, copy, delete, toggleStatus, setDefault
- [x] T104 [P] AbTesting Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/AbTestingTest.php`
- [x] T105 [P] Adapter Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/AdapterTest.php`
- [x] T106 [P] ApiKey Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/ApiKeyTest.php`
- [x] T107 [P] Assistant Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/AssistantTest.php`
- [x] T108 [P] ContentSafety Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/ContentSafetyTest.php`
- [x] T109 [P] CustomerSupport Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/CustomerSupportTest.php`
- [x] T110 [P] DefaultModel Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/DefaultModelTest.php`
- [x] T111 [P] DeveloperTools Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/DeveloperToolsTest.php`
- [x] T112 [P] Insights Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/InsightsTest.php`
- [x] T113 [P] MarketingTools Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/MarketingToolsTest.php`
- [x] T114 [P] ModelBenchmark Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/ModelBenchmarkTest.php`
- [x] T115 [P] ModelDeployment Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/ModelDeploymentTest.php`
- [x] T116 [P] ModelVersioning Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/ModelVersioningTest.php`
- [x] T117 [P] SecurityScan Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/SecurityScanTest.php`
- [x] T118 [P] Test Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/TestTest.php`
- [x] T119 [P] ThirdPartyIntegration Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/ThirdPartyIntegrationTest.php`
- [x] T120 [P] TrainingData Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Backend/TrainingDataTest.php`

### Frontend 控制器测试 (4个)
- [x] T121 [P] Frontend Assistant Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Frontend/AssistantTest.php`
- [x] T122 [P] Frontend Center Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Frontend/CenterTest.php`
- [x] T123 [P] Frontend Chat Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Frontend/ChatTest.php`
- [x] T124 [P] Frontend Index Controller Tests in `app/code/Weline/Ai/Test/Unit/Controller/Frontend/IndexTest.php`

## Phase 3.11: HTTP端到端测试 (HTTP Integration Tests - Phase 1 Priority) ✅
**⚠️ Phase 1 核心任务：使用 `php bin/w http:request` 进行端到端验证**

### Backend HTTP测试脚本
- [x] T125 Model HTTP Tests: GET /ai/backend/model/index, POST /ai/backend/model/save, POST /ai/backend/model/copy
- [x] T126 ApiKey HTTP Tests: GET /ai/backend/apikey/index, POST /ai/backend/apikey/save, DELETE /ai/backend/apikey/delete
- [x] T127 Assistant HTTP Tests: GET /ai/backend/assistant/index, POST /ai/backend/assistant/save
- [x] T128 DefaultModel HTTP Tests: GET /ai/backend/defaultmodel/index, POST /ai/backend/defaultmodel/setDefault
- [x] T129 Adapter HTTP Tests: GET /ai/backend/adapter/index, POST /ai/backend/adapter/scan
- [x] T130 Insights HTTP Tests: GET /ai/backend/insights/dashboard, GET /ai/backend/insights/report
- [x] T131 AbTesting HTTP Tests: GET /ai/backend/abtesting/index, POST /ai/backend/abtesting/start
- [x] T132 ModelBenchmark HTTP Tests: GET /ai/backend/modelbenchmark/index, POST /ai/backend/modelbenchmark/run
- [x] T133 SecurityScan HTTP Tests: GET /ai/backend/securityscan/index, POST /ai/backend/securityscan/scan
- [x] T134 ContentSafety HTTP Tests: POST /ai/backend/contentsafety/check
- [x] T135 ModelDeployment HTTP Tests: GET /ai/backend/modeldeployment/index, POST /ai/backend/modeldeployment/deploy
- [x] T136 ModelVersioning HTTP Tests: GET /ai/backend/modelversioning/index, POST /ai/backend/modelversioning/createVersion
- [x] T137 TrainingData HTTP Tests: GET /ai/backend/trainingdata/index, POST /ai/backend/trainingdata/upload
- [x] T138 Test HTTP Tests: GET /ai/backend/test/index, POST /ai/backend/test/run
- [x] T139 ThirdPartyIntegration HTTP Tests: GET /ai/backend/thirdpartyintegration/index, POST /ai/backend/thirdpartyintegration/connect
- [x] T140 CustomerSupport HTTP Tests: GET /ai/backend/customersupport/tickets, POST /ai/backend/customersupport/createTicket
- [x] T141 MarketingTools HTTP Tests: GET /ai/backend/marketingtools/campaigns, POST /ai/backend/marketingtools/createCampaign
- [x] T142 DeveloperTools HTTP Tests: GET /ai/backend/developertools/sdk, GET /ai/backend/developertools/docs

### Frontend HTTP测试脚本
- [x] T143 Frontend Assistant HTTP Tests: GET /ai/frontend/assistant/index, POST /ai/frontend/assistant/chat
- [x] T144 Frontend Center HTTP Tests: GET /ai/frontend/center/index
- [x] T145 Frontend Chat HTTP Tests: GET /ai/frontend/chat/index, POST /ai/frontend/chat/send
- [x] T146 Frontend Index HTTP Tests: GET /ai/frontend/index/index

## Dependencies
- Tests (T006-T015) before implementation (T016-T032)
- T016-T020 blocks T021-T025, T033-T036
- T021-T025 blocks T026-T030
- T031-T032 blocks T037-T040
- T033-T043 blocks T044-T056
- Implementation before polish (T044-T056)
- Core features before advanced features (T057-T067)
- Advanced features before extended features (T068-T077)
- Missing data tables (T078-T094) can run in parallel with T068-T077
- Missing services (T095-T102) blocks controller tests (T103-T124)
- Controller unit tests (T103-T124) before HTTP tests (T125-T146)
- All tests (T103-T146) are Phase 1 priority tasks

## Parallel Execution Examples

### Phase 3.1 Setup (T003-T005)
```bash
# Launch T003-T005 together:
Task: "Configure module XML in app/code/Weline/Ai/etc/module.xml"
Task: "Setup database installation script in app/code/Weline/Ai/Setup/Install.php"
Task: "Configure PHPUnit testing in app/code/Weline/Ai/tests/"
```

### Phase 3.2 Contract Tests (T006-T009)
```bash
# Launch T006-T009 together:
Task: "Contract test POST /api/v1/chat in app/code/Weline/Ai/tests/contract/test_chat_post.php"
Task: "Contract test POST /api/v1/model/{id}/copy in app/code/Weline/Ai/tests/contract/test_model_copy.php"
Task: "Contract test GET /api/v1/model/{id} in app/code/Weline/Ai/tests/contract/test_model_get.php"
Task: "Contract test POST /api/v1/api-key in app/code/Weline/Ai/tests/contract/test_api_key_post.php"
```

### Phase 3.2 Integration Tests (T010-T015)
```bash
# Launch T010-T015 together:
Task: "Integration test model management flow in app/code/Weline/Ai/tests/integration/test_model_management.php"
Task: "Integration test API key authentication in app/code/Weline/Ai/tests/integration/test_api_key_auth.php"
Task: "Integration test assistant management in app/code/Weline/Ai/tests/integration/test_assistant_management.php"
Task: "Integration test scenario adapter in app/code/Weline/Ai/tests/integration/test_scenario_adapter.php"
Task: "Integration test business insight report in app/code/Weline/Ai/tests/integration/test_business_insight.php"
Task: "Integration test multi-tenant isolation in app/code/Weline/Ai/tests/integration/test_multi_tenant.php"
```

### Phase 3.3 Core Models (T016-T020)
```bash
# Launch T016-T020 together:
Task: "AI Model entity in app/code/Weline/Ai/Model/AiModel.php"
Task: "AI API Key entity in app/code/Weline/Ai/Model/AiApiKey.php"
Task: "AI Assistant entity in app/code/Weline/Ai/Model/AiAssistant.php"
Task: "AI Tenant entity in app/code/Weline/Ai/Model/AiTenant.php"
Task: "AI Model Monitoring entity in app/code/Weline/Ai/Model/AiModelMonitoring.php"
```

### Phase 3.3 Core Services (T021-T025)
```bash
# Launch T021-T025 together:
Task: "AI Model Service in app/code/Weline/Ai/Service/AiModelService.php"
Task: "AI API Key Service in app/code/Weline/Ai/Service/AiApiKeyService.php"
Task: "AI Assistant Service in app/code/Weline/Ai/Service/AiAssistantService.php"
Task: "AI Tenant Service in app/code/Weline/Ai/Service/AiTenantService.php"
Task: "AI Chat Service in app/code/Weline/Ai/Service/AiChatService.php"
```

### Phase 3.5 Unit Tests (T044-T047)
```bash
# Launch T044-T047 together:
Task: "Unit tests for AI Model validation in app/code/Weline/Ai/tests/unit/test_ai_model_validation.php"
Task: "Unit tests for AI API Key validation in app/code/Weline/Ai/tests/unit/test_ai_api_key_validation.php"
Task: "Unit tests for AI Assistant validation in app/code/Weline/Ai/tests/unit/test_ai_assistant_validation.php"
Task: "Unit tests for AI Tenant validation in app/code/Weline/Ai/tests/unit/test_ai_tenant_validation.php"
```

## Notes
- [P] tasks = different files, no dependencies
- Verify tests fail before implementing
- Commit after each task
- Avoid: vague tasks, same file conflicts
- Follow WelineFramework patterns, avoid Magento patterns
- Use `php bin/w http:request` for E2E validation
- Implement Offcanvas UI for edit/new operations

## Task Generation Rules
*Applied during main() execution*

1. **From Contracts**:
   - ✅ Each contract file → contract test task [P]
   - ✅ Each endpoint → implementation task
   
2. **From Data Model**:
   - ✅ Each entity → model creation task [P]
   - ✅ Relationships → service layer tasks
   
3. **From User Stories**:
   - ✅ Each story → integration test [P]
   - ✅ Quickstart scenarios → validation tasks
   
4. **Ordering**:
   - ✅ Setup → Tests → Models → Services → Endpoints → Polish
   - ✅ Dependencies block parallel execution

## Validation Checklist
*GATE: Checked by main() before returning*

- [x] All contracts have corresponding tests
- [x] All entities have model tasks
- [x] All tests come before implementation
- [x] Parallel tasks truly independent
- [x] Each task specifies exact file path
- [x] No task modifies same file as another [P] task
- [x] WelineFramework compliance tasks included
- [x] HTTP request verification tasks included
- [x] Offcanvas UI implementation tasks included
- [x] Anti-Magento pattern validation included

## Constitution Compliance
*All tasks must follow Constitution v2.5.0*

- ✅ **I. 框架一致性**: All tasks use WelineFramework patterns
- ✅ **II. 测试驱动开发**: Tests before implementation (T006-T015 before T016-T032)
- ✅ **III. 模块化设计**: Independent, testable components
- ✅ **IV. 多租户数据隔离**: Tenant isolation middleware (T038)
- ✅ **V. 国际化支持**: I18n service (T062)
- ✅ **VI. 安全与合规**: SecretStore integration (T041), security middleware (T040)
- ✅ **VII. 性能与可扩展性**: Performance tests (T048), cache integration (T043)
- ✅ **VIII. 测试组织规范**: Proper test structure in tests/ directory
- ✅ **IX. PHP语言合规性**: PHP 8.2+ compliance
- ✅ **X. ORM使用规范**: WelineFramework ORM patterns
- ✅ **XI. 框架学习要求**: Self-learning and documentation updates
- ✅ **XII. Offcanvas编辑流**: Offcanvas UI tasks (T054-T056)
- ✅ **XIII. HTTP请求测试**: HTTP request verification (T053)
- ✅ **XIV. 架构与数据流验证**: Data model validation
- ✅ **XV. 变更范围限制**: All tasks within app/code/Weline/Ai/
- ✅ **XVI. 已实现功能兼容性**: Compatibility considerations
- ✅ **XVII. 禁止Magento写法**: Anti-Magento pattern validation

## Execution Status
- [x] Task generation complete
- [x] All design documents analyzed
- [x] Dependencies mapped
- [x] Parallel execution identified
- [x] Constitution compliance verified
- [x] Phase 1 controller testing tasks added (T103-T146)
- [x] Missing data tables and services added (T078-T102)
- [x] Ready for implementation

**Total Tasks**: 146 (T001-T146)
- **Phase 1 (已完成)**: T001-T077 (Core Implementation) ✅
- **Phase 1.5 (新增)**: T078-T102 (Missing Data & Services) 
- **Phase 1 Priority**: T103-T146 (Controller Tests & HTTP Tests) 🎯

**Parallel Tasks**: 109 (marked with [P])
**Sequential Tasks**: 37 (no [P] marking)

**Phase 1 控制器测试专项统计**:
- Controller Unit Tests: 22个 (T103-T124)
- HTTP Integration Tests: 22个 (T125-T146)
- Coverage Target: 90%+ for all 22 controllers

**Estimated Completion**: 
- Phase 1.5 (T078-T102): 1-2 weeks with 2 developers
- Phase 1 Priority (T103-T146): 2-3 weeks with 2 developers
- Total Phase 1 completion: 3-5 weeks with 2 developers