# Session Runtime Notes

Primary repo source:

- `dev/ai/skills/session-development/SKILL.md`

## Defaults

- Use framework session abstractions, not raw `$_SESSION`
- Prefer `SessionFactory::backend()` / `SessionFactory::frontend()`
- Keep auth/session isolated by area and business prefix
- When WLS is enabled, check Session Server config alignment across:
  - worker runtime
  - provider startup config
  - health/status tooling

## Hotspots

- `app/code/Weline/Server/Service/Provider/SessionServerProvider.php`
- `app/code/Weline/Server/bin/session_server.php`
- `app/code/Weline/Server/Session`
