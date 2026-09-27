# Changelog

All notable changes to `tool_imageoptimizer` are documented here.

## Unreleased

- Declare Moodle 5.3 support.

## v1.1.2 (2026-08-04)

- The full GPL-3.0 licence text is now included as `LICENSE` in the repository
  root. The plugin's licence is unchanged (GPL-3.0-or-later, as declared in
  `composer.json`); the file was simply missing.

## v1.1.1 (2026-07-13)

- Renamed: the plugin's frankenstyle component changed from `tool_imageoptimize`
  to **`tool_imageoptimizer`** (display name "Image optimizer"). The original
  name clashed with an unrelated plugin already published on moodle.org. No
  behavioural changes; the tracking table is now `tool_imageoptimizer_files`.

## v1.1.0 (2026-07-13)

Robustness and safety hardening following an external code review.

- Changed: image optimization is now **disabled by default**. The task
  rewrites images in place irreversibly, so a fresh install no longer starts
  altering site-wide content until an administrator consciously enables it.
  (Existing installs keep their current setting.)
- Fixed: a file whose optimization did not reduce its size — and a file that
  cannot be decoded (e.g. a decompression-bomb reject) — is now recorded in
  the tracking table. Previously such files had no tracking row and were
  re-selected, re-decoded and re-encoded on **every** run; because the batch
  is ordered by id and capped, a cluster of them could starve all other
  files from ever being processed.
- Fixed: potential data-loss window in the in-place swap. The original file
  is now restored from the in-memory bytes if creating the optimized
  replacement fails after the original was deleted, and a single file
  failing no longer aborts the rest of the batch.
- Fixed: under "keep original", GIF images are now re-encoded to PNG rather
  than JPEG, preserving transparency (a transparent GIF previously became a
  black-background JPEG).
- Changed: the ICC colour profile is now preserved when stripping metadata
  (Imagick path), so wide-gamut images no longer shift colour.
- Changed: administrator-configured quality and dimensions are clamped to
  sane ranges (quality 1–100, dimensions ≥ 1) before use.
- Changed: the selection query uses `$DB->sql_like()` for portability.
- Fixed: the `pathnamehash` column comment and its privacy-export
  description no longer mislabel it as a "content hash".
- Changed: `$plugin->requires` raised to Moodle 5.0 (matching the supported
  range) and maturity set to stable for the 1.x line.

## v1.0.0 (2026-06-24)

- Initial release.
- Scheduled task (`tool_imageoptimizer\task\process_images`, runs every 15
  minutes) finds image files in Moodle file storage above a configurable
  size threshold and resizes/recompresses them using Imagick (preferred,
  when installed) or GD (fallback).
- Admin settings: enable toggle, minimum size threshold (KB), max
  width/height, compression quality, and target format (keep original,
  convert to JPEG, or convert to WEBP).
- Tracking table (`tool_imageoptimizer_files`) records which files have been
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
- Removed the `tool/imageoptimizer:manage` capability: it was declared but
  never enforced anywhere (admin settings are already gated by core's
  `moodle/site:config`), so keeping it implied access control that didn't
  actually exist.
