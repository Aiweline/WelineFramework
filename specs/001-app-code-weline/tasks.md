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
- [ ] T033 Connect AI Model Service to database
- [ ] T034 Connect AI API Key Service to database
- [ ] T035 Connect AI Assistant Service to database
- [ ] T036 Connect AI Tenant Service to database
- [ ] T037 API authentication middleware in `app/code/Weline/Ai/Middleware/Auth.php`
- [ ] T038 Multi-tenant isolation middleware in `app/code/Weline/Ai/Middleware/TenantIsolation.php`
- [ ] T039 Request/response logging middleware in `app/code/Weline/Ai/Middleware/Logging.php`
- [ ] T040 CORS and security headers middleware in `app/code/Weline/Ai/Middleware/Security.php`
- [ ] T041 SecretStore integration for API key encryption
- [ ] T042 Queue system integration for async processing
- [ ] T043 Redis cache integration for performance optimization

## Phase 3.5: Polish
- [ ] T044 [P] Unit tests for AI Model validation in `app/code/Weline/Ai/tests/unit/test_ai_model_validation.php`
- [ ] T045 [P] Unit tests for AI API Key validation in `app/code/Weline/Ai/tests/unit/test_ai_api_key_validation.php`
- [ ] T046 [P] Unit tests for AI Assistant validation in `app/code/Weline/Ai/tests/unit/test_ai_assistant_validation.php`
- [ ] T047 [P] Unit tests for AI Tenant validation in `app/code/Weline/Ai/tests/unit/test_ai_tenant_validation.php`
- [ ] T048 Performance tests (P95 ≤ 3s, P99 ≤ 5s)
- [ ] T049 [P] Update API documentation in `app/code/Weline/Ai/docs/api.md`
- [ ] T050 [P] Update user guide in `app/code/Weline/Ai/docs/user-guide.md`
- [ ] T051 Remove code duplication
- [ ] T052 Run quickstart.md validation tests
- [ ] T053 HTTP request verification for all endpoints
- [ ] T054 Offcanvas UI implementation for model management
- [ ] T055 Offcanvas UI implementation for API key management
- [ ] T056 Offcanvas UI implementation for assistant management

## Phase 3.6: Advanced Features (Phase 2)
- [ ] T057 [P] Scenario Adapter entity in `app/code/Weline/Ai/Model/AiScenarioAdapter.php`
- [ ] T058 [P] Scenario Adapter Service in `app/code/Weline/Ai/Service/AiScenarioAdapterService.php`
- [ ] T059 [P] Business Insight Report Service in `app/code/Weline/Ai/Service/AiBusinessInsightService.php`
- [ ] T060 [P] Monitoring and Alert Service in `app/code/Weline/Ai/Service/AiMonitoringService.php`
- [ ] T061 [P] Billing Service in `app/code/Weline/Ai/Service/AiBillingService.php`
- [ ] T062 [P] Internationalization Service in `app/code/Weline/Ai/Service/AiI18nService.php`
- [ ] T063 [P] Mobile Support Service in `app/code/Weline/Ai/Service/AiMobileService.php`
- [ ] T064 [P] Third-party Integration Service in `app/code/Weline/Ai/Service/AiThirdPartyService.php`
- [ ] T065 [P] Marketing Tools Service in `app/code/Weline/Ai/Service/AiMarketingService.php`
- [ ] T066 [P] Customer Support Service in `app/code/Weline/Ai/Service/AiCustomerSupportService.php`
- [ ] T067 [P] Developer Tools Service in `app/code/Weline/Ai/Service/AiDeveloperService.php`

## Phase 3.7: Extended Features (Phase 3)
- [ ] T068 [P] Model Version Management in `app/code/Weline/Ai/Model/AiModelVersion.php`
- [ ] T069 [P] Assistant Prompt Template in `app/code/Weline/Ai/Model/AiAssistantPromptTemplate.php`
- [ ] T070 [P] API Quota Management in `app/code/Weline/Ai/Model/AiApiQuota.php`
- [ ] T071 [P] Scenario Adapter Configuration in `app/code/Weline/Ai/Model/AiScenarioAdapterConfig.php`
- [ ] T072 [P] Tenant Configuration in `app/code/Weline/Ai/Model/AiTenantConfig.php`
- [ ] T073 [P] Audit Log Detail in `app/code/Weline/Ai/Model/AiAuditLogDetail.php`
- [ ] T074 [P] Performance Metric Detail in `app/code/Weline/Ai/Model/AiPerformanceMetricDetail.php`
- [ ] T075 [P] Billing Record Detail in `app/code/Weline/Ai/Model/AiBillingRecordDetail.php`
- [ ] T076 [P] Model Training Data in `app/code/Weline/Ai/Model/AiModelTrainingData.php`
- [ ] T077 [P] Assistant Conversation in `app/code/Weline/Ai/Model/AiAssistantConversation.php`

## Dependencies
- Tests (T006-T015) before implementation (T016-T032)
- T016-T020 blocks T021-T025, T033-T036
- T021-T025 blocks T026-T030
- T031-T032 blocks T037-T040
- T033-T043 blocks T044-T056
- Implementation before polish (T044-T056)
- Core features before advanced features (T057-T067)
- Advanced features before extended features (T068-T077)

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
- [x] Ready for implementation

**Total Tasks**: 77 (T001-T077)
**Parallel Tasks**: 45 (marked with [P])
**Sequential Tasks**: 32 (no [P] marking)
**Estimated Completion**: 3-4 weeks with 2 developers