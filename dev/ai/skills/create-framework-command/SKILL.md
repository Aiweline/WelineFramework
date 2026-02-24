---
name: create-framework-command
description: Creates console commands for Weline Framework. Use when creating CLI commands, adding console functionality, or implementing terminal operations. Covers command structure, command:upgrade registration. ??, command, CLI, console, terminal.
globs:
  - "**/Console/**/*.php"
  - "**/Command/**/*.php"
alwaysApply: false
---

# Creating Framework Commands in Weline Framework

This skill guides you through creating console commands in the Weline Framework, following the framework's conventions and best practices.

## CRITICAL: Command Registration

**After creating any new command, you MUST run the command upgrade to register it:**

```bash
php bin/w command:upgrade
```

Without this step, the command will NOT be recognized by the system. This updates the `generated/commands.php` cache file.

Alternative: `setup:upgrade` also updates commands (but is slower):
```bash
php bin/w s:up
```

## Quick Start

### 1. Command Class Structure

Commands must implement `CommandInterface` or extend `CommandAbstract` (recommended).

**Template:**

```php
<?php

declare(strict_types=1);

namespace VendorName\ModuleName\Console\CommandGroup\SubGroup;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * Command description
 */
class YourCommand extends CommandAbstract
{
    private SomeService $someService;

    public function __construct(SomeService $someService)
    {
        $this->someService = $someService;
    }

    public function execute(array $args = [], array $data = [])
    {
        // Extract positional arguments (non-flag arguments)
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        // First positional arg is command name, remove it
        array_shift($positionalArgs);
        
        // Get your parameters
        $firstParam = $positionalArgs[0] ?? null;
        
        // Check for flags
        $verbose = isset($args['v']) || isset($args['verbose']);
        
        // Command logic here
        $this->printer->success('Command executed successfully!');
    }

    public function tip(): string
    {
        return 'Brief command description for command list';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'command-group:sub-group:your-command',
            'Detailed command description',
            [
                '-h, --help' => 'Show help information',
                '-v, --verbose' => 'Verbose output',
            ],
            [],
            [
                'Basic usage' => 'php bin/w command-group:sub-group:your-command',
            ]
        );
    }
}
```

### 2. File Location and Naming (IMPORTANT)

**Command Path Rules:**

The command name is automatically generated from the file path within the `Console` directory:

| File Path | Generated Command |
|-----------|------------------|
| `Console/Cache/Clear.php` | `cache:clear` |
| `Console/Blog/Import/Sample.php` | `blog:import:sample` |
| `Console/Product/Import/ImportDefault.php` | `product:import:import-default` |
| `Console/User/Create.php` | `user:create` |

**Naming Conversion Rules:**
1. Remove `Console/` prefix
2. Directory separators (`/`) become colons (`:`)
3. Remove `.php` extension
4. PascalCase converted to kebab-case (e.g., `ImportDefault` -> `import-default`)

**Module Commands Location:**
```
app/code/{Vendor}/{Module}/Console/{CommandGroup}/{SubGroup}/{CommandName}.php
```

**Example:**
```
app/code/GuoLaiRen/Blog/Console/Blog/Import/Sample.php
    -> Namespace: GuoLaiRen\Blog\Console\Blog\Import\Sample
    -> Command: blog:import:sample
```

### 3. Required Methods

All commands must implement:

1. **`execute(array $args = [], array $data = [])`**
   - `$args`: Parsed command-line arguments (options and flags)
   - `$data`: Additional data passed by the framework
   - Contains the main command logic
   - **Note:** Do NOT declare return type (interface has no return type)

2. **`tip(): string`**
   - Returns brief description shown in command list
   - Keep it concise (one line)

3. **`help(): array|string`**
   - Returns detailed help information
   - **CRITICAL:** Return type MUST be `array|string` (not just `string`)
   - Use `CommandHelper::formatHelp()` for consistent formatting

### 4. Dependency Injection

Commands support constructor dependency injection. The framework automatically resolves dependencies:

```php
public function __construct(
    SomeModel $model,
    SomeService $service
) {
    $this->model = $model;
    $this->service = $service;
}
```

**Note:** The `$this->printer` is automatically available from `CommandAbstract` via `__init()` method.

### 5. Command Aliases (Optional)

```php
public function aliases(): array
{
    return ['yc', 'your-cmd'];
}
```

Or use constant:
```php
public const ALIASES = ['yc', 'your-cmd'];
```

