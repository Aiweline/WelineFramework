# Feature Specification: AST Template Compiler for Taglib

**Feature Branch**: `001-ast-template-engine`  
**Created**: 2026-01-22  
**Status**: Draft  
**Input**: User description: "AST 模板引擎（支持编译期执行）的架构级开发计划文档；按照文档把 app\code\Weline\Framework\View\Taglib.php 改造，结合框架实际情况"

## Execution Flow (main)
```
1. Parse user description from Input
   → If empty: ERROR "No feature description provided"
2. Extract key concepts from description
   → Identify: actors, actions, data, constraints
3. For each unclear aspect:
   → Mark with [NEEDS CLARIFICATION: specific question]
4. Fill User Scenarios & Testing section
   → If no clear user flow: ERROR "Cannot determine user scenarios"
5. Generate Functional Requirements
   → Each requirement must be testable
   → Mark ambiguous requirements
6. Identify Key Entities (if data involved)
7. Run Review Checklist
   → If any [NEEDS CLARIFICATION]: WARN "Spec has uncertainties"
   → If implementation details found: ERROR "Remove tech details"
8. Return: SUCCESS (spec ready for planning)
```

---

## ⚡ Quick Guidelines
- ✅ Focus on WHAT users need and WHY
- ❌ Avoid HOW to implement (no tech stack, APIs, code structure)
- 👥 Written for business stakeholders, not developers

### Section Requirements
- **Mandatory sections**: Must be completed for every feature
- **Optional sections**: Include only when relevant to the feature
- When a section doesn't apply, remove it entirely (don't leave as "N/A")

### For AI Generation
When creating this spec from a user prompt:
1. **Mark all ambiguities**: Use [NEEDS CLARIFICATION: specific question] for any assumption you'd need to make
2. **Don't guess**: If the prompt doesn't specify something (e.g., "login system" without auth method), mark it
3. **Think like a tester**: Every vague requirement should fail the "testable and unambiguous" checklist item
4. **Common underspecified areas**:
   - User types and permissions
   - Data retention/deletion policies  
   - Performance targets and scale
   - Error handling behaviors
   - Integration requirements
   - Security/compliance needs

---

## User Scenarios & Testing *(mandatory)*

### Primary User Story
As a template developer, I want templates to compile into cached PHP files via AST processing so that custom tags, safe PHP-in-attributes, and compile-time tags are handled deterministically without regex-based semantic parsing.

### Acceptance Scenarios
1. **Given** a template containing custom tags, attributes, and PHP blocks, **When** it is compiled, **Then** it produces a cached PHP file that renders the expected output.
2. **Given** a compile-time tag, **When** the template is compiled, **Then** the tag is evaluated at compile time and replaced by static output in the resulting PHP.
3. **Given** a runtime tag, **When** the template is compiled, **Then** the tag is preserved for runtime rendering in the generated PHP.
4. **Given** malformed template structure (e.g., unclosed tags or invalid PHP blocks), **When** it is compiled, **Then** compilation fails with an error that includes file, line, and context.

### Edge Cases
- Nested tags with mixed Text/Tag/PHP nodes and attributes that embed PHP safely.
- Compile-time tags referencing runtime-only data or forbidden functions.
- Templates with unclosed PHP blocks or tag mismatches.
- Large templates compile and cache without excessive memory or time overhead.

## Requirements *(mandatory)*

### Functional Requirements
- **FR-001**: System MUST convert template sources into a structured AST that represents tags, attributes, text, and PHP blocks without regex-based semantic parsing.
- **FR-002**: System MUST support compile-time execution for tags marked as compile-time and preserve runtime tags for request-time rendering.
- **FR-003**: System MUST allow PHP code to be embedded safely within tag attributes, without executing it at compile time.
- **FR-004**: System MUST compile templates into pure PHP files.
- **FR-005**: System MUST report tokenizer/parser/semantic/runtime errors with file, line, and context.
- **FR-006**: System MUST allow custom tags to register execution stage metadata and a handler.
- **FR-007**: System MUST isolate compile-time execution from runtime data and global state using a restricted execution context.
- **FR-008**: System MAY introduce breaking behavior changes; new AST-defined behavior is authoritative.
- **FR-009**: Tag execution stage MUST be determined by tag registration; if unspecified, default to current Taglib behavior.
- **FR-010**: Static link generation tags MUST execute at compile time to emit static output.
- **FR-011**: Tag callbacks MUST be able to access the template context variables.
- **FR-012**: Tags with static arguments (e.g., `lang` with static key) SHOULD execute at compile time; tags with dynamic arguments MUST execute at runtime.

### Non-Functional Requirements
- **NFR-001**: Compilation and runtime rendering MUST prioritize performance; avoid regressions compared to current behavior.
- **NFR-002**: This change does NOT alter template cache strategy; rely on existing template caching mechanisms.

### Key Entities *(include if feature involves data)*
- **Template Source**: Raw template content and file metadata used for compilation and caching.
- **Token**: Atomic unit with type, value, line, and column produced by lexical analysis.
- **AST Node**: Structured node with line metadata and typed content (text, PHP, tag, attribute).
- **Tag Definition**: Tag metadata (name, execution stage, handler).
- **Compile-Time Context**: Isolated environment with a whitelist for compile-time execution.
- **Cache Entry**: Mapping from template content hash to compiled PHP output file.

---

## Clarifications
### Session 2026-01-22
- Q: 哪些现有模板行为必须保持兼容？ → A: 允许破坏性变更（新 AST 行为为准），但性能必须重点考虑
- Q: 现有标签未标注执行阶段时默认如何处理？ → A: 保持现有 Taglib 行为（按当前实现推断）
- Q: 编译期执行允许访问哪些能力范围？ → A: 允许访问 Taglib 现有上下文对象；标签支持属性写 PHP，且 callback 可直接用上下文变量
- Q: 哪些标签需要“编译期执行”？ → A: `lang` 静态参数可编译期，动态参数运行期；其它标签保持现有代码行为
- Q: 编译缓存的失效规则如何定义？ → A: 不调整缓存策略；使用现有模板缓存机制

## Review & Acceptance Checklist
*GATE: Automated checks run during main() execution*

### Content Quality
- [ ] No implementation details (languages, frameworks, APIs)
- [ ] Focused on user value and business needs
- [ ] Written for non-technical stakeholders
- [ ] All mandatory sections completed

### Requirement Completeness
- [ ] No [NEEDS CLARIFICATION] markers remain
- [ ] Requirements are testable and unambiguous  
- [ ] Success criteria are measurable
- [ ] Scope is clearly bounded
- [ ] Dependencies and assumptions identified

---

## Execution Status
*Updated by main() during processing*

- [x] User description parsed
- [x] Key concepts extracted
- [x] Ambiguities marked
- [x] User scenarios defined
- [x] Requirements generated
- [x] Entities identified
- [ ] Review checklist passed

---
