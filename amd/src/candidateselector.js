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
 * Transport for the invitation candidate autocomplete (C10, U3).
 *
 * One of the plugin's four form-autocomplete transports (candidate,
 * participant, guide, group), and the CANONICAL one: the never-reject
 * failure contract below is stated once, here, and the other three
 * point at it. This transport feeds the CORE form-autocomplete element
 * from the plugin's candidate search, which attaches per-candidate
 * eligibility and localised refusal reasons (spec section 6.2).
 * Ineligible candidates render disabled with their reason and cannot
 * be selected.
 *
 * @module     mod_selfselectadvanced/candidateselector
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Fetch candidates for the query.
 *
 * Called by core/form-autocomplete. The element carries data-cmid and
 * data-groupid attributes identifying the search scope.
 *
 * CORE'S FAILURE HANDLER IS DELIBERATELY NOT ACCEPTED (1.20.16). Core
 * passes a fourth argument, a failure callback, and rejecting through
 * it wedges the widget for the life of the page: updateAjax() resets
 * its inProgress latch only on the success path, so after one rejected
 * transport every later keystroke re-queues itself forever and no
 * request is ever sent again, and loadingicon's removal is chained off
 * the resolved promise, so the throbber never leaves either. That is a
 * silent, permanent hang - observed in production on 2026-08-07 when
 * one search call was refused transiently (RCA: a 300-byte
 * nopermissions body, the only response of that size). So on failure
 * this transport SAYS why (the exception dialog) and then answers the
 * widget with an empty result set: the spinner clears, the latch
 * resets, and the very next keystroke retries - which is exactly what
 * heals a transient refusal.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {String} query The search text.
 * @param {Function} callback Success callback receiving the results.
 */
export const transport = (selector, query, callback) => {
    const element = document.querySelector(selector);
    const request = {
        methodname: 'mod_selfselectadvanced_search_candidates',
        args: {
            cmid: parseInt(element.dataset.cmid, 10),
            groupid: parseInt(element.dataset.groupid, 10),
            query: query,
        },
    };

    Ajax.call([request])[0]
        // eslint-disable-next-line promise/no-callback-in-promise
        .then((results) => callback(results.map((candidate) => ({
            // Ineligible candidates keep their IDENTITY as a negated id
            // (2026-08-06): the previous mapping collapsed every
            // ineligible pick into the same anonymous 0, so the server
            // could name neither the candidate nor the reason and the
            // refusal pointed back at a list that may have scrolled
            // away. The negative sign still cannot collide with a real
            // pick, and the server resolves it to "name: reason".
            value: candidate.eligible ? candidate.id : -candidate.id,
            label: candidate.eligible
                ? candidate.label
                : candidate.label + ' (' + candidate.reason + ')',
        }))))
        .catch((error) => {
            // Never reject the widget (see the contract note above):
            // name the failure out loud, then unstick the autocomplete
            // so the next keystroke retries.
            Notification.exception(error);
            // eslint-disable-next-line promise/no-callback-in-promise
            callback([]);
        });
};

/**
 * Process the results for the autocomplete.
 *
 * Ineligible candidates stay in the list carrying their refusal
 * reason in the label (audit item 3 - the whole point of the custom
 * transport); they use the NEGATED user id, which the server resolves
 * back to the candidate's name and current refusal should anyone
 * select one.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {Array} results The results returned by transport.
 * @return {Array} Value/label pairs including ineligible entries.
 */
export const processResults = (selector, results) => results;
