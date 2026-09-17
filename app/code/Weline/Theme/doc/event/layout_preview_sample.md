# Layout preview sample

## Event Name

`Weline_Theme::layout_preview_sample`

## Trigger

`LayoutResolveService::resolvePreviewSample()` when the visual editor selects a layout or asks for a storefront sample route (canvas / start-preview).

## Payload

`DataObject` under `data`:

- `layout_path` / `layout_option` / `preferred_slug`
- `claimed` (bool)
- `preview_entity_route` / `entity_slug` / `sample_source`

## Resolution order

1. Business observers (Promotion / Product / Blog / …) may claim a slug sample (`promotion/deals`, `product/{slug}`, …).
2. Theme last-resort (`LayoutPreviewSampleObserver` + in-service fallback):  
   `layout` → `ThemeResourceCatalog.module_name` → `Env::getModuleInfo()['router']` → join  
   - Theme-owned shells: path equals layout (`products`, `cart`, homepage `""`)  
   - Business layouts: e.g. `account/login` + Customer `router=customer` → `customer/account/login`
3. Editor loads that path and only appends editor params (`editor_mode`, …). Do not invent path aliases in Theme tables.

Do not use this event to set `theme_public_route` on `theme-preview/content` canvas URLs.
