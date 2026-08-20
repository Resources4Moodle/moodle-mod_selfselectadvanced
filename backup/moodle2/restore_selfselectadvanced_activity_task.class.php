<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Restore task for mod_selfselectadvanced (spec 14.11).
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/restore_selfselectadvanced_stepslib.php');

/**
 * The restore task: one structure step plus link decoding.
 */
class restore_selfselectadvanced_activity_task extends restore_activity_task {
    /**
     * No specific settings.
     */
    protected function define_my_settings() {
    }

    /**
     * One structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_selfselectadvanced_activity_structure_step(
            'selfselectadvanced_structure',
            'selfselectadvanced.xml'
        ));
    }

    /**
     * Decodable contents: the intro.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        // Core's transformer encodes wwwroot links in EVERY string
        // written to the archive, so every rich-text field has to be
        // decoded on the way back. Registering only 'intro' left users
        // reading literal $@SELFSELECTADVANCEDVIEWBYID*123@$ tokens in
        // group briefs, message templates, tickets and the rest.
        //
        // Each entry needs its rows mapped under the same item name at
        // restore time - a rule whose item name was never mapped
        // decodes nothing and fails silently - so set_mapping() calls
        // for all of these live in the stepslib.
        return [
            new restore_decode_content('selfselectadvanced', ['intro'], 'selfselectadvanced'),
            new restore_decode_content(
                'selfselectadvanced_group',
                ['brief', 'returncomment', 'guidenotes'],
                'ssagroup'
            ),
            new restore_decode_content('selfselectadvanced_template', ['body'], 'ssatemplate'),
            new restore_decode_content('selfselectadvanced_eoi', ['remarks'], 'ssaeoi'),
            new restore_decode_content('selfselectadvanced_contact', ['message', 'reason'], 'ssacontact'),
            new restore_decode_content('selfselectadvanced_ticket', ['request', 'resolution'], 'ssaticket'),
            new restore_decode_content('selfselectadvanced_move', ['reason', 'responsenote'], 'ssajoinrequest'),
            new restore_decode_content('selfselectadvanced_penalty', ['waivereason'], 'ssapenalty'),
            // Ticketlog (1.20.42) and kb (1.20.45) both arrived after the
            // sweep this docblock describes and were never added (audit
            // B10/M-15): a needs-info question, an info-reply or a
            // resolution note exists ONLY in the trail, and a knowledgebank
            // article's question/answer is public-facing wording either
            // one can link back to the activity.
            new restore_decode_content('selfselectadvanced_ticketlog', ['note'], 'ssaticketlog'),
            new restore_decode_content('selfselectadvanced_kb', ['question', 'answer'], 'ssakbentry'),
        ];
    }

    /**
     * Decode rules for the encoded links.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('SELFSELECTADVANCEDVIEWBYID', '/mod/selfselectadvanced/view.php?id=$1', 'course_module'),
            new restore_decode_rule('SELFSELECTADVANCEDINDEX', '/mod/selfselectadvanced/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Log restore mappings for this activity's events.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        return [];
    }
}
