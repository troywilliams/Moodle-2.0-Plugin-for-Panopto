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
 * Scripts used for upgrading database when upgrading block from an older version
 *
 * @package block_panopto
 * @copyright  Panopto 2009 - 2016 with contributions from Spenser Jones (sjones@ambrose.edu)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Update the upgrade progress bar for Panopto.
 *
 * @param int $currentprogress the current progress that the bar needs to reflect.
 * @param int $totalitems the total number of items in the current step to be processed.
 * @param int $progressstep if set upgrade the progress step to this value.
 */
function update_upgrade_progress($currentprogress, $totalitems, $progressstep = null) {
    if (isset($progressstep) && !empty($progressstep)) {
        panopto_data::print_log('Now beginning the step: ' . $progressstep);
    }

    if ($currentprogress > 0) {
        panopto_data::print_log('Processing folder ' . $currentprogress . ' out of ' . $totalitems);
    }
}

/**
 * Upgrades Panopto for xmldb
 *
 * @param int $oldversion the previous version Panopto is being upgraded from
 */
function xmldb_block_panopto_upgrade($oldversion = 0) {
    global $CFG, $DB, $USER;
    $dbman = $DB->get_manager();

    // UoW - Convert from couture to ready to wear.
    if ($oldversion < 2014080801) {
        // Change config var naming.
        if (isset($CFG->block_panopto_server_name)) {
            set_config('block_panopto_server_name1', $CFG->block_panopto_server_name);
            unset_config('block_panopto_server_name');
        }
        if (isset($CFG->block_panopto_application_key)) {
            set_config('block_panopto_application_key1', $CFG->block_panopto_application_key);
            unset_config('block_panopto_application_key');
        }
        // Main folder map table.
        $table = new xmldb_table('block_panopto_foldermap');
        $courseidfield = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');
        // Rename courseid column back to moodleid.
        if ($dbman->field_exists($table, $courseidfield)) {
            $dbman->rename_field($table, $courseidfield, 'moodleid');
        }
        // Rename folderid column back to panopto_id.
        $panoptofolderidfield = new xmldb_field('folderid', XMLDB_TYPE_CHAR, '36', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        if ($dbman->field_exists($table, $panoptofolderidfield)) {
            $dbman->rename_field($table, $panoptofolderidfield, 'panopto_id');
        }
        // Add panopto_server column.
        $panoptoserverfield = new xmldb_field('panopto_server', XMLDB_TYPE_CHAR, '36', XMLDB_UNSIGNED, true, null, null);
        if (!$dbman->field_exists($table, $panoptoserverfield)) {
            $dbman->add_field($table, $panoptoserverfield);
        }
        // Add panopto_app_key column.
        $panoptoappkeyfield = new xmldb_field('panopto_app_key', XMLDB_TYPE_CHAR, '36', XMLDB_UNSIGNED, true, null, null);
        if (!$dbman->field_exists($table, $panoptoappkeyfield)) {
            $dbman->add_field($table, $panoptoappkeyfield);
        }
        // Migrate existing data.
        $rs = $DB->get_records('block_panopto_foldermap');
        foreach ($rs as $record) {
            if (empty($record->panopto_id) || trim($record->panopto_id) == false) {
                $record->panopto_id = $record->linkedfolderid;
            }
            $record->panopto_server = $CFG->block_panopto_server_name1;
            $record->panopto_app_key = $CFG->block_panopto_application_key1;
            $DB->update_record('block_panopto_foldermap', $record);
        }
        // Drop field.
        $linkedfolderidfield = new xmldb_field('linkedfolderid', XMLDB_TYPE_CHAR, '36', XMLDB_UNSIGNED, true, null, null);
        if ($dbman->field_exists($table, $linkedfolderidfield)) {
            $dbman->drop_field($table, $linkedfolderidfield);
        }
        // Drop syncuserlist field.
        $syncuserlistfield = new xmldb_field('syncuserlist', XMLDB_TYPE_INTEGER, '2', XMLDB_UNSIGNED, true, null, '0');
        if ($dbman->field_exists($table, $syncuserlistfield )) {
            $dbman->drop_field($table, $syncuserlistfield);
        }
        upgrade_block_savepoint(true, 2014080801, 'panopto');
    }

    if ($oldversion < 2014121502) {

        // Add db fields for servername and application key per course.
        if (isset($CFG->block_panopto_server_name)) {
            $oldservername = $CFG->block_panopto_server_name;
        }
        if (isset($CFG->block_panopto_application_key)) {
            $oldappkey = $CFG->block_panopto_application_key;
        }

        // Define field panopto_server to be added to block_panopto_foldermap.
        $table = new xmldb_table('block_panopto_foldermap');
        $field = new xmldb_field('panopto_server', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'panopto_id');

        // Conditionally launch add field panopto_server.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            if (isset($oldservername)) {
                $DB->set_field('block_panopto_foldermap', 'panopto_server', $oldservername, null);
            }
        }

        // Define field panopto_app_key to be added to block_panopto_foldermap.
        $table = new xmldb_table('block_panopto_foldermap');
        $field = new xmldb_field('panopto_app_key', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'panopto_server');

        // Conditionally launch add field panopto_app_key.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            if (isset($oldappkey)) {
                $DB->set_field('block_panopto_foldermap', 'panopto_app_key', $oldappkey, null);
            }
        }

        // Panopto savepoint reached.
        upgrade_block_savepoint(true, 2014121502, 'panopto');
    }

    if ($oldversion < 2015012901) {

        // Define field publisher_mapping to be added to block_panopto_foldermap.
        $table = new xmldb_table('block_panopto_foldermap');
        $field = new xmldb_field('publisher_mapping', XMLDB_TYPE_CHAR, '20', null, null, null, '1', 'panopto_app_key');

        // Conditionally launch add field publisher_mapping.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Define field creator_mapping to be added to block_panopto_foldermap.
        $table = new xmldb_table('block_panopto_foldermap');
        $field = new xmldb_field('creator_mapping', XMLDB_TYPE_CHAR, '20', null, null, null, '3,4', 'publisher_mapping');

        // Conditionally launch add field creator_mapping.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Panopto savepoint reached.
        upgrade_block_savepoint(true, 2015012901, 'panopto');
    }

    if ($oldversion <= 2016101227) {
        // Move block global settings to <prefix>_config_plugin table.
        // First, move each server configuration. We are not relying here on
        // block_panopto_server_number to determine number of servers, as there
        // could be more. Moving all that we will find in order not to leave
        // any abandoned config values in global configuration.
        for ($x = 1; $x <= 10; $x++) {
            if (isset($CFG->{'block_panopto_server_name' . $x})) {
                set_config('server_name' . $x, $CFG->{'block_panopto_server_name' . $x}, 'block_panopto');
                unset_config('block_panopto_server_name' . $x);
            }
            if (isset($CFG->{'block_panopto_application_key' . $x})) {
                set_config('application_key' . $x, $CFG->{'block_panopto_application_key' . $x}, 'block_panopto');
                unset_config('block_panopto_application_key' . $x);
            }
        }
        // Now move block_panopto_server_number setting value.
        if (isset($CFG->block_panopto_server_number)) {
            set_config('server_number', $CFG->block_panopto_server_number, 'block_panopto');
            unset_config('block_panopto_server_number');
        }
        // Move block_panopto_instance_name.
        if (isset($CFG->block_panopto_instance_name)) {
            set_config('instance_name', $CFG->block_panopto_instance_name, 'block_panopto');
            unset_config('block_panopto_instance_name');
        }
        // Move block_panopto_async_tasks.
        if (isset($CFG->block_panopto_async_tasks)) {
            set_config('async_tasks', $CFG->block_panopto_async_tasks, 'block_panopto');
            unset_config('block_panopto_async_tasks');
        }
        // Panopto savepoint reached.
        upgrade_block_savepoint(true, 2016101227, 'panopto');
    }

    if ($oldversion < 2016102709) {
        // Define table importmap where we will place all of our imports.
        $table = new xmldb_table('block_panopto_importmap');

        if (!$dbman->table_exists($table)) {
            $importfields = array();
            $importfields[] = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, true);
            $importfields[] = new xmldb_field('target_moodle_id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);
            $importfields[] = new xmldb_field('import_moodle_id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);

            $importkey = new xmldb_key('primary', XMLDB_KEY_PRIMARY, array('id'), null, null);

            foreach ($importfields as $importfield) {
                // Conditionally launch add field import_moodle_id.
                $table->addField($importfield);
            }

            $table->addKey($importkey);

            $dbman->create_table($table);
        }

        // Panopto savepoint reached.
        upgrade_block_savepoint(true, 2016102709, 'panopto');
    }

    if ($oldversion < 2017031303) {

        // Get the roles using the old method so we can update current customers to the new tables.
        $pubroles = array();
        $creatorroles = array();

         // Get publisher roles as string and explode to array.
        $existingcoursemappings = $DB->get_records(
            'block_panopto_foldermap',
            null,
            'moodleid, publisher_mapping, creator_mapping'
        );

        // Define table table where we will place all of our creator mappings.
        $creatortable = new xmldb_table('block_panopto_creatormap');

        if (!$dbman->table_exists($creatortable)) {
            $mappingfields = array();
            $mappingfields[] = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, true);
            $mappingfields[] = new xmldb_field('moodle_id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);
            $mappingfields[] = new xmldb_field('role_id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);

            $mappingkey = new xmldb_key('primary', XMLDB_KEY_PRIMARY, array('id'), null, null);

            foreach ($mappingfields as $mappingfield) {
                $creatortable->addField($mappingfield);
            }

            $creatortable->addKey($mappingkey);

            $dbman->create_table($creatortable);

            foreach ($existingcoursemappings as $existingmapping) {
                if (isset($existingmapping->creator_mapping) && !empty($existingmapping->creator_mapping)) {
                    $creatorroles = explode(",", $existingmapping->creator_mapping);

                    foreach ($creatorroles as $creatorrole) {
                        $row = (object) array('moodle_id' => $existingmapping->moodleid, 'role_id' => $creatorrole);
                        $DB->insert_record('block_panopto_creatormap', $row);
                    }
                }
            }
        }

        $publishertable = new xmldb_table('block_panopto_publishermap');

        if (!$dbman->table_exists($publishertable)) {
            $mappingfields = array();
            $mappingfields[] = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, true);
            $mappingfields[] = new xmldb_field('moodle_id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);
            $mappingfields[] = new xmldb_field('role_id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL);

            $mappingkey = new xmldb_key('primary', XMLDB_KEY_PRIMARY, array('id'), null, null);

            foreach ($mappingfields as $mappingfield) {
                $publishertable->addField($mappingfield);
            }

            $publishertable->addKey($mappingkey);

            $dbman->create_table($publishertable);

            foreach ($existingcoursemappings as $existingmapping) {
                if (isset($existingmapping->publisher_mapping) && !empty($existingmapping->publisher_mapping)) {
                    $pubroles = explode("," , $existingmapping->publisher_mapping);

                    foreach ($pubroles as $pubrole) {
                        $row = (object) array('moodle_id' => $existingmapping->moodleid, 'role_id' => $pubrole);
                        $DB->insert_record('block_panopto_publishermap', $row);
                    }
                }
            }
        }

        // Panopto savepoint reached.
        upgrade_block_savepoint(true, 2017031303, 'panopto');
    }

    if ($oldversion < 2017061000) {
        // 7200 seconds is 2 hours, this is for larger Moodle instances with a lot of Panopto folders mapped to it.
        upgrade_set_timeout(7200);

        // Get all active courses mapped to Panopto.
        $oldpanoptocourses = $DB->get_records(
            'block_panopto_foldermap',
            null,
            null,
            'moodleid'
        );

        // Define table table where we will place all of our old/broken folder mappings. So customers can keep the data if needed.
        $oldfoldermaptable = new xmldb_table('block_panopto_old_foldermap');
        if (!$dbman->table_exists($oldfoldermaptable)) {
            $mappingfields = array();
            $mappingfields[] = new xmldb_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, true);
            $mappingfields[] = new xmldb_field('moodleid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null, 'id');
            $mappingfields[] = new xmldb_field('panopto_id', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null, 'moodleid');
            $mappingfields[] = new xmldb_field('panopto_server', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'panopto_id');
            $mappingfields[] = new xmldb_field('panopto_app_key', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'panopto_server');
            $mappingfields[] = new xmldb_field('publisher_mapping', XMLDB_TYPE_CHAR, '20', null, null, null, '1', 'panopto_app_key');
            $mappingfields[] = new xmldb_field('creator_mapping', XMLDB_TYPE_CHAR, '20', null, null, null, '3,4', 'publisher_mapping');
            $mappingkey = new xmldb_key('primary', XMLDB_KEY_PRIMARY, array('id'), null, null);
            foreach ($mappingfields as $mappingfield) {
                $oldfoldermaptable->addField($mappingfield);
            }
            $oldfoldermaptable->addKey($mappingkey);
            $dbman->create_table($oldfoldermaptable);
        }

        // UOW - Moved panopto data upgrade code to "cli/upgrade.php".

        // Panopto savepoint reached.
        upgrade_block_savepoint(true, 2017061000, 'panopto');
    }

    return true;
}
