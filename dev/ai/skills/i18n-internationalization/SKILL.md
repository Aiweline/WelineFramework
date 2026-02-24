---
name: i18n-internationalization
description: Implements internationalization (i18n) in Weline Framework. CRITICAL - ALL user-facing text MUST use i18n! Use when writing __() function, <lang> tags, translation files (CSV), multilingual support, 翻译, 多语言, 国际化. NEVER use %1/%2 format - must use %{1}/%{name} with braces!
globs:
  - "**/i18n/**/*.csv"
  - "**/*.phtml"
alwaysApply: false
---

# Internationalization (i18n) in Weline Framework

This skill guides you through implementing internationalization in Weline Framework using the `__()` translation function and `<lang>` template tags.

## 🔔 TRIGGER: When to Use This Skill

**ALWAYS use this skill when:**
- User mentions "翻译" (translation)
- User mentions "多语言" (multilingual)
- User mentions "国际化" (internationalization)
- User mentions "i18n"
- User mentions "语言切换" (language switching)
- User mentions text that needs to display in multiple languages
- Creating or modifying user-facing text in any module
- Adding new features with labels, messages, or UI text


## 鈿狅笍 CRITICAL: All User-Facing Text Must Use i18n

**EVERY user-facing text MUST use internationalization, including but not limited to:**

