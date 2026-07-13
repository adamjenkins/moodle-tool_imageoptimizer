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
 * Language strings for tool_imageoptimizer.
 *
 * @package    tool_imageoptimizer
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['format:jpeg'] = 'JPEG';
$string['format:keep'] = 'Keep original';
$string['format:webp'] = 'WEBP';
$string['pluginname'] = 'Image optimizer';
$string['privacy:metadata:tool_imageoptimizer_files'] = 'Records of files that have been processed by the image optimizer.';
$string['privacy:metadata:tool_imageoptimizer_files:filename'] = 'The name of the processed file.';
$string['privacy:metadata:tool_imageoptimizer_files:optimizedsize'] = 'The size of the file after optimization.';
$string['privacy:metadata:tool_imageoptimizer_files:originalsize'] = 'The size of the file before optimization.';
$string['privacy:metadata:tool_imageoptimizer_files:pathnamehash'] = 'The path hash identifying the processed file within Moodle\'s file storage.';
$string['privacy:metadata:tool_imageoptimizer_files:timeprocessed'] = 'The time the file was processed.';
$string['settings:enabled'] = 'Enable image optimization';
$string['settings:enabled_desc'] = 'When enabled, the scheduled task will optimize eligible image files.';
$string['settings:maxheight'] = 'Maximum height (px)';
$string['settings:maxheight_desc'] = 'Images taller than this will be resized down to this height, preserving aspect ratio.';
$string['settings:maxwidth'] = 'Maximum width (px)';
$string['settings:maxwidth_desc'] = 'Images wider than this will be resized down to this width, preserving aspect ratio.';
$string['settings:minsizekb'] = 'Minimum file size (KB)';
$string['settings:minsizekb_desc'] = 'Only images larger than this size will be optimized.';
$string['settings:quality'] = 'Compression quality (1-100)';
$string['settings:quality_desc'] = 'Quality used when re-encoding lossy formats such as JPEG or WEBP.';
$string['settings:targetformat'] = 'Target format';
$string['settings:targetformat_desc'] = 'Convert optimized images to this format. "Keep original" preserves the existing format. The filename is never changed, so converting format means the file extension and its actual content may no longer match (this does not affect how the image displays).';
$string['task:processimages'] = 'Optimize uploaded images';
