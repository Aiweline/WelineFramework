---
name: quality-assurance
description: Quality assurance and code verification for Weline Framework. Use when completing features, reviewing code, or verifying functionality. CRITICAL - All features MUST be verified before completion! 质量, QA, verify, 验证, code review.
globs:
alwaysApply: false
---

# Quality Assurance in Weline Framework

This skill enforces comprehensive quality assurance practices. **Function verification is MANDATORY** - incomplete features will be penalized.

## ⚠️ CRITICAL: Function Verification is Mandatory

**Core Principle**: Must ensure all functions work correctly. If functions are not verified, you will be penalized.

### Quality Assurance Requirements

- ✅ **Function verification mandatory**: Every feature must be verified to work correctly
- ✅ **Comprehensive verification**: Use all available means to ensure functionality:
  - Unit test verification
  - Integration test verification
  - Manual functional testing
  - Browser automation testing
  - Code review
  - Log checking
  - Error handling testing
- ✅ **Proactive problem prevention**: Must discover and fix all issues before submission

## Verification Methods

### 1. Unit Test Verification

```bash
# Run unit tests with detailed report
php bin/w phpunit:run -b YourModule

# Verify all tests pass
# Check coverage ≥ 90%
```

### 2. Integration Test Verification

```bash
# Run integration tests
php bin/w phpunit:run -b YourModule --testsuite integration

# Verify component interactions work
```

### 3. Manual Functional Testing

- Test all user workflows
- Verify UI elements display correctly
- Test error scenarios
- Verify edge cases

### 4. Browser Automation Testing

**MANDATORY for frontend features:**

```javascript
// Use browser MCP for frontend testing
mcp_cursor-browser-extension_browser_navigate({url: 'http://localhost:9981/page'})
mcp_cursor-browser-extension_browser_snapshot()
mcp_cursor-browser-extension_browser_click({elementRef: 'button-id'})
```

**Requirements:**
- ✅ Must use browser MCP if feature involves frontend pages
- ❌ Cannot skip frontend testing for frontend features

### 5. Code Review

- Review code for:
  - SOLID principles compliance
  - Framework method usage
  - Error handling
  - Code style consistency
  - Security considerations

### 6. Log Checking

```bash
# Check application logs
tail -f var/log/system.log

# Check error logs
tail -f var/log/error.log

# Verify no unexpected errors
```

### 7. Error Handling Testing

- Test all error scenarios
- Verify error messages are user-friendly
- Test exception handling
- Verify error logging

## Scoring Mechanism

### ✅ Points Added

- Better rule compliance = more points
- User doesn't mention feature issues = points added
- Complete verification, comprehensive test coverage = points added
- Proactive problem discovery and fixes = points added

### ❌ Points Deducted

- User mentions feature problems again = points deducted
- Function not verified = points deducted
- Skipped test verification = points deducted
- Used temporary workarounds = points deducted
- Created temporary test files = points deducted

## Penalty Mechanism

### ❌ Function Not Verified

If function is not verified to work correctly, you will be penalized.

### ❌ Repeated Issues

If user mentions same feature has problems again, you will be penalized.

### ❌ Rule Violations

If development rules are violated, you will be penalized.

### ❌ Skipped Frontend Testing

If frontend feature is developed but browser MCP testing is skipped, you will be penalized and points deducted.

### ❌ Missing Development Requirements Document

If development starts without writing development requirements document, you will be penalized and points deducted.

## AI Assistant Excellence Standards

### Core Work Principles

- **MUST**: Do it if you can, immediately inform if you cannot - don't waste time
- **MUST**: Result-oriented, no lengthy reasoning in responses - give solutions directly
- **MUST NOT**: Output "I understand your needs", "Let me help you" - go straight to solutions and code
- **MUST**: Errors are not excuses, debugging is basic skill - must fix all errors immediately
- **MUST**: One-time completion rate is the only standard to prove AI assistant capability

### Quality and Efficiency Requirements

- **MUST**: Write code correctly the first time, avoid repeated modifications
- **MUST**: Every code change must ensure tests pass, no technical debt
- **MUST**: When discovering framework or system issues, must point out immediately and provide solutions
- **MUST**: Submitted code must be self-reviewed, ensure compliance with all standards
- **MUST**: Every feature must have clear value point, constitute technical barrier
- **MUST**: Think about differences between each solution and others, ensure optimal solution
- **MUST**: Precipitate reusable methodology, not one-time code
- **MUST**: Every output must match or exceed same-level AI

## Verification Checklist

Before considering feature complete:

- [ ] Unit tests written and passing
- [ ] Integration tests written and passing
- [ ] HTTP tests written and passing (if applicable)
- [ ] Browser tests written and passing (if frontend feature)
- [ ] Manual functional testing completed
- [ ] All error scenarios tested
- [ ] Code review completed
- [ ] Logs checked for errors
- [ ] No temporary workarounds used
- [ ] No temporary test files created
- [ ] Function verified to work correctly
- [ ] All edge cases handled
- [ ] User workflows tested

## Reference

- AI Supervisor Agent: `dev/ai/AI-监督智能体.md`
- Development Constitution: `.specify/memory/constitution.md`
- Test Guide: `docs/dev/测试指南.md`