- 鉁?**All prompts and hints** (鎻愮ず淇℃伅銆佹彁绀鸿瘝銆佹彁绀烘枃鏈?
- 鉁?**All button labels** (鎸夐挳鏂囨湰銆佹寜閽爣绛?
- 鉁?**All form labels** (琛ㄥ崟鏍囩銆佽緭鍏ユ鏍囩)
- 鉁?**All error messages** (閿欒娑堟伅銆侀敊璇彁绀?
- 鉁?**All success messages** (鎴愬姛娑堟伅銆佹垚鍔熸彁绀?
- 鉁?**All warning messages** (璀﹀憡娑堟伅銆佽鍛婃彁绀?
- 鉁?**All notification messages** (閫氱煡娑堟伅銆侀€氱煡鎻愮ず)
- 鉁?**All tooltips** (宸ュ叿鎻愮ず銆佹偓鍋滄彁绀?
- 鉁?**All placeholder text** (鍗犱綅绗︽枃鏈€佽緭鍏ユ彁绀?
- 鉁?**All table headers** (琛ㄥご銆佸垪鏍囬)
- 鉁?**All menu items** (鑿滃崟椤广€佸鑸」)
- 鉁?**All page titles** (椤甸潰鏍囬)
- 鉁?**All help text** (甯姪鏂囨湰銆佽鏄庢枃鏈?
- 鉁?**All validation messages** (楠岃瘉娑堟伅銆佹牎楠屾彁绀?
- 鉁?**All confirmation dialogs** (纭瀵硅瘽妗嗐€佺‘璁ゆ彁绀?
- 鉁?**All status text** (鐘舵€佹枃鏈€佺姸鎬佹彁绀?

**Rule: If it's visible to the user, it MUST be internationalized!**

**Never hardcode any user-facing text in any language!**


## Quick Start

### Translation Function: `__()`

The `__()` function is the core translation function available in PHP, JavaScript, and templates.

**Function Signature:**
```php
function __(string $words, array|string|int $args = ''): string
```

**Basic Usage:**
```php
// Simple translation
echo __('用户管理');
// Output: 用户管理 (Chinese) or User Management (English)

// With parameters
echo __('欢迎 %{}!', $username);
echo __('用户 %{name} 有 %{count} 条消息', ['name' => $name, 'count' => $count]);
```

## File Structure

### Translation Files Location

Translation files are placed in the module's `i18n/` directory:

```
app/code/YourModule/
├── i18n/
│   ├── zh_Hans_CN.csv     # Chinese translation (default)
│   └── en_US.csv          # English translation
├── Controller/
└── ...
```

**Important**: Always use `zh_Hans_CN.csv` for Chinese, not `zh_CN.csv`.

### CSV File Format

Translation files use CSV format with double quotes:

```csv
"源文本","翻译文本"
"用户管理","用户管理"
"操作成功","操作成功"
"用户 %{name} 有 %{count} 条消息","用户 %{name} 有 %{count} 条消息"
```

**Chinese file (zh_Hans_CN.csv):**
- Source text and translation are usually the same
- Placeholders remain unchanged

**English file (en_US.csv):**
- Provides English translations
- Placeholders remain unchanged

## Usage in PHP

### Basic Translation

```php
// Simple translation
$title = __('用户管理');
$this->assign('title', $title);

// In controllers
public function index()
{
    $this->assign('title', __('用户管理'));
    $this->messageManager->addSuccess(__('操作成功'));
    return $this->fetch();
}
```

### With Placeholders

**1. Generic placeholder `%{}` (single parameter):**
```php
echo __('欢迎 %{}!', $username);
// Output: 欢迎 John!
```

**2. Numeric placeholders `%{1}`, `%{2}`, ... (multiple parameters):**
```php
echo __('用户 %{1} 在 %{2} 登录', [$username, $loginTime]);
// Output: 用户 John 在 2025-01-26 登录
```

**3. Named placeholders `%{name}`, `%{count}`, ... (recommended):**
```php
echo __('用户 %{name} 有 %{count} 条消息', [
    'name' => $username,
    'count' => $messageCount
]);
// Output: 用户 John 有 5 条消息
```

### In Controllers

```php
namespace YourModule\Controller;

class Index extends BackendController
{
    public function postSave()
    {
        try {
            $this->model->save();
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('保存成功')
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

### In Models

```php
public function validate()
{
    if (empty($this->getData('name'))) {
        throw new \Exception(__('用户名不能为空'));
    }
    
    if (strlen($this->getData('password')) < 8) {
        throw new \Exception(__('密码长度不能少于 %{min} 位', ['min' => 8]));
    }
}
```

## Usage in Templates

### 鈿狅笍 IMPORTANT: Priority Rule for Templates

**When translating in templates, ALWAYS prioritize tag form (`<lang>`) over PHP function (`__()`) when there are NO dynamic parameters.**

**Rule:**
- 鉁?**No dynamic parameters** 鈫?Use `<lang>` tag (compiled at build time, better performance)
- 鉁?**Has dynamic parameters** 鈫?Use `<lang>` tag with `args` attribute or PHP `__()` function
- 鉂?**Never use PHP `__()` function when no parameters are needed**

**Why?**
- `<lang>` tags are translated at compile time (no runtime overhead)
- Cleaner, more semantic template code
- Better performance for static text
- Consistent with framework best practices

**Examples:**
```html
<!-- 鉁?CORRECT: No parameters, use <lang> tag -->
<h1><lang>鐢ㄦ埛绠＄悊</lang></h1>
<button><lang>淇濆瓨</lang></button>

<!-- 鉂?WRONG: No parameters, but using PHP function -->
<h1><?= __('鐢ㄦ埛绠＄悊') ?></h1>
<button><?= __('淇濆瓨') ?></button>

<!-- 鉁?CORRECT: Has parameters, use <lang> with args -->
<p><lang args="$username">娆㈣繋 %{}!</lang></p>

<!-- 鉁?ALSO CORRECT: Has parameters, can use PHP function -->
<p><?= __('娆㈣繋 %{}!', $username) ?></p>
```


### Using PHP Function

```php
<?php /** @var \Weline\Framework\View\Template $this */ ?>

<h1><?= __('用户管理') ?></h1>
<p><?= __('欢迎 %{}!', $username) ?></p>
<p><?= __('用户 %{name} 有 %{count} 条消息', [
    'name' => $username,
    'count' => $message_count
]) ?></p>

<button><?= __('保存') ?></button>
```

### Using `<lang>` Tag (Recommended)

**Basic usage:**
```html
<h1><lang>用户管理</lang></h1>
<p><lang>欢迎使用系统</lang></p>
```

**With parameters using `args` attribute:**
```html
<!-- Single parameter -->
<p><lang args="'John'">欢迎 %{}!</lang></p>

<!-- Array parameters -->
<p><lang args="['John', 5]">用户 %{1} 有 %{2} 条消息</lang></p>

<!-- Named parameters (recommended) -->
<p><lang args="['name' => $username, 'count' => $message_count]">
    用户 %{name} 有 %{count} 条消息
</lang></p>

<!-- Using template variables -->
<p><lang args="$username">欢迎 %{}!</lang></p>
```

**Auto variable recognition (smart feature):**
```html
<?php $min = 8; $max = 20; ?>
<lang>密码长度必须在 %{min} 到 %{max} 个字符之间</lang>
<!-- Automatically uses $min and $max variables -->
```

### Using `@lang()` Format

```html
<!-- Basic -->
<title>@lang(网站维护中...)</title>

<!-- With parameters -->
<p>@lang(欢迎 %{}!, 'John')</p>
<p>@lang(用户 %{1} 有 %{2} 条消息, ['John', 5])</p>
<p>@lang(用户 %{name} 有 %{count} 条消息, ['name' => 'John', 'count' => 5])</p>
```

### Using `@lang{}` Format

```html
<!-- Basic -->
<span>@lang{返回首页}</span>

<!-- With parameters -->
<p>@lang{欢迎 %{}!, 'John'}</p>
<p>@lang{用户 %{name} 有 %{count} 条消息, ['name' => 'John', 'count' => 5]}</p>
```

### In HTML Attributes

```html
<input type="text" placeholder="<?= __('请输入用户名') ?>" />
<button title="<?= __('点击保存') ?>"><?= __('保存') ?></button>

<!-- Or using lang tag -->
<input type="text" placeholder="<lang>请输入用户名</lang>" />
```

## Usage in JavaScript

The `__()` function is automatically injected into pages and can be used directly in JavaScript.

### Basic Usage

```javascript
// Simple translation
console.log(__('用户管理'));
document.getElementById('title').innerText = __('用户管理');
```

### With Placeholders

**1. Generic placeholder:**
```javascript
console.log(__('欢迎 %{}!', username));
// Output: 欢迎 John!
```

**2. Array parameters:**
```javascript
console.log(__('用户 %{1} 有 %{2} 条消息', [username, count]));
// Output: 用户 John 有 5 条消息
```

**3. Object parameters (recommended):**
```javascript
console.log(__('用户 %{name} 有 %{count} 条消息', {
    name: username,
    count: messageCount
}));
// Output: 用户 John 有 5 条消息
```

### In Event Handlers

```javascript
// Button click
button.addEventListener('click', function() {
    if (confirm(__('确定要删除 %{count} 项吗？', {
        count: selectedItems.length
    }))) {
        // Delete operation
    }
});

// AJAX callbacks
$.ajax({
    url: '/api/users',
    success: function(data) {
        showMessage(__('成功加载 %{count} 个用户', {
            count: data.length
        }));
    },
    error: function() {
        showError(__('加载失败，请稍后重试'));
    }
});

// Form validation
function validateForm() {
    let errors = [];
    
    if (!username) {
        errors.push(__('字段 %{field} 不能为空', {
            field: __('用户名')
        }));
    }
    
    if (password.length < 8) {
        errors.push(__('密码长度不能少于 %{min} 位', {
            min: 8
        }));
    }
    
    if (errors.length > 0) {
        alert(__('发现 %{count} 个错误：\n%{errors}', {
            count: errors.length,
            errors: errors.join('\n')
        }));
        return false;
    }
    
    return true;
}
```

### In Template Scripts

```html
<script>
    // Simple translation
    var title = '<?= __('用户管理') ?>';
    
    // With parameters
    function showWelcome(name) {
        alert('<?= __('欢迎 %{}!') ?>'.replace('%{}', name));
    }
    
    // Using framework's __() function
    function showUserInfo(name, count) {
        return __('用户 %{name} 有 %{count} 条消息', {
            name: name,
            count: count
        });
    }
</script>
```

## Placeholder Formats

### Comparison Table

| Format | Parameter Type | Use Case | Example |
|--------|---------------|----------|---------|
| `%{}` | String/Number | Single parameter | `__('欢迎 %{}!', $name)` |
| `%{1}`, `%{2}` | Array | Multiple parameters, fixed order | `__('用户 %{1} 有 %{2} 条消息', [$name, $count])` |
| `%{name}`, `%{count}` | Object/Assoc Array | Multiple parameters, semantic (recommended) | `__('用户 %{name} 有 %{count} 条消息', ['name' => $name, 'count' => $count])` |

### ⚠️ CRITICAL ERROR: NEVER Use `%1`, `%2` Format (Without Braces)

**❌ WRONG - This is a common mistake:**
```php
// WRONG! Missing braces {}
__('加载失败：%1', $error);
__('共 %1 个站点，第 %2/%3 页', [$total, $page, $pages]);
__('百度返回错误：%1 - %2', [$error, $message]);
```

**✅ CORRECT - Always use braces `{}`:**
```php
// Single parameter - use %{1}
__('加载失败：%{1}', $error);

// Multiple parameters (array) - use %{1}, %{2}, %{3}
__('共 %{1} 个站点，第 %{2}/%{3} 页', [$total, $page, $pages]);
__('百度返回错误：%{1} - %{2}', [$error, $message]);

// RECOMMENDED: Named parameters for better clarity
__('加载失败：%{error}', ['error' => $error]);
__('共 %{total} 个站点，第 %{page}/%{pages} 页', [
    'total' => $total,
    'page' => $page,
    'pages' => $pages
]);
```

**Why this matters:**
- `%1`, `%2` (without braces) is NOT a valid placeholder in Weline Framework
- Framework will NOT replace these with parameter values
- Translation will display literal `%1`, `%2` text to users
- Must use `%{1}`, `%{2}` with braces for numeric placeholders

**Migration pattern:**
```php
// Before (WRONG)          → After (CORRECT)
__('错误：%1', $msg)      → __('错误：%{1}', $msg)
__('用户 %1 有 %2 条', [$name, $count]) 
                          → __('用户 %{1} 有 %{2} 条', [$name, $count])

// Better: Use named placeholders
__('错误：%1', $msg)      → __('错误：%{error}', ['error' => $msg])
__('用户 %1 有 %2 条', [$name, $count])
                          → __('用户 %{name} 有 %{count} 条', [
                                'name' => $name,
                                'count' => $count
                            ])
```

**Note:** `%{}` (empty braces) is valid ONLY for single unnamed parameter:
```php
// Valid for single parameter
__('欢迎 %{}!', $username);
// But %{1} is clearer and more consistent
__('欢迎 %{1}!', $username);
```

#

## Common Scenarios Requiring i18n

### Form Elements
```html
<!-- 鉁?CORRECT -->
<label><lang>鐢ㄦ埛鍚?/lang></label>
<input type="text" placeholder="<lang>璇疯緭鍏ョ敤鎴峰悕</lang>" />
<button><lang>鎻愪氦</lang></button>
<small><lang>鐢ㄦ埛鍚嶉暱搴︿负3-20涓瓧绗?/lang></small>

<!-- 鉂?WRONG -->
<label>鐢ㄦ埛鍚?/label>
<input type="text" placeholder="璇疯緭鍏ョ敤鎴峰悕" />
<button>鎻愪氦</button>
<small>鐢ㄦ埛鍚嶉暱搴︿负3-20涓瓧绗?/small>
```

### Messages and Notifications
```php
// 鉁?CORRECT
$this->messageManager->addSuccess(__('鎿嶄綔鎴愬姛'));
$this->messageManager->addError(__('鎿嶄綔澶辫触锛?{error}', ['error' => $error]));
$this->messageManager->addWarning(__('璇锋敞鎰忥細%{message}', ['message' => $msg]));

// 鉂?WRONG
$this->messageManager->addSuccess('鎿嶄綔鎴愬姛');
$this->messageManager->addError('鎿嶄綔澶辫触锛? . $error);
```

### Tooltips and Titles
```html
<!-- 鉁?CORRECT -->
<span title="<lang>鐐瑰嚮鏌ョ湅璇︽儏</lang>"><lang>璇︽儏</lang></span>
<a href="#" title="<lang>缂栬緫鐢ㄦ埛淇℃伅</lang>"><lang>缂栬緫</lang></a>

<!-- 鉂?WRONG -->
<span title="鐐瑰嚮鏌ョ湅璇︽儏">璇︽儏</span>
<a href="#" title="缂栬緫鐢ㄦ埛淇℃伅">缂栬緫</a>
```

### Table Headers
```html
<!-- 鉁?CORRECT -->
<th><lang>鐢ㄦ埛鍚?/lang></th>
<th><lang>閭</lang></th>
<th><lang>鎿嶄綔</lang></th>

<!-- 鉂?WRONG -->
<th>鐢ㄦ埛鍚?/th>
<th>閭</th>
<th>鎿嶄綔</th>
```

### Validation Messages
```php
// 鉁?CORRECT
if (empty($username)) {
    throw new \Exception(__('鐢ㄦ埛鍚嶄笉鑳戒负绌?));
}
if (strlen($password) < 8) {
    throw new \Exception(__('瀵嗙爜闀垮害涓嶈兘灏戜簬 %{min} 浣?, ['min' => 8]));
}

// 鉂?WRONG
if (empty($username)) {
    throw new \Exception('鐢ㄦ埛鍚嶄笉鑳戒负绌?);
}
```

### Confirmation Dialogs
```javascript
// 鉁?CORRECT
if (confirm(__('纭畾瑕佸垹闄?%{count} 椤瑰悧锛?, {count: selectedItems.length}))) {
    // Delete operation
}

// 鉂?WRONG
if (confirm('纭畾瑕佸垹闄?' + selectedItems.length + ' 椤瑰悧锛?)) {
    // Delete operation
}
```


## ❌ ANTI-PATTERNS: What NOT to Do

### NEVER Hardcode Language Checks

**❌ WRONG - Hardcoding language detection:**
```php
// This is TERRIBLE! Never do this!
$lang = State::getLangLocal();
$isEnglish = str_starts_with($lang, 'en');
return $isEnglish ? 'Free Shipping' : __('免运费');
```

**✅ CORRECT - Use `__()` function only:**
```php
// Simple and clean - let the i18n system handle translation
return __('免运费');
```

**Why is hardcoding bad?**
- Duplicates translation logic across codebase
- Hard to maintain when adding new languages
- Bypasses the centralized i18n system
- Creates inconsistent behavior

### NEVER Skip Translation Files

**❌ WRONG:**
- Only using `__()` without creating translation CSV files
- Assuming Chinese will "just work" without zh_Hans_CN.csv

**✅ CORRECT:**
1. Use `__('中文文本')` in code
2. Create `i18n/en_US.csv` with English translations
3. Create `i18n/zh_Hans_CN.csv` with Chinese (source = translation)
4. Run `php bin/w i18n:collect` to collect translations

## Best Practices

1. **⚠️ CRITICAL: ALL user-facing text MUST use i18n** - No exceptions!
   - Every prompt, hint, button, label, message, tooltip, placeholder, etc.
   - Never hardcode any user-facing text in any language
   - Always ask: "Is this visible to the user?" → If yes, use i18n

2. **In templates, use `<?= __() ?>` for runtime translation in hook templates**
   - Hook templates may load after initial translation, so use PHP function
   - Regular templates can use `<lang>` tag for compile-time translation
   
   ```html
   <!-- In hook templates (view/hooks/) - use PHP function -->
   <h1><?= __('用户管理') ?></h1>
   
   <!-- In regular templates - can use <lang> tag -->
   <h1><lang>用户管理</lang></h1>
   ```

3. **Always use `__()` function** for all user-facing text in PHP code
4. **Use Chinese in code**, provide translations in CSV files
5. **Use named placeholders** for clarity and maintainability
6. **Keep text complete** - don't split sentences
7. **Run `i18n:collect`** after adding new translation text: `php bin/w i18n:collect`
8. **Test in multiple languages** after implementation
9. **Clear cache** after updating translation files: `php bin/w cache:flush -a`
10. **Delete compiled templates** when translation changes: Remove `view/tpl/` folders

## Common Issues

### Translation Not Working

- Check translation files exist in `i18n/` directory
- Verify CSV format (double quotes, comma-separated)
- Clear cache: `php bin/w cache:flush -a`
- Check translation entry exists in CSV file
- **Run `i18n:collect` command** to collect all translations: `php bin/w i18n:collect`

### Placeholders Not Replaced

- Verify placeholder format (`%{}`, `%{1}`, `%{name}`)
- Check parameter passing is correct
- Ensure placeholders are consistent in all language files

### JavaScript `__()` Function Undefined

- Ensure framework JavaScript files are loaded
- Check browser console for errors
- Verify framework is properly initialized

### CSV Format Errors

- CSV files must use double quotes around text
- Comma-separated two columns, no extra commas
- One translation pair per line, no line breaks

### Translation Not Working in Hook Templates

When using translations in hook templates (e.g., `view/hooks/`), the module's translations may not be loaded. Add these lines at the top of your hook template:

```php
// Add module to request chain for translation loading
$this->request->addModule('YourModule_Name');
// Force reload translations (module may be added after initial translation load)
\Weline\Framework\Phrase\Parser::$loaded = false;
```

### Creating i18n CSV Files Programmatically

When creating i18n files via scripts, use PHP to avoid encoding issues:

```php
<?php
// create_i18n.php
$enUS = <<<'CSV'
"源文本","English Translation"
"保存","Save"
"删除","Delete"
CSV;

$zhHansCN = <<<'CSV'
"源文本","源文本"
"保存","保存"
"删除","删除"
CSV;

$dir = 'app/code/YourModule/i18n';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($dir . '/en_US.csv', $enUS);
file_put_contents($dir . '/zh_Hans_CN.csv', $zhHansCN);
echo "i18n files created!\n";
```

Run with: `php create_i18n.php`

## Complete Workflow for Adding Translations

### Step 1: Use `__()` in Your Code

```php
// In PHP classes (Controllers, Models, Services, etc.)
public function getName(): string
{
    return __('配送方式');  // Chinese source text
}

public function getOptions(): array
{
    return [
        ['label' => __('免运费'), 'value' => 'free'],
        ['label' => __('次日达'), 'value' => 'next_day'],
    ];
}
```

### Step 2: Create Translation Files

Create `app/code/YourModule/i18n/en_US.csv`:
```csv
"配送方式","Shipping"
"免运费","Free Shipping"
"次日达","Next Day Delivery"
```

Create `app/code/YourModule/i18n/zh_Hans_CN.csv`:
```csv
"配送方式","配送方式"
"免运费","免运费"
"次日达","次日达"
```

### Step 3: Collect Translations

```bash
php bin/w i18n:collect
```

### Step 4: Clear Cache

```bash
php bin/w cache:flush -a
```

### Step 5: Delete Compiled Templates (if needed)

```bash
# PowerShell
Remove-Item -Path "app/code/YourModule/view/tpl" -Recurse -Force

# Bash
rm -rf app/code/YourModule/view/tpl
```

### Step 6: Test

Test in browser with different language URLs:
- Chinese: `http://localhost/CNY/zh_Hans_CN/your-page`
- English: `http://localhost/CNY/en_US/your-page`

Or use CLI:
```bash
php bin/w http:req "/CNY/en_US/your-page" "YourSearchTerm" -n=5
```

## Reference Files

- Translation Function: `app/code/Weline/Framework/Common/functions.php`
- Translation Guide: `app/code/Weline/Framework/doc/3-开发/01-翻译函数使用指南.md`
- Placeholder Guide: `app/code/Weline/Framework/doc/i18n-placeholder-usage.md`
- Lang Tag Guide: `app/code/Weline/Framework/doc/4-内置标签/01-lang标签使用指南.md`
- I18n Module: `app/code/Weline/I18n/doc/README.md`
- Phrase Parser: `app/code/Weline/Framework/Phrase/Parser.php`










