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
 * Tests for the tool_imageoptimize\task\process_images scheduled task.
 *
 * @package    tool_imageoptimize
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imageoptimize\task;

/**
 * Tests for process_images.
 *
 * @covers \tool_imageoptimize\task\process_images
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

    public function test_resolve_target_format_keep_falls_back_to_jpeg(): void {
        $task = new process_images();
        $result = $this->call_protected($task, 'resolve_target_format', ['image/gif', 'keep']);
        $this->assertSame(['format' => 'jpeg', 'mimetype' => 'image/jpeg'], $result);
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

        set_config('enabled', 0, 'tool_imageoptimize');
        set_config('minsizekb', 1, 'tool_imageoptimize');

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimize_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
        ];
        $fs->create_file_from_string($filerecord, $this->make_png(2000, 1500));

        $task = new process_images();
        $task->execute();

        $this->assertSame(0, $DB->count_records('tool_imageoptimize_files'));
    }

    public function test_execute_skips_images_with_oversized_declared_dimensions(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimize');
        set_config('minsizekb', 0, 'tool_imageoptimize');

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
            'component' => 'tool_imageoptimize_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'bomb.png',
        ];
        $fs->create_file_from_string($filerecord, $content);

        $task = new process_images();
        $task->execute();

        $this->assertSame(0, $DB->count_records('tool_imageoptimize_files'));
        $unchanged = $fs->get_file($context->id, 'tool_imageoptimize_test', 'test', 0, '/', 'bomb.png');
        $this->assertNotFalse($unchanged);
        $this->assertSame(strlen($content), (int) $unchanged->get_filesize());
    }

    public function test_execute_skips_files_under_threshold(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimize');
        set_config('minsizekb', 100000, 'tool_imageoptimize');

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimize_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
        ];
        $fs->create_file_from_string($filerecord, $this->make_png(2000, 1500));

        $task = new process_images();
        $task->execute();

        $this->assertSame(0, $DB->count_records('tool_imageoptimize_files'));
    }

    public function test_execute_optimizes_file_and_preserves_filename(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimize');
        set_config('minsizekb', 1, 'tool_imageoptimize');
        set_config('maxwidth', 1920, 'tool_imageoptimize');
        set_config('maxheight', 1080, 'tool_imageoptimize');
        set_config('quality', 80, 'tool_imageoptimize');
        set_config('targetformat', 'jpeg', 'tool_imageoptimize');

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimize_test',
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
        $optimized = $fs->get_file($context->id, 'tool_imageoptimize_test', 'test', 0, '/', 'photo.png');
        $this->assertNotFalse($optimized);
        $this->assertSame('photo.png', $optimized->get_filename());
        $this->assertLessThan($originalsize, $optimized->get_filesize());

        // The mimetype must reflect the actual re-encoded content, not the
        // (unchanged) file extension.
        $this->assertSame('image/jpeg', $optimized->get_mimetype());
        $info = getimagesizefromstring($optimized->get_content());
        $this->assertSame('image/jpeg', $info['mime']);

        $tracking = $DB->get_record('tool_imageoptimize_files', ['pathnamehash' => $optimized->get_pathnamehash()]);
        $this->assertNotFalse($tracking);
        $this->assertSame('photo.png', $tracking->filename);
        $this->assertSame((int) $originalsize, (int) $tracking->originalsize);
        $this->assertSame((int) $optimized->get_filesize(), (int) $tracking->optimizedsize);
    }

    public function test_execute_does_not_reprocess_tracked_files(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('enabled', 1, 'tool_imageoptimize');
        set_config('minsizekb', 1, 'tool_imageoptimize');
        set_config('targetformat', 'keep', 'tool_imageoptimize');

        $fs = get_file_storage();
        $context = \context_system::instance();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimize_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'photo.png',
        ];
        $fs->create_file_from_string($filerecord, $this->make_png(2000, 1500));

        $task = new process_images();
        $task->execute();
        $this->assertSame(1, $DB->count_records('tool_imageoptimize_files'));

        // Running again must not reprocess the already-optimized file.
        $task->execute();
        $this->assertSame(1, $DB->count_records('tool_imageoptimize_files'));
    }
}
