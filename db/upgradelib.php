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
 * Upgrade helpers for tool_imageoptimizer.
 *
 * @package    tool_imageoptimizer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Prepare existing tracking rows for the userid column.
 *
 * First deletes every tracking row whose file no longer exists in {files}:
 * such a row can no longer be tied to its owner, serves no purpose, and would
 * otherwise keep a user-chosen filename forever. Then copies the file owner
 * from {files}.userid into each remaining row. Files optimized by earlier
 * releases were re-created without an owner, so for those the owner of a user
 * context (private files, draft files) is used as a fallback.
 */
function tool_imageoptimizer_upgrade_backfill_userids(): void {
    global $DB;

    \tool_imageoptimizer\task\process_images::purge_orphaned_records();

    $rs = $DB->get_recordset_sql(
        "SELECT o.id, f.userid
           FROM {tool_imageoptimizer_files} o
           JOIN {files} f ON f.pathnamehash = o.pathnamehash
          WHERE o.userid IS NULL
            AND f.userid IS NOT NULL"
    );
    foreach ($rs as $record) {
        $DB->set_field('tool_imageoptimizer_files', 'userid', $record->userid, ['id' => $record->id]);
    }
    $rs->close();

    $rs = $DB->get_recordset_sql(
        "SELECT o.id, ctx.instanceid AS userid
           FROM {tool_imageoptimizer_files} o
           JOIN {context} ctx ON ctx.id = o.contextid
          WHERE o.userid IS NULL
            AND ctx.contextlevel = :contextlevel",
        ['contextlevel' => CONTEXT_USER]
    );
    foreach ($rs as $record) {
        $DB->set_field('tool_imageoptimizer_files', 'userid', $record->userid, ['id' => $record->id]);
    }
    $rs->close();
}
