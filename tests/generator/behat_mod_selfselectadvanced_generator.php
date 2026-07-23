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
 * Behat data generator for mod_selfselectadvanced.
 *
 * Lets features arrange groups and memberships declaratively:
 *
 *   And the following "mod_selfselectadvanced > groups" exist:
 *     | selfselectadvanced | name      | leader   |
 *     | ssa1               | Team Blue | student2 |
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_selfselectadvanced_generator extends behat_generator_base {
    /**
     * The entities this generator can create.
     *
     * @return array[]
     */
    protected function get_creatable_entities(): array {
        return [
            'groups' => [
                'singular' => 'group',
                'datagenerator' => 'group',
                'required' => ['selfselectadvanced', 'name', 'leader'],
                'switchids' => [
                    'selfselectadvanced' => 'activityid',
                    'leader' => 'leaderid',
                    'successor' => 'successorid',
                    'guide' => 'guideid',
                ],
            ],
            'members' => [
                'singular' => 'member',
                'datagenerator' => 'member',
                'required' => ['ssagroup', 'user'],
                'switchids' => ['ssagroup' => 'groupid', 'user' => 'userid'],
            ],
            'attributes' => [
                'singular' => 'attribute',
                'datagenerator' => 'userattr',
                'required' => ['user'],
                'switchids' => ['user' => 'userid'],
            ],
        ];
    }

    /**
     * Map an activity idnumber to its instance id.
     *
     * @param string $idnumber the course module idnumber
     * @return int the selfselectadvanced instance id
     */
    protected function get_selfselectadvanced_id(string $idnumber): int {
        global $DB;

        $sql = "SELECT cm.instance
                  FROM {course_modules} cm
                  JOIN {modules} md ON md.id = cm.module AND md.name = :modname
                 WHERE cm.idnumber = :idnumber";
        $instanceid = $DB->get_field_sql($sql, ['modname' => 'selfselectadvanced', 'idnumber' => $idnumber]);
        if (!$instanceid) {
            throw new Exception('No selfselectadvanced activity with idnumber "' . $idnumber . '"');
        }

        return (int) $instanceid;
    }

    /**
     * Map a leader username to a user id.
     *
     * @param string $username the username
     * @return int the user id
     */
    protected function get_leader_id(string $username): int {
        return $this->get_user_id($username);
    }

    /**
     * Map a successor username to a user id.
     *
     * @param string $username the username
     * @return int the user id
     */
    protected function get_successor_id(string $username): int {
        return $this->get_user_id($username);
    }

    /**
     * Map a guide username to a user id.
     *
     * @param string $username the username
     * @return int the user id
     */
    protected function get_guide_id(string $username): int {
        return $this->get_user_id($username);
    }

    /**
     * Map a plugin group name to its id.
     *
     * Named "ssagroup" in feature tables: the base class already owns
     * get_group_id() for core course groups with an incompatible
     * signature, so the plugin entity must use its own field name.
     *
     * @param string $name the plugin group name
     * @return int the group id
     */
    protected function get_ssagroup_id(string $name): int {
        global $DB;

        $id = $DB->get_field('selfselectadvanced_group', 'id', ['name' => $name]);
        if (!$id) {
            throw new Exception('No selfselectadvanced group named "' . $name . '"');
        }

        return (int) $id;
    }
}
