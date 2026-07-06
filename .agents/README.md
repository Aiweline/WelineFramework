# Codex Agents Metadata

This directory follows Codex's repo-scoped metadata conventions.

## Contents

- `plugins/marketplace.json`: repository marketplace that exposes the WelineFramework Codex plugin.

## Boundaries

- Do not put durable project rules here. Keep repository guidance in `AGENTS.md` and `AI-ENTRY.md`.
- Do not duplicate skill bodies here. Canonical skill instructions live under `dev/ai/skills`.
- Do not store memories here. Codex memories are user-level generated state under `~/.codex/memories`; repo-local context maps live under `dev/ai/codex`.
