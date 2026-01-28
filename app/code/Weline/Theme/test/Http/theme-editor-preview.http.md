## Theme Editor Preview HTTP Checks

### 1) Theme editor page renders with previews
```
php bin/w http:request -u "theme/backend/theme-editor/index?theme_id=5" -b --login
```
Expected:
- HTML contains widget preview markup (e.g., `widget-preview-canvas`).

### 2) Update widget config returns preview_html
```
php bin/w http:request -u "theme/backend/theme-editor/update-config" -b --login -m POST -d "layout_id=1&config[title]=demo"
```
Expected:
- JSON includes `success=true` and `preview_html`.
