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
 * Admin settings for tool_imageoptimize.
 *
 * @package    tool_imageoptimize
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('tool_imageoptimize_settings', get_string('pluginname', 'tool_imageoptimize'));
    $ADMIN->add('tools', $settings);

    $settings->add(new admin_setting_configcheckbox(
        'tool_imageoptimize/enabled',
        get_string('settings:enabled', 'tool_imageoptimize'),
        get_string('settings:enabled_desc', 'tool_imageoptimize'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'tool_imageoptimize/minsizekb',
        get_string('settings:minsizekb', 'tool_imageoptimize'),
        get_string('settings:minsizekb_desc', 'tool_imageoptimize'),
        500,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'tool_imageoptimize/maxwidth',
        get_string('settings:maxwidth', 'tool_imageoptimize'),
        get_string('settings:maxwidth_desc', 'tool_imageoptimize'),
        1920,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'tool_imageoptimize/maxheight',
        get_string('settings:maxheight', 'tool_imageoptimize'),
        get_string('settings:maxheight_desc', 'tool_imageoptimize'),
        1080,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'tool_imageoptimize/quality',
        get_string('settings:quality', 'tool_imageoptimize'),
        get_string('settings:quality_desc', 'tool_imageoptimize'),
        80,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'tool_imageoptimize/targetformat',
        get_string('settings:targetformat', 'tool_imageoptimize'),
        get_string('settings:targetformat_desc', 'tool_imageoptimize'),
        'keep',
        [
            'keep' => get_string('format:keep', 'tool_imageoptimize'),
            'jpeg' => get_string('format:jpeg', 'tool_imageoptimize'),
            'webp' => get_string('format:webp', 'tool_imageoptimize'),
        ]
    ));
}
