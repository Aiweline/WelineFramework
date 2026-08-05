# Codex skill adapters

This directory exposes only repository-specific skills that are not already provided by `weline-codex-plugin`, plus exact-passphrase release aliases.

- Active `SKILL.md` files must have a precise, non-empty trigger description.
- Canonical Weline bodies live under `dev/ai/skills`; adapters stay short.
- `SKILL.md.disabled` files are recoverable migration archives and are not discovered by Codex.
- Do not re-enable a disabled alias when a current canonical/plugin skill owns the same task.

Validate with `php dev/ai/scripts/check-ai-guidance.php`.
