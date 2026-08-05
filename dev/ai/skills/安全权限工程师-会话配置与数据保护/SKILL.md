---
name: 安全权限工程师-会话配置与数据保护
description: Review or implement Weline session configuration, auth-area isolation, sensitive-state handling, and data-protection boundaries. Use for session keys, AreaConfig, state leakage, or admin/frontend identity separation; use WLS Session/SSE for runtime server behavior and ACL skills for permission trees.
---

# Session security and data protection

## Boundary

- Frontend, backend, and other auth areas keep separate identities and session behavior.
- Sensitive state moves through framework session/config/context abstractions, not raw globals, logs, URLs, or ad hoc storage.
- This skill owns security semantics; WLS runtime mechanics and ACL trees remain with their specialist skills.

## Workflow

1. Identify the area, identity transition, sensitive fields, retention/migration behavior, and consumers.
2. Trace current AreaConfig, session factory/class, context, and config storage.
3. Fix the narrowest isolation or protection boundary without weakening another area.
4. Validate login/logout, allowed/denied paths, request boundaries, and relevant WLS persistence behavior.
5. Report consumer impact, migration needs, and residual exposure.

## Validation

- Prove state does not cross area, user, or request boundaries.
- Confirm sensitive values are not exposed through repository guidance, logs, visible responses, or raw session manipulation.
- Document a public auth/session behavior change in its owning security/API documentation.

