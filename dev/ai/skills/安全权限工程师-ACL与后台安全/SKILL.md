---
name: 安全权限工程师-ACL与后台安全
description: Design or diagnose Weline backend ACL structure, menu visibility, permission annotations, source IDs, and administrative access. Use when an admin surface needs protection or an ACL path misbehaves; use module configuration skills for ordinary menu wiring and session security for state isolation.
---

# ACL and backend security

## Contract

- Menu visibility is not authorization; every protected Controller/action needs the owning ACL declaration.
- `menu.xml`, source IDs, parent relationships, and permission annotations must describe the same tree.
- Distinguish menu-visible permissions from control-only/action permissions.

## ACL tags and menu visibility

Weline authorization resources use one source grammar:

`Vendor_Module::tag1:tag2:code`

- The final segment is the leaf resource code; preceding segments are ordered
  tags. Use `Weline\Framework\Authorization\Resource\SourceIdParser`; do not
  parse or compose tagged source IDs by hand.
- Backend menu resources come from the owning module's
  `etc/backend/menu.xml`. Controller/action resources come from
  `#[Weline\Framework\Acl\Acl]`. Do not invent an `acl.xml`.
- A role may receive an exact leaf source or a tag-path grant. Tag-path grants
  expand to matching concrete resources during ACL synchronization; they do not
  authorize unknown or disabled resources.
- `AclTag` metadata controls tag name, description, color and sort order only.
  Metadata never grants access.
- Parent menus provide topology and ancestor visibility. A parent grant is not
  a substitute for the leaf Controller/action permission.
- Use the same module, ordered tags and leaf code semantics for a menu and its
  destination Controller. Non-menu actions may use a more specific leaf under
  the same tag path.
- No permission means both: the menu is absent and direct route access is
  denied. Verify both outcomes; proving only one is a failure.
- QueryProvider, background-task and operation resources also require exact,
  enabled source IDs. Never infer permission from a route prefix or visible
  parent menu.

## Workflow

1. Identify the backend route, owning module, intended roles, menu source, and action scope.
2. Inspect `menu.xml` and Controller permission annotations together.
3. Align source identifiers and permission type without broadening access.
4. Validate a permitted and denied user through the real backend route, using only supplied/local credentials.
5. Report visibility, authorization, denial behavior, and any auth blocker.

## Validation

- A hidden menu with an unprotected Controller fails.
- A visible menu with an inconsistent/unknown source fails.
- A tag shown in the role editor without matching concrete resources fails.
- A source placed in an invented `acl.xml` instead of the framework collection
  surfaces fails.
- Denied access must fail safely and permitted access must reach the intended action.
- Update owning admin documentation only when the permission model or operator workflow changed.
