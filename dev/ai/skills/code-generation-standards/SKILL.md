---
name: code-generation-standards
description: |
  Code generation standards for Weline Framework. CRITICAL for all code generation!
  
  Use when:
  - Creating PHP files, controllers, models, services, blocks
  - Writing frontend code (CSS, JS, templates) - MUST also read theme-development skill!
  - Creating modules or any code
  
  Frontend/CSS/JS 必须同时参考 theme-development 技能！
  - 写 CSS → theme-development（主题变量、禁止硬编码颜色、CSS 命名空间）
  - 写 JS → theme-development（IIFE 闭包、禁止全局变量）
  - 写组件 → theme-development（独立作用域）
  
  Keywords: PHP, 代码生成, 模块, module, controller, model, service, block,
  CSS, JS, JavaScript, 前端, frontend, 模板, template, .phtml
globs:
  - "**/*.php"
  - "**/view/**/*.phtml"
  - "**/view/**/*.js"
  - "**/view/**/*.css"
alwaysApply: false
---

# Code Generation Standards for Weline Framework

This skill ensures all generated code follows Weline Framework standards and best practices.

## 🔔 TRIGGER: When to Use This Skill

**ALWAYS use this skill when:**
- Creating new PHP files
- Creating new modules
- Writing controllers, models, services, or blocks
- Generating any code that will run in Weline Framework

## ⚠️ CRITICAL: Framework Boundary Constraints

**AI MUST stay within framework boundaries. The following are STRICTLY PROHIBITED:**

### ❌ PROHIBITED Actions

1. **Never invent framework methods** - All methods must be verified to exist
2. **Never use patterns from other frameworks** - No Laravel, Symfony, Magento patterns
3. **Never hardcode user-facing text** - ALWAYS use `__()` function (See: i18n skill)
4. **Never create files outside allowed directories**
5. **Never bypass framework's dependency injection**
6. **Never use raw SQL when ORM methods exist**
7. **Never put PHP code in comments** - Do not write `<?`, `?>`, `<?php`, `<?=` inside any comment (single-line `//` or block `/* */`). PHP 会将注释中的 `?>` 解析为结束标签，导致后续代码被当作 HTML 解析、大括号计数错乱并报 "Unclosed '{'" 等错误。若需在注释中描述 PHP 标签，用「PHP 标签」「短标签」等文字描述，或拆成 `'<' . '?'` 等非闭合形式。

### ✅ REQUIRED Actions

1. **Always verify method exists** before using it
2. **Always use `__()` for translations** - See `i18n-internationalization` skill
3. **Always follow the module structure** defined below
4. **Always use framework's ObjectManager** for dependency injection
5. **Always include `declare(strict_types=1);`**
6. **Always follow PHP 8.4 strict type rules** - See `php84-performance` skill

### 🔥 PHP 8.4 严格类型要求（必须遵守）

框架运行在 PHP 8.4 上，必须遵守严格类型约束：

| 场景 | ❌ 错误 | ✅ 正确 |
|------|---------|---------|
| 字符串函数 | `trim($var)` | `trim($var ?? '')` |
| 数组访问 | `$arr['key']` | `$arr['key'] ?? ''` |
| foreach | `foreach ($items as $item)` | `foreach (($items ?? []) as $item)` |
| htmlspecialchars | `htmlspecialchars($text)` | `htmlspecialchars($text ?? '')` |
| 方法调用 | `$obj->method()` | `$obj?->method() ?? ''` |

**详细规范见 `php84-performance` 技能。**

### 编译期内联标签（如 @lang）

模板内联标签中**编译期即可得出结果的**（如 `@lang(提交)` 的静态翻译），回调必须**直接返回最终文本**，不得返回 PHP 代码（如 `<?=__('...')?>`）。只有需要运行期求值的才返回 PHP。这样预展开后模板中直接是译文等内容，而不是再包一层 PHP 输出。

---

## 🏗️ Framework Architecture Constraints

### Allowed Module Structure (MUST Follow)

```
app/code/{Vendor}/{ModuleName}/
├── Api/                      # API interfaces and implementations
│   └── Data/                 # Data interfaces
├── Block/                    # View blocks (prepare data for templates)
│   ├── Backend/              # Backend blocks
│   └── Frontend/             # Frontend blocks
├── Console/                  # CLI commands
│   └── {CommandName}/        # Command implementation
├── Controller/               # HTTP controllers
│   ├── Backend/              # Backend controllers (admin)
│   └── Frontend/             # Frontend controllers (public)
├── Helper/                   # Helper classes (utilities)
├── Model/                    # Data models (database interaction)
│   └── ResourceModel/        # Resource models (optional)
├── Observer/                 # Event observers
├── Plugin/                   # Interceptors (around, before, after)
├── Provider/                 # Data providers
├── Service/                  # Business logic services
├── Setup/                    # Database setup (migrations)
├── Test/                     # Tests (REQUIRED)
│   ├── Unit/                 # Unit tests
│   └── Integration/          # Integration tests
├── doc/                      # Documentation
├── etc/                      # Configuration
│   ├── event.xml             # Event configuration
│   ├── backend/              # Backend configuration
│   │   └── menu.xml          # Admin menu
│   └── di.xml                # Dependency injection (if needed)
├── i18n/                     # Translations (REQUIRED for user-facing text)
│   ├── en_US.csv             # English
│   └── zh_Hans_CN.csv        # Chinese
├── view/                     # View layer
│   ├── templates/            # Template files (.phtml)
│   │   ├── Backend/          # Backend templates
│   │   └── Frontend/         # Frontend templates
│   ├── statics/              # Static assets (JS, CSS, images)
│   │   ├── js/
│   │   └── css/
│   └── hooks/                # Hook templates
│       └── {Module_Name}/    # Hooks for other modules
└── register.php              # Module registration (REQUIRED)
```

