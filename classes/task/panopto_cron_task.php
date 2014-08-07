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

namespace block_panopto\task;

/**
 * Simple task to run the cron.
 */
class panopto_cron_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('panoptocron', 'block_panopto');
    }

    /**
     * Do the job.
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {
        global $CFG, $DB;

        require_once($CFG->dirroot.'/blocks/moodleblock.class.php');
        require_once($CFG->dirroot.'/blocks/panopto/block_panopto.php');
        
        $block = new \block_panopto();
        $trace = new \progress_trace_buffer(new \text_progress_trace(), true); // output and buffer
        $block->sync_users(null, false, $trace);
        $messagetext = $trace->get_buffer();
        email_to_user(get_admin(), get_admin(), 'Panopto cron notification', $messagetext);
        
        return true;
        
       
    }

}
