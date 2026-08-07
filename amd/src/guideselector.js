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
 * Transport for every guide picker in the plugin (strategy 1.18 B).
 *
 * Feeds the CORE form-autocomplete element from the plugin's guide
 * search. The element it enhances carries no options of its own: a
 * school with 1500 guides cannot render them, least of all once per row
 * of the assignment queue, so the list is fetched per query and capped
 * server-side.
 *
 * @module     mod_selfselectadvanced/guideselector
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import * as Autocomplete from 'core/form-autocomplete';
import Notification from 'core/notification';

/**
 * Enhance every guide picker on the page.
 *
 * One call for the whole page rather than one per control: the
 * assignment queue carries a picker on every row, and fifty separate
 * module calls to do the same thing is fifty chances for one of them to
 * be wired differently from the rest.
 *
 * @param {String} placeholder Text shown before a guide is chosen.
 * @param {String} noSelection Text shown when nothing is chosen.
 */
export const init = async(placeholder, noSelection) => {
    const fields = document.querySelectorAll('select[data-ssa-guidepicker]');
    let firsterror = null;
    for (const field of fields) {
        try {
            await Autocomplete.enhanceField(
                '#' + field.id,
                false,
                'mod_selfselectadvanced/guideselector',
                placeholder,
                false,
                true,
                noSelection,
                true
            );
        } catch (error) {
            // One failed enhancement must not silently abandon every
            // picker after it (seam audit, 1.20.19): the un-enhanced
            // rows keep their plain select, the rest still enhance,
            // and the first failure is named once.
            firsterror = firsterror || error;
        }
    }
    if (firsterror) {
        Notification.exception(firsterror);
    }
};

/**
 * Fetch guides matching the query.
 *
 * Called by core/form-autocomplete. The element carries data-cmid, and
 * optionally data-withroom="0" for the pickers that must show a full
 * guide too rather than silently omit one.
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
        methodname: 'mod_selfselectadvanced_search_guides',
        args: {
            cmid: parseInt(element.dataset.cmid, 10),
            query: query,
            withroom: element.dataset.withroom !== '0',
        },
    };

    Ajax.call([request])[0]
        // eslint-disable-next-line promise/no-callback-in-promise
        .then((results) => callback(results.map((guide) => ({
            value: guide.id,
            label: guide.label,
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
 * The server has already ordered them - most room first, then by name -
 * and capped the list, so they are passed through unchanged.
 *
 * @param {String} selector The autocomplete element selector.
 * @param {Array} results The results returned by transport.
 * @return {Array} Value/label pairs.
 */
export const processResults = (selector, results) => results;
