# Layout path resolve

## Event Name

`Weline_Theme::layout_resolve`

## Trigger

`LayoutResolveService::resolveFromRequest()` during theme layout wrap when the controller did not set `layoutType`.

## Payload

`DataObject` under `data`:

- `request_path` (string)
- `claimed` (bool)
- `layout_path` / `layout_option` / `entity_slug` / `entity_kind` / `entity_id`

Modules claim by setting `claimed=true` and filling path fields.
