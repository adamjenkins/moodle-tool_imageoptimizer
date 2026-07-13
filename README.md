# tool_imageoptimizer

An admin tool plugin for Moodle that automatically resizes and recompresses
oversized images stored anywhere in Moodle's file storage, to reduce disk
usage and improve page load times.

## How it works

Moodle's file API has no single, universal "file uploaded" event fired for
every component, so this plugin works by polling instead of hooking upload
events directly: a scheduled task (`tool_imageoptimizer\task\process_images`,
runs every 15 minutes by default) scans the `files` table for image files
above a configurable size threshold that haven't been processed yet, and:

1. Resizes the image down to fit within the configured maximum
   width/height (if it's larger), preserving aspect ratio.
2. Recompresses it at the configured quality, optionally converting to a
   different format (JPEG or WEBP), or keeping the original format.
3. Replaces the stored file's content in place — **the filename is never
   changed**, only the bytes and the mimetype.
4. Records the result in the `tool_imageoptimizer_files` tracking table so
   the same file isn't reprocessed on the next run.

Imagick is used when the extension is available (better quality/format
support); otherwise it falls back to GD.

## Why the filename never changes

Many Moodle components (labels, pages, course summaries, forum posts, etc.)
embed images in rich-text HTML using a literal placeholder:

```html
<img src="@@PLUGINFILE@@/photo.png">
```

This is resolved to a real `pluginfile.php` URL **by exact filename** at
render time. If this plugin renamed `photo.png` to `photo.jpg` when
converting formats, that embedded reference would point at a file that no
longer exists, breaking the image everywhere it's displayed. So the
filename is always preserved, even when the format changes — the mimetype
is set explicitly to match the actual re-encoded content instead of being
inferred from the (now possibly mismatched) file extension. This means a
file might end up named `photo.png` while actually containing JPEG bytes;
browsers render `<img>` tags by sniffing content, not by trusting the
extension, so this has no visible effect, but it is worth knowing if you
inspect file storage directly or rely on the extension elsewhere.

## Settings

Site administration → Plugins → Admin tools → Image optimizer:

| Setting | Description | Default |
|---|---|---|
| Enable image optimization | Master on/off switch for the scheduled task | On |
| Minimum file size (KB) | Only images larger than this are processed | 500 |
| Maximum width / height (px) | Images larger than this are resized down, preserving aspect ratio | 1920 / 1080 |
| Compression quality (1-100) | Used when re-encoding lossy formats (JPEG/WEBP) | 80 |
| Target format | Keep original, or convert everything to JPEG or WEBP | Keep original |

## Requirements

- Moodle 4.5+ (`$plugin->requires = 2024100700`)
- PHP's GD extension (always available in a standard Moodle install)
- Imagick extension (optional, used automatically when present for better
  quality and broader format support)

## Privacy

This plugin stores metadata about which files it has processed (filename,
original/optimized size, timestamp) in `tool_imageoptimizer_files`. That
table has no `userid` column of its own — ownership for GDPR export/delete
requests is resolved by joining against the core `files` table via the
file's content hash. See `classes/privacy/provider.php`.

## Security notes

- All database queries use parameterised placeholders; none concatenate
  user-controlled input into SQL.
- Before decoding any image, the plugin checks the file's *declared*
  dimensions (via `getimagesizefromstring()`, which only parses the header)
  and skips anything over 40 megapixels. GD and Imagick both allocate
  memory proportional to declared pixel dimensions rather than file size on
  disk, so without this check a small, maliciously crafted file could
  trigger a decompression-bomb-style memory exhaustion on the cron process.

## Tests

```bash
php admin/tool/phpunit/cli/init.php   # first time only
vendor/bin/phpunit admin/tool/imageoptimizer/tests/
```

See `CHANGES.md` for version history.
