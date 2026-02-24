---
name: framework-method-validation
description: Validates framework methods exist before use in Weline Framework. Use when calling ORM operations, framework APIs, or unfamiliar methods. CRITICAL - Never invent methods! Always verify with grep/search first. Undefined method, 方法不存在.
globs:
  - "**/*.php"
alwaysApply: false
---

# Framework Method Validation

This skill ensures all framework methods are verified to exist before use. **Never invent methods** - always verify framework support first.

## ⚠️ CRITICAL: Verify Methods Before Use

**Golden Rule**: All methods must be verified to exist in the framework before use.

### Prohibited Practices

- ❌ **MUST NOT**: Invent or assume methods exist
- ❌ **MUST NOT**: Use methods without verification
- ❌ **MUST NOT**: "Improve" or "optimize" framework methods
- ❌ **MUST NOT**: Use methods from other frameworks (e.g., Magento, Laravel)

### Required Practices

- ✅ **MUST**: Verify method exists before use
- ✅ **MUST**: Search framework code or documentation
- ✅ **MUST**: Use only framework-provided APIs
- ✅ **MUST**: Follow framework conventions strictly

## Verification Process

### Step 1: Search Framework Code

```bash
# Search for method in framework
grep -r "function methodName" app/code/Weline/Framework

# Or use codebase search
codebase_search "How does methodName work in the framework?"
```

### Step 2: Check Documentation

```bash
# Check development documentation
grep -r "methodName" docs/dev/开发文档.md

# Check module documentation
grep -r "methodName" app/code/Weline/ModuleName/doc/
```

### Step 3: Verify Method Signature

```php
// Read actual implementation
read_file "app/code/Weline/Framework/Path/To/Class.php"

// Verify parameters and return type
```

## Common Mistakes

### ❌ Wrong: Using Non-Existent Methods

```php
// ❌ WRONG: fetchOne() does not exist
$user = $model->where('id', 1)->fetchOne();

// ❌ WRONG: tableColumnExist() does not exist
if ($setup->tableColumnExist('field')) {
    // ...
}

// ❌ WRONG: beginTransaction() may not exist
$connection->beginTransaction();
```

### ✅ Correct: Use Verified Methods

```php
// ✅ CORRECT: Use actual framework methods
$user = $model->where('id', 1)->find()->fetch();

// ✅ CORRECT: Use actual setup methods
if ($setup->columnExist('field')) {
    // ...
}

// ✅ CORRECT: Verify transaction support first
// Check framework documentation for transaction methods
```

## Framework ORM Methods

### Verified Query Methods

**Query Building (must call fetch() to execute):**
- `select()` - Build SELECT query
- `find()` - Build SELECT query with WHERE
- `where()` - Add WHERE condition
- `order()` - Add ORDER BY
- `group()` - Add GROUP BY
- `having()` - Add HAVING
- `limit()` - Add LIMIT
- `page()` - Add pagination
- `fields()` - Select specific fields

**Execution Methods (required to execute):**
- `fetch()` - Execute and return single result
- `fetchArray()` - Execute and return array
- `total()` - Get total count

**Data Operations (must call fetch() to execute):**
- `insert()` - Insert data
- `update()` - Update data
- `delete()` - Delete data
- `inc()` - Increment field
- `dec()` - Decrement field

### ❌ Common Mistakes

```php
// ❌ WRONG: Query not executed
$model->where('id', 1)->select();
// Missing fetch() - query never executes!

// ❌ WRONG: Using non-existent method
$user = $model->fetchOne(); // Method doesn't exist

// ❌ WRONG: Assuming method exists
$model->beginTransaction(); // May not exist
```

### ✅ Correct Usage

```php
// ✅ CORRECT: Execute query
$user = $model->where('id', 1)->find()->fetch();

// ✅ CORRECT: Execute and get array
$users = $model->select()->fetchArray();

// ✅ CORRECT: Execute update
$model->where('id', 1)->update(['name' => 'New'])->fetch();

// ✅ CORRECT: Get total
$total = $model->select()->total();
```

## Framework Setup Methods

### Verified Setup Methods

**Table Operations:**
- `tableExist()` - Check if table exists
- `createTable()` - Create table
- `dropTable()` - Drop table

**Column Operations:**
- `columnExist()` - Check if column exists
- `addColumn()` - Add column
- `modifyColumn()` - Modify column
- `dropColumn()` - Drop column

**Index Operations:**
- `indexExist()` - Check if index exists
- `addIndex()` - Add index
- `dropIndex()` - Drop index

### ❌ Common Mistakes

```php
// ❌ WRONG: Non-existent method
if ($setup->tableColumnExist('field')) { }

// ❌ WRONG: Wrong method name
$setup->addField('name', 'varchar'); // Should be addColumn
```

### ✅ Correct Usage

```php
// ✅ CORRECT: Use actual method
if ($setup->columnExist('field')) {
    $setup->modifyColumn('field', TableInterface::column_type_VARCHAR, 255);
} else {
    $setup->addColumn('field', TableInterface::column_type_VARCHAR, 255);
}
```

## Verification Checklist

Before using any framework method:

- [ ] Searched framework code for method existence
- [ ] Checked framework documentation
- [ ] Verified method signature (parameters, return type)
- [ ] Confirmed method is part of framework API
- [ ] No assumptions made about method existence
- [ ] Not using methods from other frameworks

## Search Commands

### Search Framework Code

```bash
# Search for method definition
grep -r "function methodName" app/code/Weline/Framework

# Search for method usage
grep -r "->methodName(" app/code/Weline/Framework

# Search in specific module
grep -r "function methodName" app/code/Weline/ModuleName
```

### Use Codebase Search

```php
// Search for method usage patterns
codebase_search "How is methodName used in the framework?"

// Search for similar methods
codebase_search "What methods are available for querying data?"
```

## Reference

- Development Documentation: `docs/dev/开发文档.md`
- Framework API: `app/code/Weline/Framework/`
- AI Prompt Guide: `dev/ai/AI 提示词.md` (Section: Framework Method Validation)
