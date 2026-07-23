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

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat page resolvers for mod_selfselectadvanced.
 *
 * Enables steps like:
 *   When I am on the "Lab groups" "mod_selfselectadvanced > quotas" page
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_selfselectadvanced extends behat_base {
    /**
     * Resolve plugin page types to URLs.
     *
     * Recognised types (identifier = the activity name): quotas,
     * manage, guide.
     *
     * @param string $type page type
     * @param string $identifier activity name
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        $pages = [
            'quotas' => '/mod/selfselectadvanced/quotas.php',
            'manage' => '/mod/selfselectadvanced/manage.php',
            'guide' => '/mod/selfselectadvanced/guide.php',
            'moves' => '/mod/selfselectadvanced/moves.php',
            'overrides' => '/mod/selfselectadvanced/overrides.php',
        ];
        $type = strtolower($type);
        if (!isset($pages[$type])) {
            throw new Exception('Unrecognised mod_selfselectadvanced page type "' . $type . '"');
        }
        $cm = $this->get_cm_by_activity_name('selfselectadvanced', $identifier);

        return new moodle_url($pages[$type], ['id' => $cm->id]);
    }
}
