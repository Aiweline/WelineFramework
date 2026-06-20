# Weline_MediaManager

MediaManager provides the backend media connector and development-time static/media routing for files under `pub/media`.

## Security Contract

- Static request paths are URL-decoded before filesystem resolution.
- Static routing rejects decoded paths containing `..`, backslashes, NUL, or other control characters.
- Static files are served only after `realpath()` proves the requested file is inside the allowed root directory.
- Backend connector hashes and `path` parameters resolve to paths under the media root only.
- `mkdir`, `rename`, and upload target names must be single basename values without path separators or control characters.
- Write targets are checked against the real media root before creating directories, renaming, or moving uploaded files.

The connector must preserve normal nested media folders while refusing traversal attempts such as `../../app/etc/env.php`.
