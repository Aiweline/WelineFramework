# Theme Editor Development Notes

## Preview Request Optimization (T008-T015)

### Overview
This optimization eliminates duplicate preview requests in the theme editor by:
1. Server-side rendering all widget library previews on page load
2. Returning `preview_html` in save/update API responses
3. Removing real-time preview API calls during config editing
4. Lazily fetching `widget-preview` only when a library card has empty/placeholder/error preview HTML

### Changes Made

#### Backend (ThemeEditor.php)

1. **`attachWidgetPreviewHtml()`** - Pre-compiles preview HTML for all widget library items
2. **`buildWidgetPreviewHtml()`** - Renders widget preview using default params
3. **`buildPreviewHtmlForLayoutId()`** - NEW: Builds preview HTML for saved widgets
4. **`postUpdateConfig()`** - NOW returns `preview_html` in response
5. **`postSaveWidget()`** - NOW returns `preview_html` in response
6. **`sanitizeWidgetPreviewHtml()`** - Removes scripts/inline events from preview HTML

#### Frontend (theme-editor.js)

1. **Removed real-time preview**: `updateModalPreview()` no longer makes API calls
2. **Save-only refresh**: Preview updates only occur after successful config save
3. **Use returned preview_html**: `saveConfigFromModal()` uses API response to update preview
4. **Failure handling**: Failed saves keep the previous preview (T015)
5. **Fallback library preview**: Empty/placeholder/error widget cards call `widget-preview` once, then use server defaults if live content is unavailable

#### Template (index.phtml)

1. Uses `<?= $widget['preview_html'] ?? '' ?>` for widget library previews
2. Added `.preview-static-hint` CSS for config modal static preview state

### Verification Steps

```bash
# Run contract tests
php bin/w phpunit:run -b --path=app/code/Weline/Theme/Test/Contract

# Run integration tests  
php bin/w phpunit:run -b --path=app/code/Weline/Theme/Test/Integration

# HTTP checks
php bin/w http:request -u "theme/backend/theme-editor/index?theme_id=5" -b --login
php bin/w http:request -u "theme/backend/theme-editor/update-config" -b --login -m POST -d "layout_id=1&config[title]=demo"
```

### Browser Test

Open browser dev tools Network tab and verify:
- No duplicate `widget-preview` requests for cards that already have valid `preview_html`
- Empty/placeholder/error cards may request `widget-preview` once to populate a default/live preview
- `update-config` response includes `preview_html`
- `save-widget` response includes `preview_html`

### API Response Format

```json
// POST /theme/backend/theme-editor/update-config
{
  "success": true,
  "message": "配置已保存",
  "preview_html": "<div class=\"widget-content\">...</div>"
}

// POST /theme/backend/theme-editor/save-widget  
{
  "success": true,
  "message": "保存成功",
  "data": { "layout_id": 123 },
  "preview_html": "<div class=\"widget-content\">...</div>"
}
```

### Related Tasks
- T008-T010: Backend implementation
- T011-T013: Frontend implementation
- T014-T015: Integration and polish
