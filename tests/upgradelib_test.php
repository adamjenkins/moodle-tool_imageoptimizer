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
 * Tests for the tool_imageoptimizer upgrade helpers.
 *
 * @package    tool_imageoptimizer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imageoptimizer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/admin/tool/imageoptimizer/db/upgradelib.php');

/**
 * Tests for tool_imageoptimizer_upgrade_backfill_userids().
 *
 * @covers ::tool_imageoptimizer_upgrade_backfill_userids
 */
final class upgradelib_test extends \advanced_testcase {
    /**
     * Insert a tracking row without an owner, as rows written before the userid column existed.
     *
     * @param string $pathnamehash
     * @param int $contextid
     * @param string $filename
     * @return int the new row id
     */
    private function insert_legacy_row(string $pathnamehash, int $contextid, string $filename): int {
        global $DB;

        return $DB->insert_record('tool_imageoptimizer_files', (object) [
            'pathnamehash'  => $pathnamehash,
            'contextid'     => $contextid,
            'component'     => 'tool_imageoptimizer_test',
            'filearea'      => 'test',
            'itemid'        => 0,
            'userid'        => null,
            'filename'      => $filename,
            'mimetype'      => 'image/jpeg',
            'originalsize'  => 1000,
            'optimizedsize' => 100,
            'timeprocessed' => time(),
        ]);
    }

    public function test_backfill_userids(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = self::getDataGenerator()->create_user();
        $privateowner = self::getDataGenerator()->create_user();
        $fs = get_file_storage();
        $systemcontext = \context_system::instance();
        $usercontext = \context_user::instance($privateowner->id);
        $base = [
            'component' => 'tool_imageoptimizer_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
        ];

        // A live file with an owner in {files}.
        $owned = $fs->create_file_from_string(
            $base + ['contextid' => $systemcontext->id, 'filename' => 'owned.jpg', 'userid' => $owner->id],
            'owned bytes'
        );
        $ownedid = $this->insert_legacy_row($owned->get_pathnamehash(), $systemcontext->id, 'owned.jpg');

        // A live file that lost its owner (optimized by an earlier release), in a user context.
        $ownerless = $fs->create_file_from_string(
            $base + ['contextid' => $usercontext->id, 'filename' => 'private.jpg'],
            'private bytes'
        );
        $ownerlessid = $this->insert_legacy_row($ownerless->get_pathnamehash(), $usercontext->id, 'private.jpg');

        // A live ownerless file outside any user context: nobody to attribute it to.
        $anonymous = $fs->create_file_from_string(
            $base + ['contextid' => $systemcontext->id, 'filename' => 'anonymous.jpg'],
            'anonymous bytes'
        );
        $anonymousid = $this->insert_legacy_row($anonymous->get_pathnamehash(), $systemcontext->id, 'anonymous.jpg');

        // A row whose file was deleted long ago.
        $orphanid = $this->insert_legacy_row(sha1('deleted file'), $systemcontext->id, 'IMG_john_smith.jpg');

        \tool_imageoptimizer_upgrade_backfill_userids();

        $this->assertEquals($owner->id, $DB->get_field('tool_imageoptimizer_files', 'userid', ['id' => $ownedid]));
        $this->assertEquals($privateowner->id, $DB->get_field('tool_imageoptimizer_files', 'userid', ['id' => $ownerlessid]));
        $this->assertTrue($DB->record_exists('tool_imageoptimizer_files', ['id' => $anonymousid]));
        $this->assertNull($DB->get_field('tool_imageoptimizer_files', 'userid', ['id' => $anonymousid]));
        $this->assertFalse($DB->record_exists('tool_imageoptimizer_files', ['id' => $orphanid]));
    }
}
