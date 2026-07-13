# Weline_MediaManager

MediaManager provides the backend media connector and development-time static/media routing for files under `pub/media`.

Its file-manager implementation and block extend `Weline\FileManager\Api\FileManager`
and `Weline\FileManager\Api\Block\FileManager`. Cross-module code must not use the
legacy FileManager internal namespaces.

The optional AI draw scenario implements only `Weline\Ai\Api\*` contracts and
uses the public `AiModel` compatibility type; provider and model internals remain
owned by Weline_Ai.

The backend iframe reads `theme-mode-switch` through
`Weline\Backend\Api\View\BackendThemeConfigInterface`; only `dark` and `light`
are accepted, and missing/invalid configuration falls back to `light`. MediaManager
must not initialize or inspect Backend theme blocks directly.

## Dependency Inventory

- Backend and FileManager are required. FileManager owns the public extension bases;
  the dependency direction is `MediaManager -> FileManager`.
- Ai, Storage and Theme are optional. Storage only augments the connector's storage-source
  listing; Theme only publishes development override assets; their absence preserves the local media source.
- Optional calls are resolved only through `Ai\Api\Image\ImageRuntimeInterface`,
  `Ai\Api\Image\TextToImageScenarioBindingInterface`,
  `Storage\Api\StorageCatalogInterface`, and `Theme\Api\Asset\StaticAssetPublisherInterface`.
- FileManager must not declare a reverse dependency on MediaManager.

## AI Draw Binding Contract

`AiDrawModelBinder` is a thin optional-provider adapter. It submits only a data-only
`TextToImageScenarioBindingRequest` containing `media_manager_ai_draw`, the reference
scenario order and the scanner placeholder code. The Ai-owned command preserves the
selection order (reference scenario, image default, marked default, then active models),
provider-account repair and idempotent model-binding update. Ai ORM models,
`ConfigResolver`, `DefaultModelManager` and scenario persistence never cross into
MediaManager.

When Weline_Ai is absent, resolution returns no model and binding returns
`no_active_text2image_model`; the media connector remains usable. MediaManager setup
and migration call only the local binder. The migration declares no affected table,
because MediaManager does not own or directly operate the Ai scenario schema.

## Security Contract

- Static request paths are URL-decoded before filesystem resolution.
- Static routing rejects decoded paths containing `..`, backslashes, NUL, or other control characters.
- Static files are served only after `realpath()` proves the requested file is inside the allowed root directory.
- Backend connector hashes and `path` parameters resolve to paths under the media root only.
- `mkdir`, `rename`, and upload target names must be single basename values without path separators or control characters.
- Write targets are checked against the real media root before creating directories, renaming, or moving uploaded files.

The connector must preserve normal nested media folders while refusing traversal attempts such as `../../app/etc/env.php`.
