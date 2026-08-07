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
 * Transport for the manager's move-form student pickers.
 *
 * Feeds the core form-autocomplete from this activity's own participant
 * search, so the coordinator needs no site-wide capability - core's
 * user selector demands moodle/user:viewalldetails in the system
 * context, which a course-level coordinator does not hold.
 *
 * @module     mod_selfselectadvanced/participantselector
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Fetch this activity's participants matching the query.
 *
 * Called by core/form-autocomplete. The element carries a data-cmid
 * attribute identifying the activity to search within.
 *
 * Core's failure handler is deliberately not accepted: rejecting the
 * transport wedges core's autocomplete for the life of the page (the
 * in-progress latch and the loading icon both recover only on the
 * success path). The full contract note lives in candidateselector.js
 * (1.20.16); on failure this transport names the error in a dialog and
 * answers the widget with an empty result set so typing retries.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {String} query The search text.
 * @param {Function} callback Success callback receiving the results.
 */
export const transport = (selector, query, callback) => {
    const element = document.querySelector(selector);
    const request = {
        methodname: 'mod_selfselectadvanced_search_participants',
        args: {
            cmid: parseInt(element.dataset.cmid, 10),
            query: query,
        },
    };

    Ajax.call([request])[0]
        // eslint-disable-next-line promise/no-callback-in-promise
        .then((results) => callback(results.map((participant) => ({
            value: participant.id,
            label: participant.label,
        }))))
        .catch((error) => {
            // Never reject the widget - see candidateselector.js.
            Notification.exception(error);
            // eslint-disable-next-line promise/no-callback-in-promise
            callback([]);
        });
};

/**
 * Process the results for the autocomplete.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {Array} results The results returned by transport.
 * @return {Array} Value/label pairs.
 */
export const processResults = (selector, results) => results;
