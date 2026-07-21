---
name: 业务模块工程师-配置缓存与后台权限
description: Business module engineer skill for env config, cache usage, backend menu wiring, and module-level permission integration.
version: 1.1.1
---

# Role

This skill owns module-level configuration, cache usage, backend menu integration, and module permission wiring. It applies framework conventions without stepping into broader security-architecture ownership.

# When To Use

- Use for module env files, system configuration, cache wrappers, backend menus, and module permission wiring.
- Use for keywords such as env config, SystemConfig, cache, menu, backend permission, and module settings.
- Use when a business module needs operational configuration or caching behavior.

# Source Material

- `AI-ENTRY.md`
- `CLAUDE.md`
- `dev/ai/skills/config-and-env/SKILL.md`
- `dev/ai/skills/cache-usage/SKILL.md`
- `dev/ai/skills/acl-permission-system/SKILL.md`
- `dev/ai/skills/module-development/SKILL.md`

# Responsibilities

- Define module configuration in the expected env locations.
- Implement cache wrappers through framework factories instead of ad hoc storage code.
- Wire module backend menus and permissions consistently.
- Keep configuration, cache, and backend visibility changes scoped to the owning module.

# Workflow

1. Confirm whether the change affects env structure, runtime config, cache, or backend menu visibility.
2. Read the existing module configuration and permission layout before editing.
3. Implement configuration or cache code using framework-standard entry points.
4. Update backend menu and controller permission wiring together when the admin surface changes.
5. Run env, setup, or backend validation commands as needed.
6. Verify behavior from the real backend or configuration path; for local backend login use `admin/admin` unless the user supplied other credentials or the task is specifically about auth boundaries.
7. Record any required README or admin-usage notes.

# Weline Rules

- Do not edit `generated/` directly.
- Do not hardcode user-facing text.
- Use i18n for user-facing text.
- Use framework cache factories instead of direct driver construction.
- Update module README after fixing bugs or changing admin behavior.

# 缓存键契约

`w_cache($pool)` 默认自动注入当前请求的 **website_code + lang + currency**（并固定带 area）。业务只写逻辑键。

特殊（全局词典、模块元数据、bootstrap 结构缓存）使用 `*Custom`，维度 bool **默认 false = 逃逸**：

```php
// 常规
w_cache('product')->remember('price:'.$id, 1800, $builder);

// 全逃逸
w_cache('phrase')->getCustom($key);
w_cache('phrase')->setCustom($key, $words);

// 只启用语言维
w_cache('view')->getCustom($key, lang: true);
```

提供方法：`getCustom` / `setCustom` / `rememberCustom` / `deleteCustom` / `hasCustom`。  
完整说明见 `app/code/Weline/Framework/doc/3-开发/缓存使用指南.md`。

禁止：手拼站点/语言/货币进逻辑键；用全 true 的 Custom 代替普通 API；直接 new 缓存驱动。

# Inputs Required

- The owning module and configuration or admin surface being changed.
- Desired cache behavior, env structure, or backend visibility outcome.
- Related controllers, menus, or config keys.
- Validation path for backend or runtime behavior.

# Expected Output

- Updated module configuration, cache, or backend permission wiring.
- Validation evidence for the changed admin or runtime path.
- Documentation note when admin usage or config behavior changed.

# Validation

- Run `php bin/w env:check` or `env:install -y` when extension or env checks are relevant.
- Run setup or route refresh commands when backend wiring changed.
- Verify backend menu visibility and controller access behavior.
- Verify cache read, write, and invalidation behavior through the intended module flow.
- Do not stop local backend verification on the login page; continue with `admin/admin` unless the scenario specifically tests login or permission denial behavior.

# Constraints

- Do not turn module configuration work into global security policy redesign.
- Do not instantiate cache drivers directly.
- Do not leave backend menu visibility disconnected from permission annotations.
- Do not hide required operational setup steps from the module README.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.
