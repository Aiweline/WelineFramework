---
name: frontend-automation-testing
description: Frontend automation testing with Playwright and Browser MCP in Weline Framework. Use when testing UI interactions, frontend features, or user workflows. Covers E2E tests, browser automation, 前端测试, e2e, playwright, browser test.
globs:
  - "**/tests/**/*.ts"
  - "**/view/**/*.phtml"
alwaysApply: false
---

# Frontend Automation Testing in Weline Framework

This skill enforces frontend automation testing for all frontend features. **Frontend testing is MANDATORY** - all UI features must be tested with automation.

## ⚠️ CRITICAL: Frontend Testing is Mandatory

**Core Principle**: If a feature involves frontend pages, it MUST be tested with automation.

**Two Testing Methods:**
1. **Playwright E2E Tests** - For comprehensive end-to-end testing
2. **Browser MCP** - For quick interactive testing during development

## Testing Methods

### Method 1: Playwright E2E Tests (Recommended for CI/CD)

**Purpose**: Comprehensive end-to-end testing of user workflows

**Location**: 
- Module tests: `app/code/YourModule/test/e2e/`
- Global tests: `tests/e2e/`

#### Quick Start

```bash
# One command: collect and run all tests
cd tests/e2e
npm start

# Run specific module tests
npm start -- --module=Weline_Theme

# UI mode (recommended for debugging)
npm start -- --ui

# Headed mode (see browser window)
npm start -- --headed
```

#### Test File Structure

```
app/code/YourModule/
└── test/
    └── e2e/
        ├── frontend/          # Frontend tests
        │   └── your-test.spec.js
        └── backend/           # Backend UI tests
            └── admin-test.spec.js
```

#### Writing Playwright Tests

**Basic Test Template:**

```javascript
// app/code/Weline/Theme/test/e2e/frontend/theme-override.spec.js
const { test, expect } = require('@playwright/test');

test.describe('Theme frontend override behavior', () => {
  test('should override parent theme files', async ({ page }) => {
    // Navigate to page
    await page.goto('/');
    
    // Wait for element
    const header = page.locator('header');
    await expect(header.first()).toBeVisible();
    
    // Verify content
    await expect(page.getByText('Shop by Category')).toBeVisible();
  });
});
```

**Login Test Example:**

```javascript
test.describe('Admin Login', () => {
  test('should login successfully', async ({ page }) => {
    await page.goto('/admin/login');
    
    // Fill form
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'admin');
    
    // Submit
    await page.click('button[type="submit"]');
    
    // Verify redirect
    await page.waitForURL('**/admin/**');
    await expect(page).toHaveURL(/admin/);
    
    // Verify login success
    await expect(page.locator('.user-info')).toBeVisible();
  });
});
```

**Form Interaction Example:**

```javascript
test('should submit form successfully', async ({ page }) => {
  await page.goto('/your-page');
  
  // Fill form fields
  await page.fill('input[name="name"]', 'Test Name');
  await page.fill('input[name="email"]', 'test@example.com');
  
  // Select dropdown
  await page.selectOption('select[name="type"]', 'option-value');
  
  // Click submit
  await page.click('button[type="submit"]');
  
  // Wait for success message
  await expect(page.locator('.alert-success')).toBeVisible();
  await expect(page.locator('.alert-success')).toContainText('保存成功');
});
```

#### Test Organization

**Module-based Structure:**
- Tests in module's `test/e2e/` directory
- Automatic collection via `modules.json`
- Unified execution through Playwright

**Naming Convention:**
- Test files: `*.spec.js`
- Descriptive names: `theme-override.spec.js`, `user-login.spec.js`

#### Running Playwright Tests

```bash
# Generate modules.json (auto-generated on setup:upgrade)
php bin/w setup:upgrade

# Run all tests (auto-collects and runs)
cd tests/e2e
npm start

# Run specific module
npm start -- --module=Weline_Theme

# Run specific test file
npm start -- app/code/Weline/Theme/test/e2e/frontend/theme-override.spec.js

# UI mode (interactive debugging)
npm run test:ui

# Headed mode (see browser)
npm run test:headed
```

### Method 2: Browser MCP (Recommended for Development)

**Purpose**: Quick interactive testing during development

**When to Use:**
- Quick verification during development
- Interactive debugging
- Immediate feedback
- Testing before writing Playwright tests

#### Browser MCP Tools

**Available Tools:**
- `mcp_cursor-browser-extension_browser_navigate` - Navigate to URL
- `mcp_cursor-browser-extension_browser_snapshot` - Get page snapshot
- `mcp_cursor-browser-extension_browser_click` - Click element
- `mcp_cursor-browser-extension_browser_type` - Type text
- `mcp_cursor-browser-extension_browser_fill` - Fill form field
- `mcp_cursor-browser-extension_browser_scroll` - Scroll page

#### Browser MCP Workflow

**Step 1: Navigate to Page**

```javascript
// Navigate to page
mcp_cursor-browser-extension_browser_navigate({
  url: 'http://127.0.0.1:9981/admin/login'
})
```

**Step 2: Get Page Snapshot**

```javascript
// Get page structure
mcp_cursor-browser-extension_browser_snapshot()
// Returns: page structure with element references
```

**Step 3: Interact with Page**

```javascript
// Click element
mcp_cursor-browser-extension_browser_click({
  elementRef: 'button-id-123'
})

// Fill form field
mcp_cursor-browser-extension_browser_fill({
  elementRef: 'input-username-456',
  value: 'admin'
})

// Type text
mcp_cursor-browser-extension_browser_type({
  elementRef: 'input-password-789',
  text: 'admin'
})
```

