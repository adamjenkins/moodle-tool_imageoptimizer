<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scheduled task that optimizes newly uploaded image files.
 *
 * @package    tool_imageoptimizer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imageoptimizer\task;

/**
 * Finds image files above the configured size threshold that have not yet
 * been processed, resizes/recompresses them, and replaces the stored file
 * content in place.
 */
class process_images extends \core\task\scheduled_task {
    /** Maximum number of files processed per run, to bound a single cron pass. */
    const BATCH_LIMIT = 200;

    /**
     * Maximum decoded pixel count (width * height) allowed before decoding.
     * GD and Imagick both allocate memory proportional to declared pixel
     * dimensions, not file size on disk, so a small file can claim an
     * enormous canvas ("decompression bomb"). 40 megapixels covers any
     * legitimate upload while bounding worst-case memory use.
     */
    const MAX_PIXELS = 40_000_000;

    /**
     * Mimetypes the task will select and decode. Anything else (TIFF, BMP, PSD,
     * SVG, ICO, ...) is left alone: it is not worth widening the parser attack
     * surface for formats the encoder cannot write back anyway.
     */
    const ALLOWED_MIMETYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /**
     * Sniffed image types (getimagesizefromstring() index 2) allowed to be
     * decoded, matching ALLOWED_MIMETYPES. A file whose stored mimetype is
     * allowed but whose bytes are something else is recorded and skipped.
     */
    const ALLOWED_IMAGETYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /**
     * Get the task name as shown in the scheduled tasks admin UI.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:processimages', 'tool_imageoptimizer');
    }

    /**
     * Find and optimize eligible image files that have not yet been processed.
     */
    public function execute() {
        global $DB;

        // Housekeeping runs even when optimization is disabled: a tracking row
        // whose file has been deleted keeps a user-chosen filename for no
        // purpose, so it is removed as soon as possible.
        self::purge_orphaned_records();

        if (!get_config('tool_imageoptimizer', 'enabled')) {
            return;
        }

        $minsizekb = max(0, (int) get_config('tool_imageoptimizer', 'minsizekb'));
        $minsizebytes = $minsizekb * 1024;

        // Aliases (referencefileid set) are never selected: their content
        // belongs to the file they point at, and rewriting one would turn the
        // alias into a standalone copy.
        [$mimesql, $params] = $DB->get_in_or_equal(self::ALLOWED_MIMETYPES, SQL_PARAMS_NAMED, 'mime');
        $sql = "SELECT f.*
                  FROM {files} f
             LEFT JOIN {tool_imageoptimizer_files} o ON o.pathnamehash = f.pathnamehash
                 WHERE f.mimetype {$mimesql}
                   AND f.filename <> '.'
                   AND f.filesize > :minsize
                   AND f.component <> 'tool_imageoptimizer'
                   AND f.referencefileid IS NULL
                   AND o.id IS NULL
              ORDER BY f.id ASC";

        $fs = get_file_storage();
        $filesystem = $fs->get_file_system();
        $params['minsize'] = $minsizebytes;
        $records = $DB->get_records_sql($sql, $params, 0, self::BATCH_LIMIT);

        foreach ($records as $record) {
            $file = $fs->get_file_instance($record);
            // The batch was queried up front, so a row's content can have
            // disappeared in the meantime (replaced earlier in this run, or
            // simply missing from the file store); skip anything unreadable
            // rather than triggering PHP warnings inside get_content().
            if (!$filesystem->is_file_readable_locally_by_storedfile($file)) {
                continue;
            }
            try {
                $this->optimize_file($file);
            } catch (\Throwable $e) {
                // One file failing (for example an optimized write that failed
                // after the original was restored) must not abort the rest of
                // the batch; log it and carry on.
                mtrace('tool_imageoptimizer: failed to process file id ' .
                    $file->get_id() . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Delete tracking rows whose file no longer exists in the file storage.
     *
     * A file can be deleted at any time (by its owner, a course reset, a
     * submission purge, ...), and nothing tells this plugin. Without this
     * clean-up the row would keep the file's name indefinitely, and its
     * pathnamehash would stop a later file at the same path from ever being
     * selected for optimization.
     *
     * @return int number of rows deleted
     */
    public static function purge_orphaned_records(): int {
        global $DB;

        $ids = $DB->get_fieldset_sql(
            "SELECT o.id
               FROM {tool_imageoptimizer_files} o
          LEFT JOIN {files} f ON f.pathnamehash = o.pathnamehash
              WHERE f.id IS NULL"
        );
        foreach (array_chunk($ids, 1000) as $chunk) {
            $DB->delete_records_list('tool_imageoptimizer_files', 'id', $chunk);
        }
        return count($ids);
    }

    /**
     * Resize/recompress a single stored file and replace its content in place,
     * then record the result in the tracking table.
     *
     * @param \stored_file $file
     */
    protected function optimize_file(\stored_file $file): void {
        $originalsize = $file->get_filesize();
        $content = $file->get_content();

        // Inspect declared dimensions before decoding: a small file can claim
        // an enormous canvas, and both GD and Imagick allocate memory based on
        // declared pixels rather than file size. Reject pathological images
        // up front instead of risking memory exhaustion on the cron process.
        // The sniffed type must be on the allowlist too, whatever the stored
        // mimetype claims, so only the JPEG/PNG/GIF/WebP decoders are reached.
        // Multi-frame images (animated GIF, APNG, animated WebP) are skipped:
        // re-encoding keeps a single frame, irreversibly losing the animation,
        // and every frame is decoded, which the pixel guard does not bound.
        // A file that has aliases pointing at it is skipped as well: replacing
        // it would detach those aliases into standalone copies of the old bytes.
        $imageinfo = @getimagesizefromstring($content);
        if (
            $imageinfo === false
            || !in_array($imageinfo[2], self::ALLOWED_IMAGETYPES, true)
            || $imageinfo[0] * $imageinfo[1] > self::MAX_PIXELS
            || self::is_multi_frame($content, $imageinfo[2])
            || !empty(get_file_storage()->get_references_by_storedfile($file))
        ) {
            // This file cannot (or must not) be decoded or replaced, so it will
            // never be optimized. Record it anyway: without a tracking row it is
            // re-selected and its blob re-read on every run, and because the
            // batch is ordered by id and capped, a cluster of such files would
            // starve everything else from ever being processed.
            $this->record_processed($file, $originalsize, $originalsize);
            return;
        }
        $inputformat = image_type_to_extension($imageinfo[2], false);

        // Clamp administrator-configured values to sane ranges: a quality
        // outside 1-100 or a zero dimension (which makes the resize ratio 0)
        // would otherwise produce degenerate or failed output.
        $maxwidth = max(1, (int) get_config('tool_imageoptimizer', 'maxwidth'));
        $maxheight = max(1, (int) get_config('tool_imageoptimizer', 'maxheight'));
        $quality = min(100, max(1, (int) get_config('tool_imageoptimizer', 'quality')));
        $targetformat = get_config('tool_imageoptimizer', 'targetformat');

        $format = $this->resolve_target_format($file->get_mimetype(), $targetformat);

        if (class_exists('Imagick')) {
            $newcontent = $this->optimize_with_imagick(
                $content,
                $format['format'],
                $maxwidth,
                $maxheight,
                $quality,
                $inputformat
            );
        } else {
            $newcontent = $this->optimize_with_gd($content, $format['format'], $maxwidth, $maxheight, $quality);
        }

        if ($newcontent === null || strlen($newcontent) >= $originalsize) {
            // Optimization did not help; leave the original file untouched but
            // still record it so it is not re-decoded and re-encoded every run.
            $this->record_processed($file, $originalsize, $originalsize);
            return;
        }

        // Keep the original filename: components such as labels and pages embed
        // "@@PLUGINFILE@@/<filename>" literally in their HTML content, and that
        // reference is resolved by exact filename at render time. Renaming the
        // file here would silently break any inline image that points at it.
        // The correct content-type is set explicitly below instead.
        $filerecord = [
            'contextid' => $file->get_contextid(),
            'component' => $file->get_component(),
            'filearea'  => $file->get_filearea(),
            'itemid'    => $file->get_itemid(),
            'filepath'  => $file->get_filepath(),
            'filename'  => $file->get_filename(),
            'mimetype'  => $format['mimetype'],
            // Carry the file's ownership and attribution over to the
            // replacement; leaving them out would silently make the file
            // ownerless (no userid, author, licence or source).
            'userid'      => $file->get_userid(),
            'author'      => $file->get_author(),
            'license'     => $file->get_license(),
            'source'      => $file->get_source(),
            'sortorder'   => $file->get_sortorder(),
            'timecreated' => $file->get_timecreated(),
        ];

        $fs = get_file_storage();
        // Swap the content in place. The delete must come first because {files}
        // enforces a unique pathnamehash, so the replacement cannot coexist
        // with the original. If create_file_from_string() throws after the
        // delete (a transient DB error, a full or read-only disk, a concurrent
        // re-create), restore the original from the bytes still held in memory
        // so no user file is ever lost.
        try {
            $file->delete();
            $newfile = $fs->create_file_from_string($filerecord, $newcontent);
        } catch (\Throwable $e) {
            if (
                !$fs->file_exists(
                    $filerecord['contextid'],
                    $filerecord['component'],
                    $filerecord['filearea'],
                    $filerecord['itemid'],
                    $filerecord['filepath'],
                    $filerecord['filename']
                )
            ) {
                $restore = $filerecord;
                $restore['mimetype'] = $file->get_mimetype();
                $fs->create_file_from_string($restore, $content);
            }
            throw $e;
        }

        $this->record_processed($newfile, $originalsize, strlen($newcontent), $file->get_userid());
    }

    /**
     * Record that a file has been processed, in the tracking table.
     *
     * Called both when a file was optimized (with its new, smaller size) and
     * when it was examined but left untouched (original size for both), so that
     * every eligible file is remembered and is not reconsidered on later runs.
     * The tracking row is keyed by the file's pathnamehash, which is what the
     * selection query joins on. It also stores the file owner, so the privacy
     * provider can find the row without the {files} row still existing.
     *
     * @param \stored_file $file the stored file as it exists after processing
     * @param int $originalsize size in bytes before processing
     * @param int $optimizedsize size in bytes after processing (equal to the
     *                            original when the file was left unchanged)
     * @param int|null $userid owner of the original file; defaults to the owner of $file
     */
    private function record_processed(
        \stored_file $file,
        int $originalsize,
        int $optimizedsize,
        ?int $userid = null
    ): void {
        global $DB;

        if ($userid === null) {
            $userid = $file->get_userid();
        }

        $DB->insert_record('tool_imageoptimizer_files', (object) [
            'pathnamehash'  => $file->get_pathnamehash(),
            'contextid'     => $file->get_contextid(),
            'component'     => $file->get_component(),
            'filearea'      => $file->get_filearea(),
            'itemid'        => $file->get_itemid(),
            'userid'        => $userid ? (int) $userid : null,
            'filename'      => $file->get_filename(),
            'mimetype'      => $file->get_mimetype(),
            'originalsize'  => $originalsize,
            'optimizedsize' => $optimizedsize,
            'timeprocessed' => time(),
        ]);
    }

    /**
     * Whether the image bytes hold more than one frame.
     *
     * Checked on the raw bytes so that it works the same with or without
     * Imagick. The checks are deliberately conservative: a false positive only
     * means a still image is left unoptimized.
     *
     * @param string $content image bytes
     * @param int $imagetype IMAGETYPE_* constant from getimagesizefromstring()
     * @return bool
     */
    public static function is_multi_frame(string $content, int $imagetype): bool {
        switch ($imagetype) {
            case IMAGETYPE_GIF:
                // Each frame of an animated GIF carries its own graphic control
                // extension (0x21 0xF9 0x04) and image descriptor (0x2C after a
                // block terminator); a still GIF has at most one of each.
                return substr_count($content, "\x21\xF9\x04") > 1
                    || substr_count($content, "\x00\x2C") > 1;
            case IMAGETYPE_PNG:
                // APNG declares its animation in an acTL chunk.
                return strpos($content, 'acTL') !== false;
            case IMAGETYPE_WEBP:
                // Animated WebP carries an ANIM chunk.
                return strpos($content, 'ANIM') !== false;
            default:
                return false;
        }
    }

    /**
     * Decide which image format to encode to, based on the admin setting and
     * the file's current mimetype.
     *
     * @param string $originalmimetype
     * @param string $targetformat one of 'keep', 'jpeg', 'webp'
     * @return array{format: string, mimetype: string}
     */
    protected function resolve_target_format(string $originalmimetype, string $targetformat): array {
        if ($targetformat === 'keep') {
            if ($originalmimetype === 'image/png') {
                return ['format' => 'png', 'mimetype' => 'image/png'];
            }
            if ($originalmimetype === 'image/webp') {
                return ['format' => 'webp', 'mimetype' => 'image/webp'];
            }
            if ($originalmimetype === 'image/gif') {
                // GIF carries transparency (and possibly animation). Re-encoding
                // it as JPEG would flatten transparent pixels to black, so keep
                // it in a transparency-capable format (PNG) instead.
                return ['format' => 'png', 'mimetype' => 'image/png'];
            }
            return ['format' => 'jpeg', 'mimetype' => 'image/jpeg'];
        }

        if ($targetformat === 'webp') {
            return ['format' => 'webp', 'mimetype' => 'image/webp'];
        }

        return ['format' => 'jpeg', 'mimetype' => 'image/jpeg'];
    }

    /**
     * Resize/recompress image content using the Imagick extension.
     *
     * @param string $content original image bytes
     * @param string $format one of 'jpeg', 'png', 'webp'
     * @param int $maxwidth
     * @param int $maxheight
     * @param int $quality
     * @param string|null $inputformat decoder to pin ('jpeg', 'png', 'gif' or
     *        'webp'), so ImageMagick never picks a coder from the bytes alone
     * @return string|null encoded image content, or null on failure
     */
    protected function optimize_with_imagick(
        string $content,
        string $format,
        int $maxwidth,
        int $maxheight,
        int $quality,
        ?string $inputformat = null
    ): ?string {
        try {
            self::set_imagick_limits();

            // Ping first (reads headers only) and refuse multi-frame input:
            // every frame would be decoded, and only one would survive.
            $probe = new \Imagick();
            if ($inputformat !== null) {
                $probe->setFormat($inputformat);
            }
            $probe->pingImageBlob($content);
            $frames = $probe->getNumberImages();
            $probe->destroy();
            if ($frames > 1) {
                return null;
            }

            $im = new \Imagick();
            if ($inputformat !== null) {
                $im->setFormat($inputformat);
            }
            $im->readImageBlob($content);

            $width = $im->getImageWidth();
            $height = $im->getImageHeight();
            $ratio = min($maxwidth / $width, $maxheight / $height, 1);
            if ($ratio < 1) {
                $im->resizeImage(
                    (int) round($width * $ratio),
                    (int) round($height * $ratio),
                    \Imagick::FILTER_LANCZOS,
                    1
                );
            }

            // Preserve the ICC colour profile across the strip. Dropping EXIF
            // and other metadata keeps files small, but discarding the colour
            // profile would visibly shift colours on wide-gamut images.
            $icc = '';
            try {
                $icc = $im->getImageProfile('icc');
            } catch (\ImagickException $e) {
                $icc = '';
            }

            $im->setImageCompressionQuality($quality);
            $im->stripImage();
            if ($icc !== '') {
                $im->profileImage('icc', $icc);
            }
            // The decoder was pinned with setFormat(), and the wand's format
            // wins over the image's when the blob is written: set both, or the
            // file keeps its input format under the new mimetype.
            $im->setFormat($format);
            $im->setImageFormat($format);
            if ($format === 'jpeg') {
                $im->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
            }

            $newcontent = $im->getImageBlob();
            $im->destroy();
            return $newcontent;
        } catch (\ImagickException $e) {
            return null;
        }
    }

    /**
     * Bound ImageMagick's own resource use for the decode, rather than relying
     * on the distribution's policy.xml. The limits are process-wide and are
     * generous for any legitimate upload.
     */
    protected static function set_imagick_limits(): void {
        $limits = [
            'RESOURCETYPE_AREA'   => self::MAX_PIXELS,
            'RESOURCETYPE_WIDTH'  => 20000,
            'RESOURCETYPE_HEIGHT' => 20000,
            'RESOURCETYPE_MEMORY' => 256 * 1024 * 1024,
            'RESOURCETYPE_MAP'    => 512 * 1024 * 1024,
            'RESOURCETYPE_DISK'   => 1024 * 1024 * 1024,
            'RESOURCETYPE_TIME'   => 60,
        ];
        foreach ($limits as $name => $value) {
            // Older Imagick builds lack some of these constants.
            if (!defined('\Imagick::' . $name)) {
                continue;
            }
            try {
                \Imagick::setResourceLimit(constant('\Imagick::' . $name), $value);
            } catch (\Throwable $e) {
                // A limit the linked ImageMagick does not support is not fatal.
                continue;
            }
        }
    }

    /**
     * Resize/recompress image content using the GD extension (fallback when
     * Imagick is not available).
     *
     * @param string $content original image bytes
     * @param string $format one of 'jpeg', 'png', 'webp'
     * @param int $maxwidth
     * @param int $maxheight
     * @param int $quality
     * @return string|null encoded image content, or null on failure
     */
    protected function optimize_with_gd(string $content, string $format, int $maxwidth, int $maxheight, int $quality): ?string {
        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = min($maxwidth / $width, $maxheight / $height, 1);

        if ($ratio < 1) {
            $newwidth = (int) round($width * $ratio);
            $newheight = (int) round($height * $ratio);
            $resized = imagescale($image, $newwidth, $newheight);
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        ob_start();

        if ($format === 'webp' && function_exists('imagewebp')) {
            $success = imagewebp($image, null, $quality);
        } else if ($format === 'png') {
            $success = imagepng($image, null, (int) round((100 - $quality) / 100 * 9));
        } else {
            imageinterlace($image, true);
            $success = imagejpeg($image, null, $quality);
        }

        $content = ob_get_clean();
        imagedestroy($image);
        return $success ? $content : null;
    }
}
