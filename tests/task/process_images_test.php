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
 * Tests for the tool_imageoptimizer\task\process_images scheduled task.
 *
 * @package    tool_imageoptimizer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imageoptimizer\task;

/**
 * Tests for process_images.
 *
 * @covers \tool_imageoptimizer\task\process_images
 */
final class process_images_test extends \advanced_testcase {
    /**
     * Build a solid-colour PNG of the given dimensions, large enough that
     * GD/Imagick re-encoding will reliably shrink it.
     *
     * @param int $width
     * @param int $height
     * @return string raw PNG bytes
     */
    private function make_png(int $width, int $height): string {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x += 10) {
                $colour = imagecolorallocate($image, $x % 256, $y % 256, ($x + $y) % 256);
                imagefilledrectangle($image, $x, $y, $x + 10, $y, $colour);
            }
        }
        ob_start();
        imagepng($image, null, 0);
        $content = ob_get_clean();
        imagedestroy($image);
        return $content;
    }

    /**
     * Build a tiny solid-colour PNG. Such an image is already about as small as
     * PNG allows, so re-encoding it to JPEG (which carries a fixed quantisation
     * and Huffman-table overhead) reliably produces a *larger* file — a
     * deterministic "optimization did not help" case.
     *
     * @param int $width
     * @param int $height
     * @return string raw PNG bytes
     */
    private function make_solid_png(int $width, int $height): string {
        $image = imagecreatetruecolor($width, $height);
        $colour = imagecolorallocate($image, 200, 30, 30);
        imagefilledrectangle($image, 0, 0, $width, $height, $colour);
        ob_start();
        imagepng($image, null, 9);
        $content = ob_get_clean();
        imagedestroy($image);
        return $content;
    }

    /**
     * Call a protected/private method via reflection.
     *
     * @param object $object
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function call_protected(object $object, string $method, array $args) {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($object, $args);
    }

    public function test_resolve_target_format_keep_preserves_png(): void {
        $task = new process_images();
        $result = $this->call_protected($task, 'resolve_target_format', ['image/png', 'keep']);
        $this->assertSame(['format' => 'png', 'mimetype' => 'image/png'], $result);
    }

    public function test_resolve_target_format_keep_preserves_webp(): void {
        $task = new process_images();
        $result = $this->call_protected($task, 'resolve_target_format', ['image/webp', 'keep']);
        $this->assertSame(['format' => 'webp', 'mimetype' => 'image/webp'], $result);
    }

    public function test_resolve_target_format_keep_preserves_jpeg(): void {
        $task = new process_images();
        $result = $this->call_protected($task, 'resolve_target_format', ['image/jpeg', 'keep']);
        $this->assertSame(['format' => 'jpeg', 'mimetype' => 'image/jpeg'], $result);
    }

    public function test_resolve_target_format_keep_converts_gif_to_png(): void {
        // GIF must not fall through to JPEG under "keep": that would flatten
        // transparency (and animation) to a black background. PNG preserves it.
        $task = new process_images();
        $result = $this->call_protected($task, 'resolve_target_format', ['image/gif', 'keep']);
        $this->assertSame(['format' => 'png', 'mimetype' => 'image/png'], $result);
    }

    public function test_resolve_target_format_explicit_webp_overrides_original(): void {
        $task = new process_images();
        $result = $this->call_protected($task, 'resolve_target_format', ['image/png', 'webp']);
        $this->assertSame(['format' => 'webp', 'mimetype' => 'image/webp'], $result);
    }

    public function test_resolve_target_format_explicit_jpeg_overrides_original(): void {
        $task = new process_images();
        $result = $this->call_protected($task, 'resolve_target_format', ['image/png', 'jpeg']);
        $this->assertSame(['format' => 'jpeg', 'mimetype' => 'image/jpeg'], $result);
    }

    public function test_optimize_with_gd_shrinks_and_resizes(): void {
        $task = new process_images();
        $content = $this->make_png(2000, 1500);

        $result = $this->call_protected($task, 'optimize_with_gd', [$content, 'jpeg', 1920, 1080, 80]);

        $this->assertNotNull($result);
        $this->assertLessThan(strlen($content), strlen($result));
        $info = getimagesizefromstring($result);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertLessThanOrEqual(1920, $info[0]);
        $this->assertLessThanOrEqual(1080, $info[1]);
    }

    public function test_optimize_with_gd_returns_null_for_invalid_content(): void {
        $task = new process_images();
        $result = $this->call_protected($task, 'optimize_with_gd', ['not an image', 'jpeg', 1920, 1080, 80]);
        $this->assertNull($result);
    }

    public function test_optimize_with_imagick_shrinks_and_resizes(): void {
        if (!class_exists('Imagick')) {
            $this->markTestSkipped('Imagick extension is not available.');
        }

        $task = new process_images();
        $content = $this->make_png(2000, 1500);

        $result = $this->call_protected($task, 'optimize_with_imagick', [$content, 'jpeg', 1920, 1080, 80]);

        $this->assertNotNull($result);
        $this->assertLessThan(strlen($content), strlen($result));
        $info = getimagesizefromstring($result);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertLessThanOrEqual(1920, $info[0]);
        $this->assertLessThanOrEqual(1080, $info[1]);
    }

    public function test_optimize_with_imagick_returns_null_for_invalid_content(): void {
        if (!class_exists('Imagick')) {
            $this->markTestSkipped('Imagick extension is not available.');
        }

        $task = new process_images();
        $result = $this->call_protected($task, 'optimize_with_imagick', ['not an image', 'jpeg', 1920, 1080, 80]);
        $this->assertNull($result);
    }

    public function test_execute_skips_when_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 0, 'tool_imageoptimizer');
        set_config('minsizekb', 1, 'tool_imageoptimizer');

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
        ];
        $fs->create_file_from_string($filerecord, $this->make_png(2000, 1500));

        $task = new process_images();
        $task->execute();

        $this->assertSame(0, $DB->count_records('tool_imageoptimizer_files'));
    }

    public function test_execute_records_and_skips_images_with_oversized_declared_dimensions(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 0, 'tool_imageoptimizer');

        // A forged PNG header declaring an enormous canvas (50000x50000 =
        // 2.5 billion pixels) in a tiny file. GD/Imagick allocate memory
        // based on declared dimensions, not file size, so this must be
        // rejected before any decode is attempted.
        $ihdr = pack('NN', 50000, 50000) . pack('C5', 8, 2, 0, 0, 0);
        $content = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . "\x00\x00\x00\x00";

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'bomb.png',
        ];
        $fs->create_file_from_string($filerecord, $content);

        $task = new process_images();
        $task->execute();

        // The file itself must be left completely untouched...
        $unchanged = $fs->get_file($context->id, 'tool_imageoptimizer_test', 'test', 0, '/', 'bomb.png');
        $this->assertNotFalse($unchanged);
        $this->assertSame(strlen($content), (int) $unchanged->get_filesize());

        // ...but it must still be recorded as processed, so it is not
        // re-selected and re-read on every run (which, with the id-ordered
        // capped batch, would otherwise starve other files).
        $tracking = $DB->get_record('tool_imageoptimizer_files', ['pathnamehash' => $unchanged->get_pathnamehash()]);
        $this->assertNotFalse($tracking);
        $this->assertSame((int) $tracking->originalsize, (int) $tracking->optimizedsize);

        // A second run must not reprocess it: the tracking row makes it
        // ineligible, so no further rows are created. (The base PHPUnit dataset
        // may hold other eligible images, so assert the total is stable across
        // runs rather than an absolute count.)
        $countafterfirst = $DB->count_records('tool_imageoptimizer_files');
        $task->execute();
        $this->assertSame($countafterfirst, $DB->count_records('tool_imageoptimizer_files'));
    }

    public function test_execute_records_files_that_do_not_shrink(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 0, 'tool_imageoptimizer');
        set_config('maxwidth', 4000, 'tool_imageoptimizer');
        set_config('maxheight', 4000, 'tool_imageoptimizer');
        set_config('quality', 80, 'tool_imageoptimizer');
        // Forcing JPEG output for a tiny solid PNG guarantees the re-encode is
        // larger than the original, exercising the "did not shrink" path.
        set_config('targetformat', 'jpeg', 'tool_imageoptimizer');

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'solid.png',
        ];
        $original = $fs->create_file_from_string($filerecord, $this->make_solid_png(60, 60));
        $originalsize = (int) $original->get_filesize();

        $task = new process_images();
        $task->execute();

        // The original must be left untouched (still a PNG of the same size)...
        $unchanged = $fs->get_file($context->id, 'tool_imageoptimizer_test', 'test', 0, '/', 'solid.png');
        $this->assertNotFalse($unchanged);
        $this->assertSame('image/png', $unchanged->get_mimetype());
        $this->assertSame($originalsize, (int) $unchanged->get_filesize());

        // ...but recorded so it is not re-decoded and re-encoded every run.
        $tracking = $DB->get_record('tool_imageoptimizer_files', ['pathnamehash' => $unchanged->get_pathnamehash()]);
        $this->assertNotFalse($tracking);
        $this->assertSame($originalsize, (int) $tracking->originalsize);
        $this->assertSame($originalsize, (int) $tracking->optimizedsize);

        // A second run must not reprocess it: assert the total is stable across
        // runs (the base PHPUnit dataset may hold other eligible images).
        $countafterfirst = $DB->count_records('tool_imageoptimizer_files');
        $task->execute();
        $this->assertSame($countafterfirst, $DB->count_records('tool_imageoptimizer_files'));
    }

    public function test_execute_skips_files_under_threshold(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 100000, 'tool_imageoptimizer');

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
        ];
        $fs->create_file_from_string($filerecord, $this->make_png(2000, 1500));

        $task = new process_images();
        $task->execute();

        $this->assertSame(0, $DB->count_records('tool_imageoptimizer_files'));
    }

    public function test_execute_optimizes_file_and_preserves_filename(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 1, 'tool_imageoptimizer');
        set_config('maxwidth', 1920, 'tool_imageoptimizer');
        set_config('maxheight', 1080, 'tool_imageoptimizer');
        set_config('quality', 80, 'tool_imageoptimizer');
        set_config('targetformat', 'jpeg', 'tool_imageoptimizer');

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
        ];
        $original = $fs->create_file_from_string($filerecord, $this->make_png(2000, 1500));
        $originalsize = $original->get_filesize();

        $task = new process_images();
        $task->execute();

        // The filename must never change: embedded "@@PLUGINFILE@@/photo.png"
        // references in rich text content rely on the literal filename
        // staying stable, even though the underlying bytes/format change.
        $optimized = $fs->get_file($context->id, 'tool_imageoptimizer_test', 'test', 0, '/', 'photo.png');
        $this->assertNotFalse($optimized);
        $this->assertSame('photo.png', $optimized->get_filename());
        $this->assertLessThan($originalsize, $optimized->get_filesize());

        // The mimetype must reflect the actual re-encoded content, not the
        // (unchanged) file extension.
        $this->assertSame('image/jpeg', $optimized->get_mimetype());
        $info = getimagesizefromstring($optimized->get_content());
        $this->assertSame('image/jpeg', $info['mime']);

        $tracking = $DB->get_record('tool_imageoptimizer_files', ['pathnamehash' => $optimized->get_pathnamehash()]);
        $this->assertNotFalse($tracking);
        $this->assertSame('photo.png', $tracking->filename);
        $this->assertSame((int) $originalsize, (int) $tracking->originalsize);
        $this->assertSame((int) $optimized->get_filesize(), (int) $tracking->optimizedsize);
    }

    public function test_execute_does_not_reprocess_tracked_files(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 1, 'tool_imageoptimizer');
        set_config('targetformat', 'keep', 'tool_imageoptimizer');

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
        ];
        $created = $fs->create_file_from_string($filerecord, $this->make_png(2000, 1500));

        $task = new process_images();
        $task->execute();
        // The file (filename, and therefore pathnamehash, is preserved) has a
        // tracking row after the first run.
        $this->assertTrue(
            $DB->record_exists('tool_imageoptimizer_files', ['pathnamehash' => $created->get_pathnamehash()])
        );

        // Running again must not reprocess any already-tracked file: the total
        // number of tracking rows is unchanged. (Asserting a stable count
        // rather than an absolute value keeps the test robust to other eligible
        // images present in the base PHPUnit dataset.)
        $countafterfirst = $DB->count_records('tool_imageoptimizer_files');
        $task->execute();
        $this->assertSame($countafterfirst, $DB->count_records('tool_imageoptimizer_files'));
    }

    public function test_execute_records_owner_and_keeps_file_ownership(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 1, 'tool_imageoptimizer');
        set_config('maxwidth', 1920, 'tool_imageoptimizer');
        set_config('maxheight', 1080, 'tool_imageoptimizer');
        set_config('quality', 80, 'tool_imageoptimizer');
        set_config('targetformat', 'jpeg', 'tool_imageoptimizer');

        $user = self::getDataGenerator()->create_user();
        $fs = get_file_storage();
        $context = \context_user::instance($user->id);
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
            'userid'    => $user->id,
            'author'    => 'Jane Author',
            'license'   => 'cc-4.0',
            'source'    => 'photo.png',
            'timecreated' => 1000000000,
        ];
        $original = $fs->create_file_from_string($filerecord, $this->make_png(2000, 1500));
        $originalsize = (int) $original->get_filesize();

        $task = new process_images();
        $task->execute();

        $optimized = $fs->get_file($context->id, 'tool_imageoptimizer_test', 'test', 0, '/', 'photo.png');
        $this->assertNotFalse($optimized);
        $this->assertLessThan($originalsize, (int) $optimized->get_filesize());

        // The replacement keeps the original owner and attribution.
        $this->assertEquals($user->id, $optimized->get_userid());
        $this->assertSame('Jane Author', $optimized->get_author());
        $this->assertSame('cc-4.0', $optimized->get_license());
        $this->assertSame('photo.png', $optimized->get_source());
        $this->assertEquals(1000000000, $optimized->get_timecreated());

        // The tracking row stores the owner, for the privacy provider.
        $tracking = $DB->get_record('tool_imageoptimizer_files', ['pathnamehash' => $optimized->get_pathnamehash()]);
        $this->assertNotFalse($tracking);
        $this->assertEquals($user->id, $tracking->userid);
    }

    public function test_execute_records_owner_of_files_that_do_not_shrink(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 0, 'tool_imageoptimizer');
        set_config('maxwidth', 4000, 'tool_imageoptimizer');
        set_config('maxheight', 4000, 'tool_imageoptimizer');
        set_config('quality', 80, 'tool_imageoptimizer');
        set_config('targetformat', 'jpeg', 'tool_imageoptimizer');

        $user = self::getDataGenerator()->create_user();
        $fs = get_file_storage();
        $context = \context_user::instance($user->id);
        $original = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'solid.png',
            'userid'    => $user->id,
        ], $this->make_solid_png(60, 60));

        $task = new process_images();
        $task->execute();

        $tracking = $DB->get_record('tool_imageoptimizer_files', ['pathnamehash' => $original->get_pathnamehash()]);
        $this->assertNotFalse($tracking);
        $this->assertEquals($user->id, $tracking->userid);
    }

    public function test_execute_purges_tracking_rows_of_deleted_files(): void {
        global $DB;
        $this->resetAfterTest();

        // Housekeeping must run even while optimization itself is disabled.
        set_config('enabled', 0, 'tool_imageoptimizer');

        $user = self::getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $fs = get_file_storage();
        $base = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'userid'    => $user->id,
        ];
        $kept = $fs->create_file_from_string($base + ['filename' => 'kept.jpg'], 'kept bytes');
        $gone = $fs->create_file_from_string($base + ['filename' => 'IMG_john_smith.jpg'], 'gone bytes');

        $ids = [];
        foreach ([$kept, $gone] as $file) {
            $ids[$file->get_filename()] = $DB->insert_record('tool_imageoptimizer_files', (object) [
                'pathnamehash'  => $file->get_pathnamehash(),
                'contextid'     => $context->id,
                'component'     => 'tool_imageoptimizer_test',
                'filearea'      => 'test',
                'itemid'        => 0,
                'userid'        => $user->id,
                'filename'      => $file->get_filename(),
                'mimetype'      => 'image/jpeg',
                'originalsize'  => 1000,
                'optimizedsize' => 100,
                'timeprocessed' => time(),
            ]);
        }

        // The user deletes one file; core removes its {files} row only.
        $gone->delete();
        $this->assertTrue($DB->record_exists('tool_imageoptimizer_files', ['id' => $ids['IMG_john_smith.jpg']]));

        $task = new process_images();
        $task->execute();

        $this->assertFalse($DB->record_exists('tool_imageoptimizer_files', ['id' => $ids['IMG_john_smith.jpg']]));
        $this->assertTrue($DB->record_exists('tool_imageoptimizer_files', ['id' => $ids['kept.jpg']]));
    }

    /**
     * Build a GIF89a with the given number of 1x1 frames.
     *
     * @param int $frames
     * @return string raw GIF bytes
     */
    private function make_gif(int $frames): string {
        // Header, logical screen descriptor (1x1, 2-colour global table), table.
        $gif = "GIF89a" . "\x01\x00\x01\x00\x80\x00\x00" . "\x00\x00\x00\xff\xff\xff";
        for ($i = 0; $i < $frames; $i++) {
            // Graphic control extension (10/100 s delay), image descriptor,
            // LZW minimum code size 2 and one data sub-block.
            $gif .= "\x21\xF9\x04\x00\x0A\x00\x00\x00";
            $gif .= "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00";
            $gif .= "\x02\x02\x44\x01\x00";
        }
        return $gif . "\x3B";
    }

    /**
     * Base file record used by the tests below.
     *
     * @param string $filename
     * @param string $mimetype
     * @return array
     */
    private function file_record(string $filename, string $mimetype): array {
        return [
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
            'mimetype'  => $mimetype,
        ];
    }

    public function test_is_multi_frame_detects_animated_gif(): void {
        $this->assertTrue(process_images::is_multi_frame($this->make_gif(2), IMAGETYPE_GIF));
        $this->assertFalse(process_images::is_multi_frame($this->make_gif(1), IMAGETYPE_GIF));
    }

    public function test_is_multi_frame_detects_apng_and_animated_webp(): void {
        $this->assertFalse(process_images::is_multi_frame($this->make_solid_png(10, 10), IMAGETYPE_PNG));
        $this->assertTrue(process_images::is_multi_frame(
            "\x89PNG\r\n\x1a\n" . pack('N', 8) . 'acTL' . pack('NN', 2, 0),
            IMAGETYPE_PNG
        ));
        $this->assertTrue(process_images::is_multi_frame('RIFF....WEBPVP8X....ANIM....', IMAGETYPE_WEBP));
        $this->assertFalse(process_images::is_multi_frame('RIFF....WEBPVP8 ....', IMAGETYPE_WEBP));
    }

    public function test_execute_leaves_animated_gif_unchanged(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 0, 'tool_imageoptimizer');
        set_config('targetformat', 'jpeg', 'tool_imageoptimizer');

        $fs = get_file_storage();
        $content = $this->make_gif(2);
        $original = $fs->create_file_from_string($this->file_record('anim.gif', 'image/gif'), $content);
        $contenthash = $original->get_contenthash();

        $task = new process_images();
        $task->execute();

        // Re-encoding would keep only one frame, so the animation must be left
        // exactly as uploaded...
        $after = $fs->get_file($original->get_contextid(), 'tool_imageoptimizer_test', 'test', 0, '/', 'anim.gif');
        $this->assertNotFalse($after);
        $this->assertSame($contenthash, $after->get_contenthash());
        $this->assertSame('image/gif', $after->get_mimetype());

        // ...and recorded as processed so it is not re-read every run.
        $tracking = $DB->get_record('tool_imageoptimizer_files', ['pathnamehash' => $after->get_pathnamehash()]);
        $this->assertNotFalse($tracking);
        $this->assertSame((int) $tracking->originalsize, (int) $tracking->optimizedsize);
    }

    public function test_execute_ignores_formats_outside_the_allowlist(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 0, 'tool_imageoptimizer');
        set_config('targetformat', 'jpeg', 'tool_imageoptimizer');

        $image = imagecreatetruecolor(400, 300);
        ob_start();
        imagebmp($image, null, false);
        $bmp = ob_get_clean();
        imagedestroy($image);

        $fs = get_file_storage();
        // A BMP stored as such is never selected.
        $stored = $fs->create_file_from_string($this->file_record('picture.bmp', 'image/bmp'), $bmp);
        // BMP bytes behind an allowed mimetype are sniffed, recorded and skipped.
        $disguised = $fs->create_file_from_string($this->file_record('picture.png', 'image/png'), $bmp);

        $task = new process_images();
        $task->execute();

        $this->assertFalse($DB->record_exists('tool_imageoptimizer_files', ['pathnamehash' => $stored->get_pathnamehash()]));
        $after = $fs->get_file($stored->get_contextid(), 'tool_imageoptimizer_test', 'test', 0, '/', 'picture.bmp');
        $this->assertSame($stored->get_contenthash(), $after->get_contenthash());

        $after = $fs->get_file($disguised->get_contextid(), 'tool_imageoptimizer_test', 'test', 0, '/', 'picture.png');
        $this->assertSame($disguised->get_contenthash(), $after->get_contenthash());
        $tracking = $DB->get_record('tool_imageoptimizer_files', ['pathnamehash' => $disguised->get_pathnamehash()]);
        $this->assertNotFalse($tracking);
        $this->assertSame((int) $tracking->originalsize, (int) $tracking->optimizedsize);
    }

    public function test_execute_leaves_aliases_and_their_targets_intact(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/repository/lib.php');
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimizer');
        set_config('minsizekb', 1, 'tool_imageoptimizer');
        set_config('maxwidth', 1920, 'tool_imageoptimizer');
        set_config('maxheight', 1080, 'tool_imageoptimizer');
        set_config('targetformat', 'jpeg', 'tool_imageoptimizer');

        $user = self::getDataGenerator()->create_user();
        $this->setUser($user);
        $usercontext = \context_user::instance($user->id);
        $syscontext = \context_system::instance();

        // Same set-up as core's file_storage_test::test_create_file_from_reference().
        $guestroleid = $DB->get_field('role', 'id', ['shortname' => 'guest']);
        assign_capability('repository/user:view', CAP_ALLOW, $guestroleid, $syscontext->id, true);
        $repos = \repository::get_instances(['type' => 'user']);
        $userrepository = reset($repos);
        $this->assertInstanceOf(\repository::class, $userrepository);

        $fs = get_file_storage();
        $targetrecord = [
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea'  => 'private',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
            'userid'    => $user->id,
        ];
        $target = $fs->create_file_from_string($targetrecord, $this->make_png(2000, 1500));
        $alias = $fs->create_file_from_reference([
            'contextid' => $syscontext->id,
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
        ], $userrepository->id, $fs->pack_reference($targetrecord));
        $this->assertEquals(1, $fs->get_references_count_by_storedfile($target));

        $task = new process_images();
        $task->execute();

        // The target has an alias, so it is recorded but not replaced...
        $targetafter = $fs->get_file($usercontext->id, 'user', 'private', 0, '/', 'photo.png');
        $this->assertSame($target->get_contenthash(), $targetafter->get_contenthash());
        $this->assertTrue($DB->record_exists('tool_imageoptimizer_files', ['pathnamehash' => $target->get_pathnamehash()]));

        // ...the alias is never selected, and is still an alias.
        $aliasafter = $fs->get_file($syscontext->id, 'tool_imageoptimizer_test', 'test', 0, '/', 'photo.png');
        $this->assertTrue($aliasafter->is_external_file());
        $this->assertEquals($alias->get_id(), $aliasafter->get_id());
        $this->assertFalse($DB->record_exists('tool_imageoptimizer_files', ['pathnamehash' => $alias->get_pathnamehash()]));
        $this->assertEquals(1, $fs->get_references_count_by_storedfile($targetafter));
    }
}