**Step 4: Verify Results**

```javascript
// Get updated snapshot
mcp_cursor-browser-extension_browser_snapshot()

// Verify element exists
// Check snapshot for expected elements
```

#### Complete Browser MCP Test Example

```javascript
// 1. Navigate to login page
mcp_cursor-browser-extension_browser_navigate({
  url: 'http://127.0.0.1:9981/admin/login'
})

// 2. Get page snapshot
const snapshot = mcp_cursor-browser-extension_browser_snapshot()

// 3. Fill login form
mcp_cursor-browser-extension_browser_fill({
  elementRef: snapshot.elements.find(e => e.placeholder === '用户名').ref,
  value: 'admin'
})

mcp_cursor-browser-extension_browser_fill({
  elementRef: snapshot.elements.find(e => e.type === 'password').ref,
  value: 'admin'
})

// 4. Click login button
mcp_cursor-browser-extension_browser_click({
  elementRef: snapshot.elements.find(e => e.text === '登录').ref
})

// 5. Wait and verify
// Wait for navigation
await new Promise(resolve => setTimeout(resolve, 2000))

// Get new snapshot
const newSnapshot = mcp_cursor-browser-extension_browser_snapshot()

// Verify login success (check for admin dashboard elements)
```

## Testing Requirements by Feature Type

### Frontend Page Features

**Must test:**
- Page loads correctly
- UI elements display properly
- User interactions work
- Navigation functions
- Form submissions
- Error handling

**Example:**
```javascript
test('page should load and display correctly', async ({ page }) => {
  await page.goto('/your-page');
  await expect(page.locator('h1')).toBeVisible();
  await expect(page.locator('h1')).toContainText('Page Title');
});
```

### Form Features

**Must test:**
- Form fields are accessible
- Validation works
- Submission succeeds
- Error messages display
- Success feedback shows

**Example:**
```javascript
test('form should submit successfully', async ({ page }) => {
  await page.goto('/form-page');
  await page.fill('input[name="name"]', 'Test');
  await page.click('button[type="submit"]');
  await expect(page.locator('.success-message')).toBeVisible();
});
```

### Interactive Features

**Must test:**
- Click events work
- Dropdowns function
- Modals open/close
- Tabs switch
- Accordions expand/collapse

**Example:**
```javascript
test('modal should open and close', async ({ page }) => {
  await page.goto('/page');
  await page.click('button.open-modal');
  await expect(page.locator('.modal')).toBeVisible();
  await page.click('button.close-modal');
  await expect(page.locator('.modal')).not.toBeVisible();
});
```

## Prohibited Practices

### ❌ NEVER Skip Frontend Testing

**CRITICAL RULE**: If feature involves frontend pages, testing is mandatory.

- ❌ **MUST NOT**: Skip frontend testing for frontend features
- ❌ **MUST NOT**: Assume frontend works without testing
- ❌ **MUST NOT**: Only test backend API without testing UI
- ✅ **MUST**: Use Playwright E2E or Browser MCP for all frontend features

### ❌ NEVER Test Manually Only

- ❌ **MUST NOT**: Only manual testing without automation
- ✅ **MUST**: Use automation (Playwright or Browser MCP)
- ✅ **MUST**: Document test cases in automated tests

## Testing Checklist

Before considering frontend feature complete:

- [ ] Playwright E2E tests written (for comprehensive testing)
- [ ] OR Browser MCP tests performed (for quick verification)
- [ ] Page loads correctly tested
- [ ] UI elements display correctly tested
- [ ] User interactions tested
- [ ] Form submissions tested
- [ ] Error scenarios tested
- [ ] Navigation tested
- [ ] Responsive design tested (if applicable)
- [ ] Cross-browser tested (if applicable)

## Best Practices

### 1. Use Playwright for Comprehensive Testing

- Write Playwright tests for all user workflows
- Test critical paths end-to-end
- Include error scenarios
- Test across different browsers

### 2. Use Browser MCP for Quick Verification

- Use during development for immediate feedback
- Verify before writing Playwright tests
- Test interactive debugging scenarios
- Quick smoke tests

### 3. Test User Workflows

```javascript
// Test complete user workflow
test('user can complete purchase flow', async ({ page }) => {
  // 1. Login
  await page.goto('/login');
  await page.fill('input[name="username"]', 'user');
  await page.fill('input[name="password"]', 'pass');
  await page.click('button[type="submit"]');
  
  // 2. Browse products
  await page.goto('/products');
  await page.click('.product-card:first-child');
  
  // 3. Add to cart
  await page.click('button.add-to-cart');
  
  // 4. Checkout
  await page.goto('/checkout');
  await page.fill('input[name="address"]', '123 Main St');
  await page.click('button.place-order');
  
  // 5. Verify success
  await expect(page.locator('.order-success')).toBeVisible();
});
```

### 4. Test Error Scenarios

```javascript
test('should show error on invalid input', async ({ page }) => {
  await page.goto('/form');
  await page.fill('input[name="email"]', 'invalid-email');
  await page.click('button[type="submit"]');
  await expect(page.locator('.error-message')).toBeVisible();
  await expect(page.locator('.error-message')).toContainText('邮箱格式不正确');
});
```

## Reference

- E2E Test Guide: `tests/e2e/README.md`
- Playwright Guide: `app/code/Weline/Ai/doc/开发/Playwright测试指南.md`
- Testing Guide: `docs/dev/测试指南.md`
- Browser MCP: Available through cursor-browser-extension MCP server
