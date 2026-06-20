# Module Marketplace Meta Client

This document records the client-side contract implemented in `DEV-workspace` for WMP-Meta v1 module packages.

## Scope

The current repository implements the terminal/client side only:

- Read `etc/marketplace/meta.json` lazily after a module package is extracted or installed.
- Require and strictly validate `etc/marketplace/meta.json` during AppStore package installation.
- Validate `module_name` and optional package manifest hash before installation is allowed to continue.
- Persist structured marketplace meta, tag snapshot, primary tag, surfaces, and meta hash on the installed-module record.
- Submit source marketplace copy to the I18n dictionary pipeline when translations are incomplete.
- Expose a lightweight `php bin/w module:info Vendor_Module --locale=en_US` diagnostic command.

The PlatformAppStore server-side schema, upload review workflow, `/apps/tags/{slug}` landing pages, sitemap sources, and public marketplace detail pages remain server-side PlatformAppStore responsibilities.

## Package Manifest

`weline-appstore-package.json` may declare the bundled meta file:

```json
{
  "marketplace_meta": {
    "path": "app/code/Vendor/Module/etc/marketplace/meta.json",
    "sha256": "..."
  }
}
```

When `marketplace_meta.path` is declared:

- The path must be inside the extracted package.
- The file must exist.
- If `sha256` is present, the raw file hash must match.
- A mismatch blocks installation.

When the manifest does not declare a path, the client reads `etc/marketplace/meta.json` from the detected module directory.

AppStore installation uses strict mode. Existing local modules, legacy recovery scans, and `module:info` use non-strict lazy reads so older modules can still be inspected.

## WMP-Meta v1 Fields

The client understands:

- `schema_version: 1`
- `module_name`
- `i18n.source_locale`
- `i18n.locales`
- `tags[]`
- `surfaces[]`
- `seo`
- `capabilities`

Unknown fields are preserved in `marketplace_meta_json` but not interpreted.

For WLS Panel plugins, modules may preserve optional entry fields in the same
meta file. AppStore stores them as raw marketplace meta and
`w_query('appstore', 'installedModules')` exposes them for WLS to consume:

```json
{
  "wls_panel_url": "server/backend/wls-file-manager",
  "wls_panel": {
    "backend_route": "server/backend/wls-file-manager"
  },
  "capabilities": {
    "file_manager": {
      "backend_route": "server/backend/wls-file-manager"
    }
  }
}
```

Menu entries can be declared under `wls_panel.menu[]` when a plugin wants to
appear directly in the standalone WLS Panel sidebar:

```json
{
  "wls_panel": {
    "menu": [
      {
        "key": "file-manager",
        "label": {
          "zh_Hans_CN": "文件管理",
          "en_US": "File Manager"
        },
        "description": {
          "zh_Hans_CN": "管理 WLS 项目路径内的文件。",
          "en_US": "Manage files inside WLS project paths."
        },
        "backend_route": "server/backend/wls-file-manager",
        "group": "tools",
        "order": 30
      }
    ]
  }
}
```

The WLS consumer accepts `wls_panel_url`, `panel_url`, `backend_url`,
`capability_url`, `panel_entry`, `backend_entry`, `wls_panel`, and nested
capability entries. These fields remain optional; `module:wls` is still the
only mandatory WLS compatibility tag. If `wls_panel.menu[]` is absent but a
panel URL is declared, WLS may render one fallback plugin panel entry.

## Typed Tag Codes

Tag `code` supports both the legacy dot format and the new typed format:

```text
legacy: surface.backend, capability.seo
typed:  module:wls, custom:wls-file-manager, system:false
```

The typed format is:

```text
type:value
```

Client normalization rules:

- `type` is lower-case.
- `value` is lower-case and keeps hyphenated identifiers such as `wls-file-manager`.
- `module:wls` is the WLS Panel compatibility tag.
- `custom:*` identifies a product-specific plugin family.
- `surface:backend` is treated the same as legacy `surface.backend` for surface filtering.
- Legacy dot tags remain compatible and continue to be accepted.

Minimum strict install meta:

```json
{
  "schema_version": 1,
  "module_name": "Vendor_Module",
  "i18n": {
    "source_locale": "zh_Hans_CN",
    "locales": {
      "zh_Hans_CN": {
        "display_name": "模块名称",
        "description": "模块描述"
      }
    }
  },
  "tags": [
    {
      "code": "surface.backend",
      "primary": true,
      "label": {
        "zh_Hans_CN": "后台应用"
      }
    }
  ]
}
```

## Validation Policy

Blocking errors:

- AppStore package install has no readable `etc/marketplace/meta.json`.
- Declared `marketplace_meta.sha256` does not match the file.
- `module_name` in meta does not match the module being installed.
- Declared meta path is unsafe or missing.
- Strict install meta is missing `schema_version: 1`.
- Strict install meta is missing `i18n.source_locale`.
- Strict install meta is missing `i18n.locales[source_locale].display_name`.
- Strict install meta has no tags.
- Strict install meta tag is missing `code` or source locale label.

Warnings only:

- Invalid optional tag shape.
- Missing primary tag; the first tag is treated as primary.
- Multiple primary tags.

Warnings do not block old packages or manual packages on non-strict lazy reads.

## I18n Policy

Only the source locale copy is required in package meta. Other locales may be incomplete.

After install or platform refresh, the client submits these source words to `Weline_I18n::collect_translations`:

- `i18n.locales[source_locale].display_name`
- `i18n.locales[source_locale].description` when present
- tag source locale labels
- SEO source locale title/description when present

If package meta provides other locale translations, they are submitted with the same source word. Missing locale translations are left to the existing I18n dictionary and AI translation queue.

Tag labels accept the public `label` map and are normalized internally to `labels`. Legacy string tags remain readable in non-strict mode, but strict AppStore package installs require structured tags with source locale labels.

Typed WLS plugin example:

```json
{
  "schema_version": 1,
  "module_name": "Vendor_WlsFileManager",
  "i18n": {
    "source_locale": "zh_Hans_CN",
    "locales": {
      "zh_Hans_CN": {
        "display_name": "WLS File Manager"
      }
    }
  },
  "tags": [
    {
      "code": "module:wls",
      "label": {
        "zh_Hans_CN": "WLS Panel"
      },
      "primary": true
    },
    {
      "code": "custom:wls-file-manager",
      "label": {
        "zh_Hans_CN": "WLS File Manager"
      }
    },
    {
      "code": "system:false",
      "label": {
        "zh_Hans_CN": "Third-party Module"
      }
    }
  ]
}
```

## Installed Record Snapshot

`Weline\AppStore\Model\AppStoreInstalledModule` stores:

- `marketplace_meta_json`
- `marketplace_meta_hash`
- `marketplace_meta_locale`
- `primary_tag_code`
- `surface_codes`

`Weline\AppStore\Service\InstalledModuleMetaService` is the client service for:

- `syncOnInstall()`
- `getTags()`
- `getLocalizedInfo()`
- `refreshFromPlatform()`
- `syncPlatformTags()`

## SEO Notes

Weline SEO already renders canonical, hreflang, `CollectionPage`, and `ItemList`. Tag landing pages should set `page_type` to `tag_collection` or `tag_landing` and provide an `item_list`.

Future PlatformAppStore detail pages can return a `SoftwareApplication` node through `schema_nodes`; `applicationCategory` should use the primary surface/tag.
