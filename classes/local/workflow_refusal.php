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

namespace mod_selfselectadvanced\local;

/**
 * An EXPECTED workflow decision travelling as an exception.
 *
 * The 2026-08-07 external release audit (MKT-02) named the failure
 * mode this type exists to end: a service correctly re-refuses a
 * stale action under its lock, the controller has no catch, and a
 * normal multi-user race lands the person on Moodle's fatal error
 * page with a dead "More information" link. The fix is NOT to catch
 * every moodle_exception - that would disguise genuine failures as
 * business notices - but to give expected refusals their own type, so
 * a controller can catch exactly the decisions the workflow planned
 * for and let everything else stay loud.
 *
 * CONTRACT: throw this (or subclass it) for refusals a correctly
 * functioning plugin produces in the ordinary course of multi-user
 * work - state raced ahead, authority changed, a rule said no. Never
 * for malformed parameters, missing records that indicate tampering,
 * or anything a developer needs to hear about. Controllers catch this
 * type and answer with a redirect + notification; migration of the
 * scattered untyped refusal throws onto this type is incremental
 * (wave 2 of the audit response), and until a service's throws are
 * typed its controller keeps the older broad-catch-rethrow-coding
 * idiom.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workflow_refusal extends \moodle_exception {
}
