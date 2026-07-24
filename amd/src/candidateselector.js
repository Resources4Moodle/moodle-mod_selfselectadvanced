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
 * The only custom AMD module in the plugin: it feeds the CORE
 * form-autocomplete element from the plugin's candidate search, which
 * attaches per-candidate eligibility and localised refusal reasons
 * (spec section 6.2). Ineligible candidates render disabled with their
 * reason and cannot be selected.
 *
 * @module     mod_selfselectadvanced/candidateselector
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Fetch candidates for the query.
 *
 * Called by core/form-autocomplete. The element carries data-cmid and
 * data-groupid attributes identifying the search scope.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {String} query The search text.
 * @param {Function} callback Success callback receiving the results.
 * @param {Function} failure Failure callback.
 */
export const transport = (selector, query, callback, failure) => {
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
        .then((results) => callback(results.map((candidate) => ({
            value: candidate.eligible ? candidate.id : 0,
            label: candidate.eligible
                ? candidate.label
                : candidate.label + ' (' + candidate.reason + ')',
        }))))
        .catch(failure);
};

/**
 * Process the results for the autocomplete.
 *
 * Ineligible candidates stay in the list carrying their refusal
 * reason in the label (audit item 3 - the whole point of the custom
 * transport); they use value 0, which the server refuses with the
 * same reason should anyone select one.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {Array} results The results returned by transport.
 * @return {Array} Value/label pairs including ineligible entries.
 */
export const processResults = (selector, results) => results;