## Parameter Handling (CRITICAL)

The `$args` array contains both the command name and command-line arguments. **The first positional argument is always the command name itself.**

### Extracting Positional Parameters

```php
public function execute(array $args = [], array $data = [])
{
    // Extract positional arguments (non-flag arguments)
    $positionalArgs = [];
    foreach ($args as $key => $arg) {
        if (is_int($key) && !str_starts_with((string)$arg, '-')) {
            $positionalArgs[] = $arg;
        }
    }
    
    // First positional arg is command name, remove it
    array_shift($positionalArgs);
    
    // Now get your parameters
    $firstParam = $positionalArgs[0] ?? null;
    $secondParam = $positionalArgs[1] ?? null;
    
    // Check for flags in $args or $data
    $verbose = isset($args['v']) || isset($args['verbose']) || isset($data['v']) || isset($data['verbose']);
    $all = isset($args['all']) || isset($args['a']) || isset($data['all']) || isset($data['a']);
    
    // Option with value: --site=123
    $siteId = $args['site'] ?? $args['s'] ?? $data['site'] ?? $data['s'] ?? null;
}
```

### Alternative: Using array_shift

```php
public function execute(array $args = [], array $data = [])
{
    // Filter out flags and options
    foreach ($args as $key => $arg) {
        if (!is_int($key) || str_starts_with((string)$arg, '-')) {
            unset($args[$key]);
        }
    }
    
    // Remove command name (first element)
    array_shift($args);
    
    // Remaining args are your positional parameters
    $task_names = $args;
}
```

## Output Methods

Use `$this->printer` for all output:

```php
$this->printer->success('Operation successful!');  // Green
$this->printer->error('Operation failed!');        // Red
$this->printer->warning('Please note!');           // Yellow
$this->printer->note('Information message');       // Blue
$this->printer->print('Plain text');               // Default
$this->printer->printList(['Item 1', 'Item 2']);   // List format
```

## Help Formatting

```php
public function help(): array|string
{
    return CommandHelper::formatHelp(
        'blog:import:sample',
        'Import blog sample data',
        [
            '-s, --site <id>' => 'Specify website ID',
            '-h, --help' => 'Show help',
        ],
        [],
        [
            'Basic' => 'php bin/w blog:import:sample',
            'With site' => 'php bin/w blog:import:sample -s 1',
        ]
    );
}
```

## Checklist

When creating a command, ensure:

- [ ] Extends `CommandAbstract` or implements `CommandInterface`
- [ ] File location: `{Module}/Console/{CommandGroup}/.../Command.php`
- [ ] Namespace matches file path exactly
- [ ] Implements `execute()`, `tip()`, `help()` methods
- [ ] **`help()` return type is `array|string` (NOT just `string`)**
- [ ] **`execute()` has NO return type declaration**
- [ ] **Parameter parsing removes command name from positional args**
- [ ] **CRITICAL: Run `php bin/w command:upgrade` after creation**
- [ ] Test with `php bin/w your:command --help`

## Troubleshooting

### Command Not Recognized

**Problem:** "没有找到匹配的命�? (No matching command found)

**Solution:**
1. **Run `php bin/w command:upgrade`** - This is the most common fix
2. Verify file path and namespace match exactly
3. Check class extends `CommandAbstract`
4. Ensure no PHP syntax errors

### "Abstract method" Error

**Problem:** `Class ... contains 1 abstract method and must therefore be declared abstract or implement the remaining methods`

**Solution:**
1. Check that `help()` method has return type `array|string` (not just `string`)
2. Ensure all interface methods are implemented: `execute()`, `tip()`, `help()`

### Parameters Not Working

**Problem:** Command receives wrong parameters

**Solution:**
1. Remember that `$args[0]` is the command name, not your first parameter
2. Use `array_shift()` or filter positional args to get actual parameters
3. Check for flags in both `$args` and `$data` arrays

### Verify Registration

```bash
php bin/w command:upgrade | grep "your:command"
php bin/w your
```

## Reference Files

- CommandInterface: `app/code/Weline/Framework/Console/CommandInterface.php`
- CommandAbstract: `app/code/Weline/Framework/Console/CommandAbstract.php`
- CommandHelper: `app/code/Weline/Framework/Console/CommandHelper.php`
- Example: `app/code/WeShop/Product/Console/Product/Import/ImportDefault.php`
- Example: `app/code/Weline/Cron/Console/Cron/Task/Run.php`


