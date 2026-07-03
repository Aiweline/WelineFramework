# Weline_Theme Extends

## TargetType

Theme target types are contributed through:

```text
extends/module/Weline_Theme/TargetType/{Provider}.php
```

The provider must implement:

```php
Weline\Theme\Api\TargetTypeProviderInterface
```

Use this extension point when a module needs Theme layouts, virtual layouts, preview, render, and Theme/Meta identify data to bind to a concrete business target.

Example CMS provider path:

```text
app/code/Weline/Cms/extends/module/Weline_Theme/TargetType/CmsPageTargetTypeProvider.php
```

Runtime discovery must read the generated extends registry through `ExtendsData`; do not scan module directories during render/editor requests. After adding or changing providers, run the normal `setup:upgrade` or `extends:rebuild` flow and reload long-running WLS workers.

Theme uses target providers for validation and identity resolution. Cross-module reads for admin display can still use `w_query`, but target type registration belongs to this Theme extension point.

If the target preview needs business data injected into Theme editor live preview, the same provider can also implement:

```php
Weline\Theme\Api\TargetPreviewPayloadProviderInterface
```

The returned payload may include `meta`; Theme live preview merges that meta after layout meta. This keeps target-specific preview data with the registered target provider instead of hard-coding target types in Theme preview controllers.
