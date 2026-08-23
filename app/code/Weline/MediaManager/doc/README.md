# Weline_MediaManager

MediaManager provides a provider-aware backend media library. Local media is one
canonical Storage disk; uploads and mutations stay synchronized with FileAsset.

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
- Storage is required and owns every local/remote directory and object operation.
  Ai and Theme remain optional.
- Optional calls are resolved only through `Ai\Api\Image\ImageRuntimeInterface`,
  `Ai\Api\Image\TextToImageScenarioBindingInterface`,
  `Storage\Api\StorageCatalogInterface`,
  `Storage\Api\StorageDirectoryManagerInterface`, and
  `FileManager\Api\FileAssetLibraryInterface`, and
  `Theme\Api\Asset\StaticAssetPublisherInterface`.
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
- Backend connector hashes and `path` parameters resolve only to normalized object
  keys on the selected canonical Storage disk.
- `mkdir`, `rename`, and upload target names must be single basename values without path separators or control characters.
- Directory and object writes remain behind Storage provider contracts; the connector
  contains no direct local-filesystem mutation fallback.
- Browser business uploads use `Weline.Api.resource('media_manager').connector`;
  neither raw XHR nor process-global request mutation is part of the upload path.
- Uploads are capped at 14 MiB per request (within the default 16 MiB WLS body budget), preflight target-name collisions,
  validate the configured extension against detected MIME, and never overwrite.
- Wildcard upload permits a safe passive-content list; active browser content is
  accepted only when an embedding picker explicitly lists its extension.
- Remote storage names and paths are validated before the provider is resolved; root mutation, traversal, control characters, and rename-overwrite are rejected.
- Provider operations never fall back to another disk. Capability gaps are reflected as disabled UI actions.

## Storage Directory Management

- The folder tree and media grid expose the same provider-aware directory actions by pointer context menu, keyboard context menu, and a touch-friendly more button.
- `StorageCatalogInterface` remains read-only discovery. Directory commands use
  `StorageDirectoryManagerInterface`; FileAsset-aware object mutations use only
  `FileAssetLibraryInterface`. Adapter objects, ORM models and credentials never
  cross into MediaManager.
- The management scope is browse, create directory, rename/move file, rename
  directory, delete file/directory, FileAsset upload, metadata display/edit,
  download and preview where the provider advertises the matching capability.
- External operating-system `Files` dropped on the content area and clipboard File
  items pasted outside editable controls use the existing connector upload contract
  and current-directory target. Every file receives its own localized display name,
  default alt and description. Pure text paste is never intercepted.
- Existing regular files use a dedicated internal DataTransfer MIME and connector
  `move` command when dropped on a grid or tree folder. Directories, symbolic links,
  same-folder moves and target-name overwrites are rejected. Non-local moves always
  stay behind `StorageDirectoryManagerInterface` capability checks and never fall
  back to the local media root.
- Context menus use the native `Weline.UI` Menu shell and `w-menu` semantic skin. A virtual anchor preserves the actual pointer/keyboard invocation point, while the shared floating runtime handles portal ownership, visual-viewport/safe-area flipping and clamping, viewport-change repositioning, keyboard navigation, Escape/Tab, outside dismissal, focus restoration, reload reset, and automatic light/dark/system tokens. MediaManager owns actions only; it must not duplicate menu colors or panel-positioning code.
- Selecting a file renders its safe FileAsset and locale metadata in the side panel.
  The ordinary-file context menu exposes “查看详情”, which opens the same complete
  record in an accessible responsive dialog; hashes and private provider credentials
  are never displayed.

The connector must preserve normal nested media folders while refusing traversal attempts such as `../../app/etc/env.php`.

## Query Providers

- `media_manager`（`MediaManagerQueryProvider`）：后台会话可读 AI 作图配置、保存当前用户拥有的生成结果；浏览器侧走 bin-query / `Weline.Api.*`，生成本身仍由 `runtime_task` 启动。普通 `config/connector/save` 优先取 QueryBin 已证明的 backend Worker 用户 ID，无 Worker 上下文时才回退 backend Session；不得借用 `ResumableTaskOwnerResolver`（那是 runtime_task 的 owner/lease 校验）。身份一律称 backend，不称 admin。
- `media_manager_asset`（`MediaManagerAssetQueryProvider`）：可信模块只读边界，按媒体哈希读取图片字节；`frontend=false`，不暴露给浏览器 Query。