### File Placement Rules

| Type | Directory | Naming |
|------|-----------|--------|
| Controller | `Controller/{Area}/` | PascalCase.php |
| Model | `Model/` | PascalCase.php |
| Service | `Service/` | PascalCase.php + "Service" suffix |
| Block | `Block/{Area}/` | PascalCase.php |
| Helper | `Helper/` | PascalCase.php |
| Observer | `Observer/` | PascalCase.php |
| Plugin | `Plugin/` | PascalCase.php |
| Template | `view/templates/` | snake_case.phtml or PascalCase.phtml |
| Test | `Test/Unit/` | PascalCase + "Test" suffix |

---

## 📝 Code Templates

### 1. Register File (REQUIRED)

```php
<?php
declare(strict_types=1);

use Weline\Framework\Register\Register;

Register::register(
    Register::MODULE,
    'Vendor_ModuleName',
    __DIR__
);
```

### 2. Controller Template

```php
<?php
declare(strict_types=1);

namespace Vendor\ModuleName\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

/**
 * @DESC | Controller description
 */
class Index extends BackendController
{
    public function index()
    {
        // ✅ ALWAYS use __() for user-facing text
        $this->assign('title', __('页面标题'));
        return $this->fetch();
    }
    
    public function postSave()
    {
        try {
            // Save logic
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功')  // ✅ Use __()
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('保存失败：%{error}', ['error' => $e->getMessage()])
            ]);
        }
    }
}
```

### 3. Model Template

表结构使用 **声明式 #[Table]/#[Col]/#[Index]**，由 `php bin/w setup:upgrade` 触发 SchemaDiff 同步；业务初始化/种子数据放在模块 **Setup/Install.php**。Model 仅保留 `columns()` 与注解。

```php
<?php
declare(strict_types=1);

namespace Vendor\ModuleName\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '表描述')]
#[Index(name: 'idx_name', columns: ['name'], comment: '名称索引')]
class YourModel extends Model
{
    #[Col('int', 0, nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    protected mixed $id = null;
    #[Col('varchar', 255, nullable: false, comment: '名称')]
    protected mixed $name = null;
    #[Col('smallint', 1, nullable: false, default: 1, comment: '状态')]
    protected mixed $status = null;
    #[Col('datetime', nullable: true, comment: '创建时间')]
    protected mixed $created_at = null;
    #[Col('datetime', nullable: true, comment: '更新时间')]
    protected mixed $updated_at = null;

    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_STATUS = 'status';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

    public array $_unit_primary_keys = ['id'];
    public array $_index_sort_keys = ['id', 'name'];

    public function columns(): array
    {
        return $this->getModelFields();
    }
}
```

### 4. Service Template

```php
<?php
declare(strict_types=1);

namespace Vendor\ModuleName\Service;

use Vendor\ModuleName\Model\YourModel;
use Weline\Framework\Manager\ObjectManager;

/**
 * @DESC | Service description
 */
class YourService
{
    private YourModel $model;
    
    public function __construct()
    {
        // Use ObjectManager for dependency injection
        $this->model = ObjectManager::getInstance(YourModel::class);
    }
    
    /**
     * Get data by ID
     * 
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        // ✅ CORRECT: Use verified ORM methods
        return $this->model
            ->reset()
            ->where(YourModel::fields_ID, $id)
            ->find()
            ->fetch();
    }
    
    /**
     * Get all records
     * 
     * @return array
     */
    public function getAll(): array
    {
        // ✅ CORRECT: fetchArray() returns array
        return $this->model
            ->reset()
            ->select()
            ->fetchArray();
    }
}
```

### 5. Translation Files (REQUIRED)

**⚠️ CRITICAL: See `i18n-internationalization` skill for complete translation requirements!**

**i18n/en_US.csv:**
```csv
"页面标题","Page Title"
"保存成功","Save successful"
"保存失败：%{error}","Save failed: %{error}"
"名称","Name"
"状态","Status"
"创建时间","Created At"
"更新时间","Updated At"
```

**i18n/zh_Hans_CN.csv:**
```csv
"页面标题","页面标题"
"保存成功","保存成功"
"保存失败：%{error}","保存失败：%{error}"
"名称","名称"
"状态","状态"
"创建时间","创建时间"
"更新时间","更新时间"
```

**After creating translation files, MUST run:**
```bash
php bin/w i18n:collect
```

---

---

