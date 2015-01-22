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

define('CLI_SCRIPT', true);
define('CACHE_DISABLE_ALL', true);

require(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
require_once($CFG->libdir.'/clilib.php');         // cli only functions
require_once($CFG->libdir.'/clilib.php');      // cli only functions
require_once($CFG->dirroot.'/blocks/moodleblock.class.php');
require_once($CFG->dirroot.'/blocks/panopto/block_panopto.php');

// we may need a lot of memory here
@set_time_limit(0);
raise_memory_limit(MEMORY_HUGE);

// now get cli options
list($options, $unrecognized) = cli_get_params(
    array(
        'help'              => false
    ),
    array(
        'h' => 'help'
    )
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help =
"Command line remove a paper folder map

Please note you must execute this script with the same uid as apache!

Options:
-h, --help            Print out this help

Example:
\$sudo -u apache /usr/bin/php blocks/panopto/cli/removepaperfoldermap.php
";

    echo $help;
    die;
}

$prompt = 'Enter a course identifier';
$courseid = cli_input($prompt);
if (! is_numeric($courseid)){
    exit("Must be an integer!\n");
}
$sql = "SELECT c.shortname, p.*
          FROM {course} c
          JOIN {block_panopto_foldermap} p
            ON p.courseid = c.id
         WHERE c.id = ?";


$record = $DB->get_record_sql($sql, array($courseid));
if (! $record) {
    exit("No panopto record exists!\n");
}

$prompt = "Delete block_panopto_foldermap record for course {$record->shortname} proceed? type y (means yes) or n (means no)";
$input = cli_input($prompt, '', array('n', 'y'));
if ($input == 'y') {
    $DB->delete_records('block_panopto_foldermap', array('courseid' => $courseid));
}
mtrace("Tis done... fullstop");
exit;
