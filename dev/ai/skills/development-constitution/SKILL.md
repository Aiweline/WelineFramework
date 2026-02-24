---
name: development-constitution
description: Core development principles for Weline Framework. Use when making architectural decisions, implementing features, or ensuring framework compliance. CRITICAL - All development must follow framework patterns. 开发规范, architecture, design patterns.
globs:
alwaysApply: false
---

# Weline Framework Development Constitution

This skill enforces the core development principles that govern all Weline Framework development.

## Core Principles

### I. Framework Compliance (Framework Consistency)

**CRITICAL**: All development must strictly follow Weline Framework specifications and existing module patterns.

**Requirements:**
- Read relevant framework development documentation before implementation
- Study existing module implementation patterns
- Follow framework's MVC architecture design
- Use framework-provided ORM and utility classes
- Maintain consistency with existing code style

**Prohibited:**
- ❌ Development based on personal experience
- ❌ Development based on external framework patterns
- ❌ Deviating from framework standards

### II. Test-Driven Development (TDD - NON-NEGOTIABLE)

**See**: `test-driven-development` skill for detailed TDD requirements.

**Key Points:**
- Write tests first (ensure they fail)
- Implement feature to make tests pass
- Refactor while keeping tests passing
- ❌ **MUST NOT**: Delete test files
- ❌ **MUST NOT**: Create temporary test scripts
- ✅ **MUST**: All tests in `Test/Unit/` directory

### III. Modular Architecture

**Requirements:**
- Each component independently testable
- Clear module separation and dependency management
- Follow framework directory structure and naming conventions
- Support independent module deployment and maintenance
- Loose coupling integration with other modules

### IV. Multi-Tenant Data Isolation

**Enterprise Requirements:**
- All data queries must include tenant ID filtering
- Tenant-level configuration and permission management
- Complete data isolation for security
- Support tenant-level resource quota management
- Implement tenant context middleware

### V. Internationalization Support

**See**: `i18n-internationalization` skill for detailed i18n requirements.

**Key Points:**
- Complete multi-language support
- All user-facing text must use i18n
- Support dynamic language switching
- Provide multi-language API responses

### VI. Security & Compliance

**Enterprise Security:**
- API keys and sensitive information encrypted storage
- Input/output auditing and content security checks
- Role-based access control
- Complete operation audit logs
- Data protection regulation compliance

### VII. Performance & Scalability

**Requirements:**
- Support 1000+ concurrent requests, response time <200ms
- Multi-level caching strategy
- Asynchronous queue processing
- Load balancing support
- Support horizontal scaling

### VIII. Test Organization Standards

**Requirements:**
- Test files in module's `Test/Unit/` directory (PSR-4)
- Unit tests: `Test/Unit/` directory
- Integration tests: `tests/integration/` directory
- Contract tests: `tests/contract/` directory
- Test class naming: PascalCase (e.g., `BusinessInsightServiceTest`)
- **MUST**: Run tests with `php bin/w phpunit:run -b` (background mode with HTML report)

### IX. PHP Language Compliance (NON-NEGOTIABLE)

**Requirements:**
- Must use PHP 8.2+ strict syntax
- Must use `declare(strict_types=1)`
- Strict type declarations for all functions
- Follow PHP language features strictly

### X. CLI 命令使用约定

**CRITICAL**：对不熟悉或不确定用法的框架 CLI 命令，**必须先查看帮助**，再编写或执行命令。

- 查看帮助：`php bin/m <command> -h` 或 `php bin/w <command> --help`
- 入口脚本：项目根目录下 `bin/m` 或 `bin/w` 均可，以实际存在为准
- 禁止凭猜测传参；帮助中的选项、参数以当前运行 `-h` 输出为准

**Keywords**: 命令, command, CLI, 帮助, help, -h, bin/m, bin/w

## Development Workflow

### Before Implementation

1. Read framework development documentation
2. Study existing module patterns
3. Design following MVC architecture
4. Write tests first (TDD)

### During Implementation

1. Use framework ORM and utilities
2. Follow framework naming conventions
3. Maintain code style consistency
4. Ensure all tests pass

### After Implementation

1. Verify functionality works correctly
2. Run all tests with `-b` flag
3. Check code quality and compliance
4. Update documentation if needed

## Prohibited Practices

- ❌ Development based on personal experience
- ❌ Using patterns from other frameworks
- ❌ Deviating from framework standards
- ❌ Deleting test files
- ❌ Creating temporary test scripts
- ❌ Hardcoding user-facing text
- ❌ Using non-verified framework methods

## Reference

- Full Constitution: `.specify/memory/constitution.md`
- Framework Documentation: `docs/dev/开发文档.md`
- AI Prompt Guide: `dev/ai/AI 提示词.md`
