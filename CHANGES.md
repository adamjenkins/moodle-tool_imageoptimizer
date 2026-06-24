# Changelog

All notable changes to `tool_imageoptimize` are documented here.

## v1.0.0 (2026-06-24)

- Initial release.
- Scheduled task (`tool_imageoptimize\task\process_images`, runs every 15
  minutes) finds image files in Moodle file storage above a configurable
  size threshold and resizes/recompresses them using Imagick (preferred,
  when installed) or GD (fallback).
- Admin settings: enable toggle, minimum size threshold (KB), max
  width/height, compression quality, and target format (keep original,
  convert to JPEG, or convert to WEBP).
- Tracking table (`tool_imageoptimize_files`) records which files have been
  processed, to avoid reprocessing on subsequent task runs.
- Privacy provider with full export/delete/userlist support. The tracking
  table has no `userid` column of its own; ownership is resolved by joining
  against the core `{files}` table.
- Security hardening: a declared-dimension check (via `getimagesizefromstring()`)
  rejects images whose width × height exceeds 40 megapixels *before* any
  decode is attempted, to prevent a small file from triggering a
  decompression-bomb-style memory exhaustion in GD/Imagick.
- Fixed: optimized files always keep their original filename. Rich-text
  content (labels, pages, and other components with HTML fields) embeds
  `@@PLUGINFILE@@/<filename>` literally, resolved by exact filename at
  render time — renaming the underlying stored file orphans that reference
  and breaks the embedded image. The mimetype is set explicitly from the
  re-encoded content instead of being inferred from the (unchanged) file
  extension.
- Removed the `tool/imageoptimize:manage` capability: it was declared but
  never enforced anywhere (admin settings are already gated by core's
  `moodle/site:config`), so keeping it implied access control that didn't
  actually exist.
