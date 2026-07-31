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
 * Transport for the team pickers on the move, override and join forms.
 *
 * The move form carried two selects holding every team in the activity,
 * and the override form an autocomplete that filtered in the browser -
 * which still renders every option first. Both now fetch only what a
 * query matches, capped server-side.
 *
 * @module     mod_selfselectadvanced/groupselector
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Fetch teams matching the query.
 *
 * Called by core/form-autocomplete. The element carries data-cmid.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {String} query The search text.
 * @param {Function} callback Success callback receiving the results.
 * @param {Function} failure Failure callback.
 */
export const transport = (selector, query, callback, failure) => {
    const element = document.querySelector(selector);
    const request = {
        methodname: 'mod_selfselectadvanced_search_groups',
        args: {
            cmid: parseInt(element.dataset.cmid, 10),
            query: query,
            // Only the join picker asks for this. The server judges the
            // teams against the calling user and folds the caution and
            // the seat into the label, so nothing here has to know the
            // composition rules.
            fit: element.dataset.fit === '1',
        },
    };

    Ajax.call([request])[0]
        // eslint-disable-next-line promise/no-callback-in-promise
        .then((results) => callback(results.map((group) => ({
            value: group.id,
            label: group.label,
        }))))
        .catch(failure);
};

/**
 * Process the results for the autocomplete.
 *
 * Already ordered by name and capped by the server.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {Array} results The results returned by transport.
 * @return {Array} Value/label pairs.
 */
export const processResults = (selector, results) => results;
