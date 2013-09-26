<?php
define('CLI_SCRIPT', true);

require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once($CFG->libdir.'/clilib.php');      // cli only functions
//require_once($CFG->libdir.'/cronlib.php');      // cli only functions
require_once($CFG->dirroot.'/blocks/panopto/lib/panopto_data.php');

list($options, $unrecognized) = cli_get_params(
    array(
        'help'       => false
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
"Command line: Upgrade

-h, --help      Print out this help

Example:
\$sudo -u apache /usr/bin/php blocks/panopto/cli/upgrade.php
"; //TODO: localize - to be translated later when everything is finished
    echo $help;
    die;
}


require_once($CFG->dirroot . '/blocks/panopto/lib/panopto_data.php');

cron_setup_user(); // need to emulate admin user

$instancename = isset($CFG->block_panopto_instance_name) ?
                      $CFG->block_panopto_instance_name :
                      false;

if (!$instancename) {
    mtrace('No instance setup');
    exit;
}

$records = $DB->get_records('block_panopto_foldermap');
foreach ($records as $record) {
    $panopto = new panopto_data($record->courseid);
    mtrace("getting course for $record->courseid");
    $panoptofolder = $panopto->get_course();
    if ($panoptofolder->ExternalCourseID) {
        $courseid = str_replace($instancename.':', '', $panoptofolder->ExternalCourseID);
        if ($courseid != $record->courseid) {
            mtrace("courseid $record->courseid linked panopto folder from courseid $courseid, setting linkedfolderid");
            $DB->set_field('block_panopto_foldermap', 'linkedfolderid', $panoptofolder->PublicID,
                           array('courseid'=>$record->courseid));
        }
    }
}
exit;
