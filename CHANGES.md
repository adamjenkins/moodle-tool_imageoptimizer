# Changes

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
