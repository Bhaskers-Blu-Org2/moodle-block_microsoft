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
 * @package block_microsoft
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Microsoft Block.
 */
class block_microsoft extends block_base {
    /**
     * Initialize plugin.
     */
    public function init() {
        $this->title = get_string('microsoft', 'block_microsoft');
    }

    /**
     * Get the content of the block.
     *
     * @return stdObject
     */
    public function get_content() {
        global $PAGE, $DB, $USER;

        if (!isloggedin()) {
            return null;
        }

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new \stdClass;
        $this->content->text = '';
        $this->content->footer = '';

        $o365config = get_config('local_o365');

        try {
            $o365connected = $DB->record_exists('local_o365_token', ['user_id' => $USER->id]);
            if ($o365connected === true) {
                $langconnected = get_string('o365connected', 'block_microsoft');
                $this->content->text .= '<h5>'.$langconnected.'</h5>';
                $outlookurl = new \moodle_url('/local/o365/ucp.php?action=calendar');
                $outlookstr = get_string('linkoutlook', 'block_microsoft');
                $sharepointstr = get_string('linksharepoint', 'block_microsoft');
                $prefsurl = new \moodle_url('/local/o365/ucp.php');
                $prefsstr = get_string('linkprefs', 'block_microsoft');
                $connecturl = new \moodle_url('/local/o365/ucp.php');
                $connectstr = get_string('linkconnection', 'block_microsoft');

                $items = [];

                if ($PAGE->context instanceof \context_course && $PAGE->context->instanceid !== SITEID) {
                    if (!empty($o365config->sharepointlink)) {
                        $courserec = $DB->get_record('course', ['id' => $PAGE->context->instanceid]);
                        if (!empty($courserec)) {
                            $spurl = $o365config->sharepointlink.'/'.$courserec->shortname;
                            $spattrs = ['class' => 'servicelink block_microsoft_sharepoint', 'target' => '_blank'];
                            $items[] = html_writer::link($spurl, $sharepointstr, $spattrs);
                            $items[] = '<hr/>';
                        }
                    }
                }

                $items[] = $this->render_onenote();
                $items[] = \html_writer::link($outlookurl, $outlookstr, ['class' => 'servicelink block_microsoft_outlook']);
                $items[] = \html_writer::link($prefsurl, $prefsstr, ['class' => 'servicelink block_microsoft_preferences']);
                $items[] = \html_writer::link($connecturl, $connectstr, ['class' => 'servicelink block_microsoft_connection']);

                $this->content->text .= \html_writer::alist($items);
            } else {
                $this->content->text .= '<h5>'.get_string('notconnected', 'block_microsoft').'</h5>';

                $connecturl = new \moodle_url('/local/o365/ucp.php');
                $connectstr = 'Connect to Office365';

                $items = [
                    \html_writer::link($connecturl, $connectstr, ['class' => 'servicelink block_microsoft_connection']),
                    $this->render_onenote()
                ];
                $this->content->text .= \html_writer::alist($items);
            }

        } catch (\Exception $e) {
            $this->content->text = $e->getMessage();
        }

        return $this->content;
    }

    /**
     * Get the user's Moodle OneNote Notebook.
     *
     * @param \local_onenote\api\base $onenoteapi A constructed OneNote API to use.
     * @return array Array of information about the user's OneNote notebook used for Moodle.
     */
    protected function get_onenote_notebook(\local_onenote\api\base $onenoteapi) {
        $moodlenotebook = null;
        for ($i = 0; $i < 2; $i++) {
            $notebooks = $onenoteapi->get_items_list('');
            if (!empty($notebooks)) {
                $notebookname = get_string('notebookname', 'block_microsoft');
                foreach ($notebooks as $notebook) {
                    if ($notebook['title'] == $notebookname) {
                        $moodlenotebook = $notebook;
                        break;
                    }
                }
            }
            if (empty($moodlenotebook)) {
                $onenoteapi->sync_notebook_data();
            } else {
                break;
            }
        }
        return $moodlenotebook;
    }

    /**
     * Render OneNote section of the block.
     *
     * @return string HTML for the rendered OneNote section of the block.
     */
    protected function render_onenote() {
        global $USER, $PAGE;
        $action = optional_param('action', '', PARAM_TEXT);
        $onenoteapi = \local_onenote\api\base::getinstance();
        $output = '';
        if ($onenoteapi->is_logged_in()) {
            // Add the "save to onenote" button if we are on an assignment page.
            $onassignpage = ($PAGE->cm && $PAGE->cm->modname == 'assign' && $action == 'editsubmission') ? true : false;
            if ($onassignpage === true && $onenoteapi->is_student($PAGE->cm->id, $USER->id)) {
                $workstr = get_string('workonthis', 'block_microsoft');
                $output .= $onenoteapi->render_action_button($workstr, $PAGE->cm->id).'<br /><br />';
            }
            // Find moodle notebook, create if not found.
            $moodlenotebook = null;

            $cache = \cache::make('block_microsoft', 'onenotenotebook');
            $moodlenotebook = $cache->get($USER->id);
            if (empty($moodlenotebook)) {
                $moodlenotebook = $this->get_onenote_notebook($onenoteapi);
                $result = $cache->set($USER->id, $moodlenotebook);
            }

            if (!empty($moodlenotebook)) {
                $url = new \moodle_url($moodlenotebook['url']);
                $stropennotebook = get_string('linkonenote', 'block_microsoft');
                $linkattrs = [
                    'onclick' => 'window.open(this.href,\'_blank\'); return false;',
                    'class' => 'servicelink block_microsoft_onenote',
                ];
                $output .= \html_writer::link($url->out(false), $stropennotebook, $linkattrs);
            } else {
                $output .= get_string('error_nomoodlenotebook', 'block_microsoft');
            }
        } else {
            $output .= $this->render_signin_widget($onenoteapi->get_login_url());
        }
        return $output;
    }

    /**
     * Get the HTML for the sign in button for an MS account.
     *
     * @return string HTML containing the sign in widget.
     */
    public function render_signin_widget($loginurl) {
        $loginstr = get_string('msalogin', 'block_microsoft');

        $attrs = [
            'onclick' => 'window.open(this.href,\'mywin\',\'left=20,top=20,width=500,height=500,toolbar=1,resizable=0\'); return false;',
            'class' => 'servicelink block_microsoft_msasignin'
        ];
        return \html_writer::link($loginurl, $loginstr, $attrs);
    }
}
