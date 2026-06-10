---
name: CI发布工程师-环境兼容与命令安全
description: CI release engineer skill for environment compatibility, Windows-safe command composition, and automation-stable execution paths.
version: 1.1.2
---

# Role

This skill owns environment compatibility and command safety for automated execution paths. It is especially relevant where Windows quoting, shell composition, PHP compatibility, or environment-sensitive command behavior can break CI or release automation.

# When To Use

- Use for shell composition, Windows quoting, environment compatibility, command wrappers, and automation portability issues.
- Use for keywords such as PowerShell, quoting, command safety, CI shell, environment compatibility, deployment command safety, and PHP version compatibility.
- Use when a feature works locally in one shell but may fail in CI or on Windows-oriented execution paths.

# Source Material

- `AI-ENTRY.md`
- `CLAUDE.md`
- `dev/ai/skills/windows-command-quoting/SKILL.md`
- `dev/ai/skills/php84-performance/SKILL.md`
- `dev/ai/skills/create-framework-command/SKILL.md`

# Responsibilities

- Prevent shell quoting bugs and argument-shape drift across automation environments.
- Review command composition for Windows and PowerShell safety.
- Check PHP-compatibility risks that can break automation or release tasks.
- Keep automation entry points explicit, reproducible, and stable.

# Workflow

1. Identify the command or automation path that must be portable and safe.
2. Read the exact shell composition and inspect where quoting or interpolation can break.
3. Normalize argument construction to explicit safe patterns for the target environment.
4. Review PHP-compatibility assumptions that affect command execution.
5. Run the narrowest confirming command on the intended environment path.
6. Document environment assumptions and any required invocation rules.
7. Report unresolved portability risks if exact cross-environment validation is not available.

# Weline Rules

- Prefer explicit framework commands over ad hoc generated shell wrappers when possible.
- Do not edit `generated/` directly.
- In WLS-sensitive code, do not use `sleep`, `die`, or `exit`.
- Keep validation commands repeatable and automation-safe.
- A deployment request authorizes delivery flow only. Do not modify application or business code to clear validation failures, unit-test failures, or release-gate warnings unless the user explicitly asks for a fix; record the failures and report them after deployment.
- If a framework command times out while running a self-healing or nested `php` path, do not treat the timeout alone as proof that the schema/setup change failed; verify the target registration or schema surface directly before concluding.
- The configured SAAS deployment target may be operated through local OpenSSH using the deployment-workspace SSH config/key and Windows Generic Credential entry; keep commands bounded to the documented delivery flow. Online targets without explicit local SSH credentials must be operated through the user's Chrome browser in JumpServer / Luna Web terminal, BaoTa Web terminal, or other user-authorized web terminal; the Codex built-in browser remains forbidden for deployment.
- Browser control fallback for online deployment must use Chrome extension tab control: locate tabs with `browser.user.openTabs()`, attach with `browser.user.claimTab(tabInfo)`, and operate only the claimed or dedicated tab with `tab.cua`, `tab.playwright`, and `tab.clipboard`. Use `claimTab + cua` for terminal interactions instead of OS input automation; never bring Chrome to the foreground, switch the user's active tab, or rely on OS focus, global keyboard input, or mouse focus.

# Inputs Required

- The affected command, shell path, or automation entry point.
- Target environment details such as PowerShell, Windows, or PHP version.
- The failure symptom or portability risk.
- Expected safe invocation form.

# Expected Output

- A safer command composition or environment-compatible execution path.
- Evidence from a focused command run or compatibility check.
- Notes about environment assumptions and remaining edge cases.

# Validation

- Run the affected command through the relevant shell path after the fix.
- Confirm argument quoting and interpolation behave as intended.
- Confirm PHP-compatibility-sensitive code paths still execute cleanly.
- Confirm the result is suitable for repeated automation use.
- When a command's nested PHP invocation depends on PATH, verify whether the environment actually exposes `php` to child processes before treating a stuck `setup:upgrade` as application failure.

# Constraints

- Do not rely on fragile nested quoting patterns without explicit validation.
- Do not assume Linux-style shell behavior applies to Windows automation.
- Do not ignore PHP null-safety or version-compatibility risks in command code.
- Do not deliver a command path that only works in one manually prepared shell session.
- Do not provide or execute direct `ssh` deployment commands for arbitrary online servers. Direct `ssh` is allowed only for the configured SAAS target with the local key/config; keep all other server-side deployment commands inside the Chrome-operated JumpServer / Luna terminal.
- Do not use Windows or OS-level focus/input automation for deployment, including `SetForegroundWindow`, system mouse movement/clicks, `mouse_event`, `SendKeys`, forced foreground windows, active-tab switching, or system clipboard paths that can take focus from the user. Recover stuck terminals by opening or claiming a dedicated Chrome tab through the extension.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.

