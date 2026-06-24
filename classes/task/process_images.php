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
 * @package    tool_imageoptimize
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imageoptimize\task;

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
     * Get the task name as shown in the scheduled tasks admin UI.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:processimages', 'tool_imageoptimize');
    }

    /**
     * Find and optimize eligible image files that have not yet been processed.
     */
    public function execute() {
        global $DB;

        if (!get_config('tool_imageoptimize', 'enabled')) {
            return;
        }

        $minsizekb = (int) get_config('tool_imageoptimize', 'minsizekb');
        $minsizebytes = $minsizekb * 1024;

        $sql = "SELECT f.*
                  FROM {files} f
             LEFT JOIN {tool_imageoptimize_files} o ON o.pathnamehash = f.pathnamehash
                 WHERE f.mimetype LIKE 'image/%'
                   AND f.filename <> '.'
                   AND f.filesize > :minsize
                   AND f.component <> 'tool_imageoptimize'
                   AND o.id IS NULL
              ORDER BY f.id ASC";

        $fs = get_file_storage();
        $records = $DB->get_records_sql($sql, ['minsize' => $minsizebytes], 0, self::BATCH_LIMIT);

        foreach ($records as $record) {
            $file = $fs->get_file_instance($record);
            $this->optimize_file($file);
        }
    }

    /**
     * Resize/recompress a single stored file and replace its content in place,
     * then record the result in the tracking table.
     *
     * @param \stored_file $file
     */
    protected function optimize_file(\stored_file $file): void {
        global $DB;

        $originalsize = $file->get_filesize();
        $content = $file->get_content();

        // Inspect declared dimensions before decoding: a small file can claim
        // an enormous canvas, and both GD and Imagick allocate memory based on
        // declared pixels rather than file size. Reject pathological images
        // up front instead of risking memory exhaustion on the cron process.
        $imageinfo = @getimagesizefromstring($content);
        if ($imageinfo === false || $imageinfo[0] * $imageinfo[1] > self::MAX_PIXELS) {
            return;
        }

        $maxwidth = (int) get_config('tool_imageoptimize', 'maxwidth');
        $maxheight = (int) get_config('tool_imageoptimize', 'maxheight');
        $quality = (int) get_config('tool_imageoptimize', 'quality');
        $targetformat = get_config('tool_imageoptimize', 'targetformat');

        $format = $this->resolve_target_format($file->get_mimetype(), $targetformat);

        if (class_exists('Imagick')) {
            $newcontent = $this->optimize_with_imagick($content, $format['format'], $maxwidth, $maxheight, $quality);
        } else {
            $newcontent = $this->optimize_with_gd($content, $format['format'], $maxwidth, $maxheight, $quality);
        }

        if ($newcontent === null || strlen($newcontent) >= $originalsize) {
            // Optimization did not help; leave the original file untouched.
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
        ];

        $fs = get_file_storage();
        $file->delete();
        $newfile = $fs->create_file_from_string($filerecord, $newcontent);

        $tracking = (object) [
            'pathnamehash'  => $newfile->get_pathnamehash(),
            'contextid'     => $filerecord['contextid'],
            'component'     => $filerecord['component'],
            'filearea'      => $filerecord['filearea'],
            'itemid'        => $filerecord['itemid'],
            'filename'      => $filerecord['filename'],
            'mimetype'      => $newfile->get_mimetype(),
            'originalsize'  => $originalsize,
            'optimizedsize' => strlen($newcontent),
            'timeprocessed' => time(),
        ];
        $DB->insert_record('tool_imageoptimize_files', $tracking);
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
     * @return string|null encoded image content, or null on failure
     */
    protected function optimize_with_imagick(
        string $content,
        string $format,
        int $maxwidth,
        int $maxheight,
        int $quality
    ): ?string {
        try {
            $im = new \Imagick();
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

            $im->setImageCompressionQuality($quality);
            $im->stripImage();
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
