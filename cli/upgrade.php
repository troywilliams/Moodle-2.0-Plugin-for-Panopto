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
require(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->libdir.'/cronlib.php');
require_once($CFG->dirroot.'/blocks/panopto/lib/panopto_data.php');

// We may need a lot of memory here.
core_php_time_limit::raise();
raise_memory_limit(MEMORY_HUGE);

// Now get cli options.
list($options, $unrecognized) = cli_get_params(
    array(
        'non-interactive'   => false,
        'help'              => false,
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
    $help = "
Options:
--non-interactive     No interactive questions or confirmations
-h, --help            Print out this help

Example:
\$sudo -u www-data /usr/bin/php upgrade.php
";
    echo $help;
    die;
}

// Ensure errors are well explained.
set_debugging(DEBUG_DEVELOPER, true);

$interactive = empty($options['non-interactive']);
if ($interactive) {
    $prompt = "Upgrade panopto data to version 2017082900? type y (means yes) or n (means no)";
    $input = cli_input($prompt, '', array('n', 'y'));
    if ($input == 'n') {
        mtrace('exited');
        exit;
    }
}

cron_setup_user();

$failcount = 0;
$dbman = $DB->get_manager();

// Get all active courses mapped to Panopto.
$oldpanoptocourses = $DB->get_records(
    'block_panopto_foldermap',
    null,
    null,
    'moodleid'
);

$currindex = 0;
$totalupgradesteps = count($oldpanoptocourses);
$upgradestep = "Verifying Permission";
block_panopto_update_upgrade_progress($currindex, $totalupgradesteps, $upgradestep);

$panoptocourseobjects = array();

$getunamepanopto = new panopto_data(null);
$errorstring = get_string('upgrade_provision_access_error', 'block_panopto', $getunamepanopto->panopto_decorate_username($getunamepanopto->uname));
$versionerrorstring = get_string('upgrade_panopto_required_version', 'block_panopto');
$usercanupgrade = true;

foreach ($oldpanoptocourses as $oldcourse) {
    ++$currindex;
    block_panopto_update_upgrade_progress($currindex, $totalupgradesteps);

    $oldpanoptocourse = new stdClass;
    $oldpanoptocourse->panopto = new panopto_data($oldcourse->moodleid);

    $existingmoodlecourse = $DB->get_record('course', array('id' => $oldcourse->moodleid));

    $moodlecourseexists = isset($existingmoodlecourse) && $existingmoodlecourse !== false;
    $hasvalidpanoptodata = isset($oldpanoptocourse->panopto->servername) && !empty($oldpanoptocourse->panopto->servername) &&
        isset($oldpanoptocourse->panopto->applicationkey) && !empty($oldpanoptocourse->panopto->applicationkey);

    if ($moodlecourseexists && $hasvalidpanoptodata) {
        if (isset($oldpanoptocourse->panopto->uname) && !empty($oldpanoptocourse->panopto->uname)) {
            $oldpanoptocourse->panopto->ensure_auth_manager();
            $activepanoptoserverversion = $oldpanoptocourse->panopto->authmanager->get_server_version();
            if (!version_compare($activepanoptoserverversion, \panopto_data::$requiredpanoptoversion, '>=')) {
                echo "<div class='alert alert-error alert-block'>" .
                    "<strong>Panopto ClientData(old) to Public API(new) Upgrade Error - Panopto Server requires newer version</strong>" .
                    "<br/>" .
                    "<p>" . $versionerrorstring . "</p><br/>" .
                    "<p>Impacted server: " . $oldpanoptocourse->panopto->servername . "</p>" .
                    "<p>Minimum required version: " . \panopto_data::$requiredpanoptoversion . "</p>" .
                    "<p>Current version: " . $activepanoptoserverversion . "</p>" .
                    "</div>";
                return false;
            }
        } else {
            echo "<div class='alert alert-error alert-block'>" .
                "<strong>Panopto ClientData(old) to Public API(new) Upgrade Error - Not valid user</strong>" .
                "<br/>" .
                $errorstring .
                "</div>";
            return false;
        }
    } else {
        // Shouldn't hit this case, but in the case a row in the DB has invalid data move it to the old_foldermap.
        panopto_data::print_log(get_string('removing_corrupt_folder_row', 'block_panopto') . $oldcourse->moodleid);
        panopto_data::delete_panopto_relation($oldcourse->moodleid, true);
        // Continue to the next entry assuming this one was cleanup.
        continue;
    }

    $oldpanoptocourse->provisioninginfo = $oldpanoptocourse->panopto->get_provisioning_info();
    if (isset($oldpanoptocourse->provisioninginfo->accesserror) &&
        $oldpanoptocourse->provisioninginfo->accesserror === true) {
        mtrace('Panopto folder access error, removing mapping');
        $failcount++;
        panopto_data::delete_panopto_relation($oldcourse->moodleid, false);
        continue;
        //$usercanupgrade = false;
        //break;
    } else {
        if (isset($oldpanoptocourse->provisioninginfo->couldnotfindmappedfolder) &&
            $oldpanoptocourse->provisioninginfo->couldnotfindmappedfolder === true) {
            // Course was mapped to a folder but that folder was not found, most likely folder was deleted on Panopto side.
            // The true parameter moves the row to the old_foldermap instead of deleting it.
            panopto_data::delete_panopto_relation($oldcourse->moodleid, true);

            //Recreate the default role mappings that were deleted by the above line.
            $oldpanoptocourse->panopto->check_course_role_mappings();

            // Imports SHOULD still work for this case, so continue to below code.
        }
        $courseimports = panopto_data::get_import_list($oldpanoptocourse->panopto->moodlecourseid);
        foreach ($courseimports as $courseimport) {
            $importpanopto = new panopto_data($courseimport);


            $existingmoodlecourse = $DB->get_record('course', array('id' => $courseimport));

            $moodlecourseexists = isset($existingmoodlecourse) && $existingmoodlecourse !== false;
            $hasvalidpanoptodata = isset($importpanopto->servername) && !empty($importpanopto->servername) &&
                isset($importpanopto->applicationkey) && !empty($importpanopto->applicationkey);

            // Only perform the actions below if the import is in a valid state, otherwise remove it.
            if ($moodlecourseexists && $hasvalidpanoptodata) {
                // False means the user failed to get the folder.
                $importpanoptofolder = $importpanopto->get_folders_by_id();
                if (isset($importpanoptofolder) && $importpanoptofolder === false) {
                    mtrace('Panopto folder access error, removing mapping');
                    $failcount++;
                    panopto_data::delete_panopto_relation($oldcourse->moodleid, false);
                    continue;
                    //$usercanupgrade = false;
                    //break;
                } else if (!isset($importpanoptofolder) || $importpanoptofolder === -1) {
                    // In this case the folder was not found, not an access issue. Most likely the folder was deleted and this is an old entry.
                    // Move the entry to the old_foldermap so user still has a reference.
                    panopto_data::delete_panopto_relation($courseimport, true);
                    // We can still continue on with the upgrade, assume this was an old entry that was deleted from Panopto side.
                }
            } else {
                panopto_data::print_log(get_string('removing_corrupt_folder_row', 'block_panopto') . $courseimport);
                panopto_data::delete_panopto_relation($courseimport, true);
                // Continue to the next entry assuming this one was cleanup.
                continue;
            }
        }
    }
    $panoptocourseobjects[] = $oldpanoptocourse;
}

if (!$usercanupgrade) {
    echo "<div class='alert alert-error alert-block'>" .
        "<strong>Panopto ClientData(old) to Public API(new) Upgrade Error - Lacking Folder Access</strong>" .
        "<br/>" .
        $errorstring .
        "</div>";
    return false;
}

$upgradestep = "Upgrading Provisioned courses";
$currindex = 0;
$totalupgradesteps = count($panoptocourseobjects);
block_panopto_update_upgrade_progress($currindex, $totalupgradesteps, $upgradestep);
foreach ($panoptocourseobjects as $mappablecourse) {
    // This should add the required groups to the existing Panopto folder.
    $mappablecourse->panopto->provision_course($mappablecourse->provisioninginfo);
    $courseimports = panopto_data::get_import_list($mappablecourse->panopto->moodlecourseid);
    foreach ($courseimports as $importedcourse) {
        $mappablecourse->panopto->init_and_sync_import($importedcourse);
    }

    ++$currindex;
    block_panopto_update_upgrade_progress($currindex, $totalupgradesteps);
}

function block_panopto_update_upgrade_progress($currentprogress, $totalitems, $progressstep = null) {
    if (isset($progressstep) && !empty($progressstep)) {
        panopto_data::print_log('Now beginning the step: ' . $progressstep);
    }

    if ($currentprogress > 0) {
        panopto_data::print_log('Processing folder ' . $currentprogress . ' out of ' . $totalitems);
    }
}
mtrace('Fail folder verfication count: ' . $failcount);
mtrace(userdate(time()));
exit(0);
