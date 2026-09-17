# Weline_FileManager

FileManager owns reusable file-picker contracts and the durable `FileAsset`
identity/metadata layer. Physical objects remain owned by `Weline_Storage` disks.

## Public boundaries

Cross-module code may depend only on `Weline\FileManager\Api\*`:

- `FileManagerInterface`, `FileManager`, `Api\Block\FileManager`: picker extension
  and rendering contracts. **Selection** in templates: `<w:file-manager />`
  (path mode) or MediaManager `WelineMedia` with `value_mode=file-image` for typed
  layout/widget nodes — see [`doc/file-manager-选图与file-image出图.md`](file-manager-选图与file-image出图.md).
- `FileAssetManagerInterface`: page rendering and image-usage validation. This
  established interface still exposes legacy model return types for compatibility.
  **Display** storefront/admin images with `<w:file:image>` and UI `width`/`height`
  or `aspect_ratio` (see `doc/file-image-cls-尺寸与响应式.md`). Do not use
  `file:image` as a media picker.
- `FileAssetLibraryInterface`: the data-only management boundary for asset lookup,
  upload, localized metadata, revision-guarded asset provenance metadata,
  authorized reference counts, URL resolution, move and delete. New management
  consumers should use this interface instead of models or `Service\*` classes.
- `FileAccessPolicyInterface` and `Api\Data\FileAccessContext`: scoped access checks.
- `LayoutContentValidatorInterface`: validates saved layout references.

`etc/module.php` registers injectable interfaces to their owning implementations.
FileManager must not depend on MediaManager; the dependency direction is
`MediaManager -> FileManager -> Storage`.

## Batched render URLs

`Api\FileAssetBatchUrlResolverInterface::resolveUrls()` is an optional capability
on the existing asset manager instance; it is not a separate injection binding.
`FileAssetManagerInterface` and third-party implementations remain compatible.
Each keyed input supplies an asset ID, an ordered list of `FileAccessContext`
values, and optional `StorageUrlOptions`; the result preserves the key and is a
`ResolvedStorageUrl` or null when no context succeeds.

The manager reads raw asset and exact-locale rows in chunks of 200 IDs/codes.
These maps live only for the current call. Each input still receives independent
models, the standard fetch hooks, its own access-policy checks and Storage URL
resolution. Batch-read failure falls back to the existing scalar reads;
non-exact case matches remain subject to the database's original scalar lookup.
Private assets retain shared-response-cache prohibition, the existing temporary
URL TTL, and kind/cacheability/expiry checks. Final URLs are not cached here.

`Framework\Database\Query\QueryDelegator::hydrateFetchResult()` applies the
same model lifecycle used by normal fetch after the caller records queryData.
It performs no SQL or delete events; FileManager does not maintain a private
copy of the ORM hydration rules.

Development coverage: `test/Unit/Service/FileAssetBatchUrlResolverTest.php`
executes real SQLite queries across the 200-item boundary and verifies independent
models, hooks, scoped policy, private URLs, and query-result forms. Actual
storefront response and performance acceptance remain separate from these tests.

## FileAsset record

`FileAsset` stores the canonical disk code and object key, original filename, detected
MIME, byte count, SHA-256, optional image dimensions, visibility, lifecycle state and
revision. `FileAssetLocale` stores exact-locale display name, default alt, description,
caption, translation state and translation origin.

An asset is selectable for publishing only when it is ready and the requested exact
locale has reviewed display name, default alt and description. Page use still records
its own contextual `ImageUsage` alt snapshot.

## Mutation guarantees

- Upload writes the Storage object with `overwrite=false`, then persists FileAsset and
  locale metadata. Persistence failure removes the just-written object.
- `replaceContent` overwrites an existing live object (`overwrite=true`), updates technical
  FileAsset fields, and keeps the same `asset_id` plus all locale rows.
- File and directory moves update canonical object keys. Metadata persistence failure
  attempts to roll the physical move back.
- Delete refuses referenced assets. FileAsset is disabled before physical deletion so
  a partial provider failure fails closed for later rendering.
- Cross-module callers receive safe arrays from `FileAssetLibraryInterface`; model,
  repository and provider credentials remain internal.

## Media reference identity

Occupancy identity follows **MediaReferenceIdentity.v1**. Build only via `w_scope` /
`window.w_scope` — never hand-paste paths. Authoritative docs:

- Protocol: [`doc/media-reference-identity-protocol.md`](media-reference-identity-protocol.md)
- Skill: [`doc/ai/skills/media-reference-identity/SKILL.md`](ai/skills/media-reference-identity/SKILL.md)
- Spec: [`doc/开发/spec/media-reference-identity.md`](开发/spec/media-reference-identity.md)

Swap/clear image = unbind references only; physical delete uses soft-delete (`deleted_at`)
and requires zero references.

## Verification

Unit coverage lives under `test/Unit`; browser selection and integration coverage is
owned by the embedding module (currently MediaManager). Real WebUI acceptance must run
against a dedicated WLS instance before the change is marked complete.
