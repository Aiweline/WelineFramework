# Tasks: Weline_Ai (draft)

**Input**: Design documents from `/specs/001-app-code-weline/`

## Phase 3.1: Setup
- [ ] T001 Initialize module directory `app/code/Weline/Ai` (if missing)
- [ ] T002 Create migration skeletons for ai_model, ai_assistant, ai_api_key, ai_tenant
- [ ] T003 [P] Configure PHPUnit and CI test runner

## Phase 3.2: Tests First (TDD)
**CRITICAL: These tests MUST be written and MUST FAIL before ANY implementation**
- [ ] T004 [P] Contract test: POST /api/{version}/chat -> tests/contract/test_chat_post.php
- [ ] T005 [P] Contract test: POST /api/{version}/model/{id}/copy -> tests/contract/test_model_copy.php
 - [ ] T006 [P] Verify POST /api/{version}/chat via http:request (assert status=200; response.data.response exists; response includes locale/version fields) — BLOCKER
 - [ ] T007 [P] Verify POST /api/{version}/model/{id}/copy via http:request (assert status=200; response returns new model id and origin_model_id) — BLOCKER
 - [ ] T008 [P] Verify GET /api/{version}/model/{id} via http:request (assert status=200; response includes model metadata, is_copy flag where applicable) — BLOCKER

## Phase 3.3: Core Implementation
- [ ] T009 [P] Implement `AiModel` model and migration
- [ ] T010 [P] Implement `AiAssistant` model and migration
- [ ] T011 [P] Implement `ModelController::copy` endpoint (opens Offcanvas in UI)
- [ ] T012 Implement API Chat endpoint skeleton

## Phase 3.4: Integration
- [ ] T015 Connect models to DB and ORM
- [ ] T016 Implement API Key middleware and quota checks

## Phase 3.5: Polish
- [ ] T019 [P] Unit tests and integration tests
- [ ] T020 [P] Quickstart examples (`http:request`) and docs update

## Approval / Scope Tasks
- [ ] T030 Approval: Any changes touching files outside `app/code/Weline/Ai` MUST include design approval note and approver in PR description (tech lead sign-off).


## Notes
- Tasks are ordered TDD-first; mark [P] for parallelizable items.


