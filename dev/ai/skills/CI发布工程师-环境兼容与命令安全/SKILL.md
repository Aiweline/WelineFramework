---
name: CI发布工程师-环境兼容与命令安全
description: Make Weline commands and automation portable across macOS/Linux shells, Windows PowerShell, CI, and supported PHP versions. Use when quoting, argument construction, nested PHP processes, PATH differences, shell wrappers, deployment commands, or environment-specific failures are in scope; do not use it as a fallback transport when the required shell or host is unavailable.
---

# Environment and command safety

## Load

- The exact command implementation and target environment documentation

## Workflow

1. Identify the real target shell, PHP/runtime version, working directory, environment variables, and command boundary.
2. Inspect how arguments are constructed and where quoting, interpolation, pipes, optional syntax, or nested processes can change meaning.
3. Prefer structured argument arrays and existing framework commands over generated shell strings.
4. Keep commands bounded and non-interactive for automation.
5. Run the narrowest representative invocation on the intended environment; if that environment is unavailable, state the unverified portability risk.
6. Distinguish a wrapper/PATH/timeout failure from the target operation's actual state before changing application code.

## Rules

- Do not publish pseudo-syntax such as `command1|command2`, `[optional]`, or mixed shell variants as a copyable command.
- Do not assume POSIX quoting works in PowerShell, or that a parent process's PHP/PATH is available to nested children.
- Avoid fragile inline PHP/JSON quoting when an existing CLI option, here-string, or temporary task-scoped script is safer.
- A deployment request does not authorize application-code changes to hide failing gates.
- Deployment requires an explicitly confirmed target and usable local credentials/transport. If SSH or the documented transport is unavailable, stop and report the blocker.
- Do not fall back to JumpServer/Luna/BaoTa browser terminals, OS focus automation, or arbitrary-server SSH. If the documented transport is unavailable, report a blocker.

## Validation and output

Report:

- tested environment and exact invocation;
- exit code and decisive output;
- quoting/PATH assumptions;
- remaining untested environments or blockers.
