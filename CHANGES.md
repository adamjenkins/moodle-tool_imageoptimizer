# Changes

## v1.1.1

- Renamed the plugin to **`tool_imageoptimizer`** ("Image optimizer"); the old
  name `tool_imageoptimize` clashed with a different plugin already on
  moodle.org. No functional changes.

## v1.1.0

Robustness and safety hardening following an external code review.

- Image optimization is now **disabled by default** — a fresh install no
  longer rewrites site-wide images until an administrator enables it.
- Files that do not shrink, or that cannot be decoded, are now recorded so
  they are not re-processed on every run (previously a source of wasted work
  and possible starvation of other files).
- The in-place swap now restores the original if writing the optimized
  replacement fails, and one failing file no longer aborts the batch.
- Under "keep original", GIFs are re-encoded to PNG (preserving transparency)
  instead of being flattened to JPEG; ICC colour profiles are preserved.
- Quality and dimension settings are clamped to sane ranges.

## v1.0.0

First public release.

- A scheduled task finds images in Moodle file storage above a configurable
  size threshold and resizes/recompresses them with Imagick or GD.
- Configurable maximum dimensions, quality, and target format (keep
  original, JPEG or WEBP); optimized files keep their original filename so
  embedded @@PLUGINFILE@@ references keep working.
- Decompression-bomb protection: declared image dimensions are checked
  before any decode is attempted.
- Privacy provider with full export/delete/userlist support and PHPUnit
  tests for the task and the provider.
- CI on Moodle 5.0, 5.1 and 5.2 (PHP matched per branch; PostgreSQL and
  MariaDB).
