# Weline_AppStore

Weline_AppStore provides the local application store integration for account binding, module download history, license views, installed-module records, and setup-time AppStore metadata initialization.

## Setup Logging

Setup and observer error handling must keep the `Weline\Framework\App\Env::log_error()` contract:

```php
Env::log_error(string $filename, string $message): bool
```

Use an `appstore/*.log` filename string as the first argument. The second argument must be a string message; serialize any context into the message or use the global logger API when structured context is required.

## Validation

- WEL-86 adds a focused PHPUnit regression for AppStore `Env::log_error()` calls so setup logging cannot pass an array as the message again.
- After setup logging changes, validate with the targeted AppStore logging test and a non-production `setup:upgrade` command.
