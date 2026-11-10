# Changes

## v1.1.5

- Animated images (animated GIF, animated PNG and animated WebP) are now left untouched.
  Before, optimizing them kept only the first frame, so the animation was lost.
- Only JPEG, PNG, GIF and WebP images are processed now, checked both on the file's recorded
  type and on its actual content. Other image formats (such as TIFF, BMP or SVG) are no longer
  touched.
- Aliases (files that link to another file), and files that aliases point at, are now left
  untouched, so those links keep working instead of becoming separate, stale copies.
- Safer image processing: when the Imagick extension is used, it reads each file only as the
  expected image format and works within memory, disk and time limits set by the plugin.
- Skipped files are still recorded, so they are not examined again on every run.
- The plugin now has an icon on the plugins overview page.
- README: the default for "Enable image optimization" is Off (it said On), the requirement is
  Moodle 5.0 or later (it said 4.5), and a warning now explains that optimized images replace
  the originals with no backup kept.
