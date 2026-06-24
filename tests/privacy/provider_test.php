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
 * Tests for the tool_imageoptimize privacy provider.
 *
 * @package    tool_imageoptimize
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imageoptimize\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for the privacy provider.
 *
 * @covers \tool_imageoptimize\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Insert a tracking row for a file owned by the given user, in the given context.
     *
     * @param \stdClass $user
     * @param \context $context
     * @param string $filename
     * @return \stdClass the inserted tracking record
     */
    private function create_tracked_file(\stdClass $user, \context $context, string $filename = 'photo.jpg'): \stdClass {
        global $DB;

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'tool_imageoptimize_test',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename,
            'userid'    => $user->id,
        ];
        $file = $fs->create_file_from_string($filerecord, 'fake image bytes for ' . $filename);

        $tracking = (object) [
            'pathnamehash'  => $file->get_pathnamehash(),
            'contextid'     => $context->id,
            'component'     => 'tool_imageoptimize_test',
            'filearea'      => 'test',
            'itemid'        => 0,
            'filename'      => $filename,
            'mimetype'      => 'image/jpeg',
            'originalsize'  => 1000,
            'optimizedsize' => 100,
            'timeprocessed' => time(),
        ];
        $tracking->id = $DB->insert_record('tool_imageoptimize_files', $tracking);
        return $tracking;
    }

    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection('tool_imageoptimize');
        $result = provider::get_metadata($collection);
        $this->assertCount(1, $result->get_collection());
    }

    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();
        $context1 = \context_user::instance($user1->id);
        $context2 = \context_user::instance($user2->id);

        $this->create_tracked_file($user1, $context1);
        $this->create_tracked_file($user2, $context2);

        $contextlist = provider::get_contexts_for_userid($user1->id);
        $contextids = array_map(fn($c) => $c->id, $contextlist->get_contexts());

        $this->assertContains($context1->id, $contextids);
        $this->assertNotContains($context2->id, $contextids);
    }

    public function test_get_users_in_context(): void {
        $this->resetAfterTest();

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();
        $context = \context_system::instance();

        $this->create_tracked_file($user1, $context, 'a.jpg');
        $this->create_tracked_file($user2, $context, 'b.jpg');

        $userlist = new userlist($context, 'tool_imageoptimize');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing([$user1->id, $user2->id], $userlist->get_userids());
    }

    public function test_export_user_data(): void {
        $this->resetAfterTest();

        $user = self::getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        $this->create_tracked_file($user, $context, 'photo.jpg');

        $this->setUser($user);
        $approved = new approved_contextlist($user, 'tool_imageoptimize', [$context->id]);
        provider::export_user_data($approved);

        $exported = writer::with_context($context)->get_data(['tool_imageoptimize']);
        $this->assertNotEmpty($exported);
        $this->assertCount(1, $exported->files);
        $this->assertSame('photo.jpg', $exported->files[0]['filename']);
    }

    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();
        $context = \context_system::instance();

        $this->create_tracked_file($user1, $context, 'a.jpg');
        $this->create_tracked_file($user2, $context, 'b.jpg');

        provider::delete_data_for_all_users_in_context($context);

        $this->assertSame(0, $DB->count_records('tool_imageoptimize_files', ['contextid' => $context->id]));
    }

    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();
        $context1 = \context_user::instance($user1->id);
        $context2 = \context_user::instance($user2->id);

        $tracking1 = $this->create_tracked_file($user1, $context1);
        $this->create_tracked_file($user2, $context2);

        $approved = new approved_contextlist($user1, 'tool_imageoptimize', [$context1->id]);
        provider::delete_data_for_user($approved);

        $this->assertFalse($DB->record_exists('tool_imageoptimize_files', ['id' => $tracking1->id]));
        $this->assertSame(1, $DB->count_records('tool_imageoptimize_files'));
    }

    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();

        $user1 = self::getDataGenerator()->create_user();
        $user2 = self::getDataGenerator()->create_user();
        $context = \context_system::instance();

        $tracking1 = $this->create_tracked_file($user1, $context, 'a.jpg');
        $tracking2 = $this->create_tracked_file($user2, $context, 'b.jpg');

        $approved = new approved_userlist($context, 'tool_imageoptimize', [$user1->id]);
        provider::delete_data_for_users($approved);

        $this->assertFalse($DB->record_exists('tool_imageoptimize_files', ['id' => $tracking1->id]));
        $this->assertTrue($DB->record_exists('tool_imageoptimize_files', ['id' => $tracking2->id]));
    }
}
