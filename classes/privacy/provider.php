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
 * Privacy provider for tool_imageoptimizer.
 *
 * @package    tool_imageoptimizer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imageoptimizer\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * The tracking table records which files were processed. Each row stores the
 * owner of the file (userid) itself, so a user's rows are found directly and
 * stay reachable even after the underlying file has been deleted from {files}.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata about this plugin's data store.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'tool_imageoptimizer_files',
            [
                'userid'        => 'privacy:metadata:tool_imageoptimizer_files:userid',
                'pathnamehash'  => 'privacy:metadata:tool_imageoptimizer_files:pathnamehash',
                'filename'      => 'privacy:metadata:tool_imageoptimizer_files:filename',
                'originalsize'  => 'privacy:metadata:tool_imageoptimizer_files:originalsize',
                'optimizedsize' => 'privacy:metadata:tool_imageoptimizer_files:optimizedsize',
                'timeprocessed' => 'privacy:metadata:tool_imageoptimizer_files:timeprocessed',
            ],
            'privacy:metadata:tool_imageoptimizer_files'
        );

        return $collection;
    }

    /**
     * Get the list of contexts containing files processed for this user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT o.contextid
                  FROM {tool_imageoptimizer_files} o
                 WHERE o.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        return $contextlist;
    }

    /**
     * Get the list of users who have files processed within the given context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        $sql = "SELECT o.userid
                  FROM {tool_imageoptimizer_files} o
                 WHERE o.contextid = :contextid
                   AND o.userid IS NOT NULL";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);
    }

    /**
     * Export processed-file records for a user within the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $records = $DB->get_records(
                'tool_imageoptimizer_files',
                ['contextid' => $context->id, 'userid' => $userid],
                'id ASC'
            );

            $data = array_map(function ($record) {
                $datetime = \core_privacy\local\request\transform::datetime($record->timeprocessed);
                return [
                    'filename'      => $record->filename,
                    'pathnamehash'  => $record->pathnamehash,
                    'originalsize'  => $record->originalsize,
                    'optimizedsize' => $record->optimizedsize,
                    'timeprocessed' => $datetime,
                ];
            }, array_values($records));

            if (!empty($data)) {
                writer::with_context($context)->export_data(['tool_imageoptimizer'], (object) ['files' => $data]);
            }
        }
    }

    /**
     * Delete all processed-file records within the given context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        $DB->delete_records('tool_imageoptimizer_files', ['contextid' => $context->id]);
    }

    /**
     * Delete processed-file records belonging to a user within the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $DB->delete_records('tool_imageoptimizer_files', ['contextid' => $context->id, 'userid' => $userid]);
        }
    }

    /**
     * Delete processed-file records belonging to a list of users within a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select(
            'tool_imageoptimizer_files',
            "contextid = :contextid AND userid $insql",
            array_merge(['contextid' => $context->id], $inparams)
        );
    }
}
