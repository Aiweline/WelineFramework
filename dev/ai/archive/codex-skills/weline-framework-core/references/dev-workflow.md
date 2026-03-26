# WelineFramework Development Workflow

Derived from `dev/ai/AI-开发与测试指南.md`.

## Before coding

- Inspect existing module patterns in `app/code`
- Verify framework APIs from source instead of assuming Laravel/Symfony/Magento-style helpers exist
- Choose the closest repo skill from `dev/ai/skills` if the task is specialized

## Common commands

```bash
php bin/w setup:upgrade
php bin/w setup:upgrade --route
php bin/w http:request /
php bin/w http:request admin -b
php bin/w http:request rest/v1/module/action -api
php bin/w http:request admin -b --filter=Warning
php bin/w http:request admin -b --filter=Fatal
```

## Validation expectations

- Validate core flow, UI availability, data consistency, and error handling
- Frontend-related changes should get browser-level validation when practical
- New controller work should be checked via `http:request` after route upgrade

## Common pitfalls

- Missing or malformed `register.php`
- Wrong event file path or observer namespace
- Controller/model class name collisions
- Taglib attributes containing raw PHP output
- Null-unsafe calls in PHP 8.2+