## 🌐 TRANSLATION REQUIREMENTS (CRITICAL)

**⚠️ For complete translation guide, ALWAYS use the `i18n-internationalization` skill!**

### MUST DO for Every Code Generation:

1. **ALL user-facing text MUST use `__()` function**
   ```php
   // ✅ CORRECT
   return __('保存成功');
   $label = __('用户名');
   
   // ❌ WRONG - NEVER hardcode text
   return 'Save successful';
   $label = '用户名';
   ```

2. **NEVER use hardcoded language checks**
   ```php
   // ❌ TERRIBLE - NEVER do this!
   $isEnglish = str_starts_with($lang, 'en');
   return $isEnglish ? 'Save' : __('保存');
   
   // ✅ CORRECT - Let i18n system handle it
   return __('保存');
   ```

3. **In Templates:**
   - Use `<?= __('文本') ?>` for runtime translation
   - In hook templates, add module to request chain:
   ```php
   $this->request->addModule('YourModule_Name');
   \Weline\Framework\Phrase\Parser::$loaded = false;
   ```

4. **Create Translation Files:**
   - `i18n/en_US.csv` - English translations
   - `i18n/zh_Hans_CN.csv` - Chinese (source = translation)

5. **Collect Translations:**
   ```bash
   php bin/w i18n:collect
   ```

---

## 🔍 Pre-Generation Checklist

Before generating any code, verify:

### Structure Check
- [ ] Module directory follows allowed structure
- [ ] File placed in correct directory
- [ ] Namespace matches directory path

### Code Check
- [ ] `declare(strict_types=1);` at top
- [ ] Uses framework methods (verified to exist)
- [ ] No methods from other frameworks
- [ ] Proper PHPDoc comments

### Translation Check (CRITICAL - See i18n skill)
- [ ] ALL user-facing text uses `__()` function
- [ ] NO hardcoded language checks (`$isEnglish ? 'X' : __('Y')`)
- [ ] i18n files created in `i18n/` directory
- [ ] en_US.csv has English translations
- [ ] zh_Hans_CN.csv has Chinese (source = translation)
- [ ] Run `php bin/w i18n:collect` after adding translations

### Framework Compliance
- [ ] Uses ObjectManager for dependencies
- [ ] ORM methods verified (not invented)
- [ ] Follows MVC pattern
- [ ] No raw SQL when ORM available

---

## ⚠️ Common Mistakes to Avoid

### ❌ WRONG: Hardcoding Text
```php
// ❌ NEVER do this
return ['error' => 'Save failed'];
$label = 'User Name';
```

### ✅ CORRECT: Using __()
```php
// ✅ ALWAYS use __()
return ['error' => __('保存失败')];
$label = __('用户名');
```

### ❌ WRONG: Inventing Methods
```php
// ❌ fetchOne() doesn't exist
$user = $model->fetchOne();

// ❌ beginTransaction() may not exist
$conn->beginTransaction();
```

### ✅ CORRECT: Using Verified Methods
```php
// ✅ Use verified methods
$user = $model->where('id', 1)->find()->fetch();

// ✅ Verify transaction methods exist first
```

### ❌ WRONG: Wrong File Location
```php
// ❌ Controller in wrong directory
namespace Vendor\Module\Controllers;  // Wrong: "Controllers" not "Controller"
```

### ✅ CORRECT: Proper File Location
```php
// ✅ Correct namespace matching directory
namespace Vendor\Module\Controller\Backend;
```

---

## ⚠️ CRITICAL: Testing Requirements

**All module development MUST include tests. See `module-development` skill for complete requirements.**

### Mandatory Tests:
1. **Unit Tests** - PHPUnit tests in `Test/Unit/` directory
2. **E2E Tests** - Playwright tests in `test/e2e/` directory

### Quick Test Commands:
```bash
# Run unit tests
php bin/w phpunit:run -b YourModule

# Run E2E tests
cd tests/e2e && npm start -- --module=YourModule
```

**Development is NOT complete until all tests pass.**

---

## 📚 Related Skills

- **error-learning**: **自动学习** - 遇到代码生成错误时自动调用
- **module-development**: For MANDATORY testing requirements (unit + E2E)
- **theme-development**: **REQUIRED for Theme/Frontend/Backend JS** - Module loading, URL generation, API requests
- **weline-routing**: **REQUIRED for Controllers** - Route naming, HTTP method mapping, URL generation
- **friendly-notifications**: User-friendly UI notifications (avoid alert/confirm/prompt)
- **php-unit-testing**: For detailed PHPUnit testing guide
- **error-tracking**: 错误跟踪和记录
- **frontend-automation-testing**: For detailed Playwright E2E testing guide
- **i18n-internationalization**: For ALL translation requirements
- **framework-method-validation**: For verifying framework methods
- **development-constitution**: For core development principles
- **solid-principles**: For SOLID design principles
- **quality-assurance**: For complete QA checklist

## 📖 Reference

- Framework API: `app/code/Weline/Framework/`
- Development Documentation: `docs/dev/开发文档.md`
- AI Prompt Guide: `dev/ai/AI 提示词.md`
