# WLS Runtime Basics

Primary repo sources:

- `dev/ai/skills/runtime-and-process/SKILL.md`
- `dev/ai/rules/wls-state-management.mdc`
- `app/code/Weline/Server`

## Key rules

- End processes via framework process management, not ad-hoc kill-port behavior
- Distinguish reload vs restart clearly
- Code changes usually mean `php bin/w server:reload`
- Maintenance/reload/restart changes require careful Dispatcher + Worker pool reasoning
- New request-scoped static state must be evaluated for StateManager reset under WLS

## Useful commands

```bash
php bin/w server:start
php bin/w server:reload
php bin/w server:restart -r
php bin/w server:stop
```

## Review hotspots

- `app/code/Weline/Server/bin/worker.php`
- `app/code/Weline/Server/bin/worker_ssl.php`
- `app/code/Weline/Server/Service/ServiceOrchestrator.php`
- `app/code/Weline/Server/Dispatcher`
