
# Implementation Plan: AST Taglib Template Compiler

**Branch**: `001-ast-template-engine` | **Date**: 2026-01-22 | **Spec**: `specs/001-ast-template-engine/spec.md`
**Input**: Feature specification from `specs/001-ast-template-engine/spec.md`

## Execution Flow (/plan command scope)
```
1. Load feature spec from Input path
   → If not found: ERROR "No feature spec at {path}"
2. Fill Technical Context (scan for NEEDS CLARIFICATION)
   → Detect Project Type from file system structure or context (web=frontend+backend, mobile=app+api)
   → Set Structure Decision based on project type
3. Fill the Constitution Check section based on the content of the constitution document.
4. Evaluate Constitution Check section below
   → If violations exist: Document in Complexity Tracking
   → If no justification possible: ERROR "Simplify approach first"
   → Update Progress Tracking: Initial Constitution Check
5. Execute Phase 0 → research.md
   → If NEEDS CLARIFICATION remain: ERROR "Resolve unknowns"
6. Execute Phase 1 → contracts, data-model.md, quickstart.md, agent-specific template file (e.g., `CLAUDE.md` for Claude Code, `.github/copilot-instructions.md` for GitHub Copilot, `GEMINI.md` for Gemini CLI, `QWEN.md` for Qwen Code or `AGENTS.md` for opencode).
7. Re-evaluate Constitution Check section
   → If new violations: Refactor design, return to Phase 1
   → Update Progress Tracking: Post-Design Constitution Check
8. Plan Phase 2 → Describe task generation approach (DO NOT create tasks.md)
9. STOP - Ready for /tasks command
```

**IMPORTANT**: The /plan command STOPS at step 7. Phases 2-4 are executed by other commands:
- Phase 2: /tasks command creates tasks.md
- Phase 3-4: Implementation execution (manual or via tools)

## Summary
Deliver an AST-based template compilation pipeline inside `Taglib` to replace regex parsing, support compile-time tag execution, preserve runtime rendering for dynamic tags, and emit pure PHP output while keeping existing template cache strategy.

## Technical Context
**Language/Version**: PHP 8.2+  
**Primary Dependencies**: Weline Framework core, PHP tokenizer  
**Storage**: Existing template cache (no changes)  
**Testing**: `php bin/w phpunit:run -b` (Framework module tests)  
**Target Platform**: PHP runtime on Linux/Windows server  
**Project Type**: single framework codebase  
**Performance Goals**: compile-on-change, minimal runtime overhead  
**Constraints**: no `view/tpl` edits; no generated directory edits; avoid Magento patterns; PHP 8.2 null safety  
**Scale/Scope**: framework-level template parsing for all modules

## Constitution Check
*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- [x] Follow WelineFramework patterns and avoid Magento patterns.
- [x] No edits under `view/tpl` or `generated/` directories.
- [x] Primary changes scoped to `app/code/Weline/Framework/View/Taglib.php` and tests.
- [x] Use PHP 8.2+ compatible calls (no null to string functions).
- [x] Include unit tests and run `php bin/w phpunit:run -b`.
- [x] Document design and testing artifacts in `specs/001-ast-template-engine/`.

## Project Structure

### Documentation (this feature)
```
specs/001-ast-template-engine/
├── plan.md              # This file (/plan command output)
├── research.md          # Phase 0 output (/plan command)
├── data-model.md        # Phase 1 output (/plan command)
├── quickstart.md        # Phase 1 output (/plan command)
├── contracts/           # Phase 1 output (/plan command)
└── tasks.md             # Phase 2 output (/tasks command)
```

### Source Code (repository root)
```
app/
└── code/
    └── Weline/
        └── Framework/
            └── View/
                ├── Taglib.php
                ├── Template.php        # only if integration requires
                └── test/
                    ├── TaglibTest.php
                    └── HookNameExtractionTest.php
```

**Structure Decision**: single framework codebase; modify `Taglib.php` and update/add tests under `app/code/Weline/Framework/View/test`. `Template.php` changes are not planned unless needed for wiring.

## Phase 0: Outline & Research
1. **Extract unknowns from Technical Context** above:
   - None (clarifications resolved).

2. **Generate and dispatch research agents**:
   - Not required; decisions captured in `research.md`.

3. **Consolidate findings** in `research.md` using format:
   - Decision, rationale, alternatives.

**Output**: `research.md` with resolved decisions.

## Phase 1: Design & Contracts
*Prerequisites: research.md complete*

1. **Extract entities from feature spec** → `data-model.md`:
   - Token, AST nodes, tag definitions, compile-time context, cache metadata (no cache changes).

2. **Generate API contracts**:
   - Not applicable (no external API). Document in `contracts/README.md`.

3. **Generate contract tests**:
   - Not applicable.

4. **Extract test scenarios** from user stories:
   - Map to unit tests in `View/test` and to quickstart validation steps.

5. **Update agent file incrementally** (O(1) operation):
   - Run `.specify/scripts/powershell/update-agent-context.ps1 -AgentType cursor`
     **IMPORTANT**: Execute it exactly as specified above. Do not add or remove any arguments.

**Output**: `data-model.md`, `contracts/README.md`, `quickstart.md`, agent-specific file.

## Phase 2: Task Planning Approach
*This section describes what the /tasks command will do - DO NOT execute during /plan*

**Task Generation Strategy**:
- Load `.specify/templates/tasks-template.md` as base
- Generate tasks from Phase 1 design docs (data model, quickstart, contracts README)
- TDD tasks for tokenizer/parser/semantic/compile-time/generator
- Add tests for compile-time vs runtime tag behavior and PHP-in-attribute support

**Ordering Strategy**:
- Tests before implementation
- Tokenizer → Parser → Semantic → Compile-time executor → Code generator → Integration

**Estimated Output**: 18-24 numbered, ordered tasks in tasks.md

**IMPORTANT**: This phase is executed by the /tasks command, NOT by /plan

## Phase 3+: Future Implementation
*These phases are beyond the scope of the /plan command*

**Phase 3**: Task execution (/tasks command creates tasks.md)  
**Phase 4**: Implementation (execute tasks.md following constitutional principles)  
**Phase 5**: Validation (run tests, execute quickstart.md, performance validation)

## Complexity Tracking
*Fill ONLY if Constitution Check has violations that must be justified*

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| N/A | N/A | N/A |

## Progress Tracking
*This checklist is updated during execution flow*

**Phase Status**:
- [x] Phase 0: Research complete (/plan command)
- [x] Phase 1: Design complete (/plan command)
- [x] Phase 2: Task planning complete (/plan command - describe approach only)
- [x] Phase 3: Tasks generated (/tasks command)
- [ ] Phase 4: Implementation complete
- [ ] Phase 5: Validation passed

**Gate Status**:
- [x] Initial Constitution Check: PASS
- [x] Post-Design Constitution Check: PASS
- [x] All NEEDS CLARIFICATION resolved
- [x] Complexity deviations documented

---
*Based on Constitution v2.18.0 - See `.specify/memory/constitution.md`*
