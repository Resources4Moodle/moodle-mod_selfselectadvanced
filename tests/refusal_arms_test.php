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

namespace mod_selfselectadvanced;

/**
 * Every POST arm that can meet a service refusal answers with a notice,
 * never the raw error page (seam audit failure-honesty family, 1.20.19).
 *
 * The proof style is the one leaderjoinpanel_test already uses for page
 * scripts: assert the script carries the guard, because a page script
 * has no callable seam a unit test can drive. The guard contract is the
 * catch-notify-redirect written on the accept arm in 1.20.18; the live
 * behaviour of one representative arm is verified on the deployed site
 * at every release (the stale-click curl check). WEAK BY DESIGN and
 * declared as such: this pins the presence of the pattern, the gate's
 * behat pins the success paths, and the deployed-site check pins one
 * real refusal end to end.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class refusal_arms_test extends \advanced_testcase {
    /**
     * Which arms each script guards; the count is the number of
     * NOTIFY_ERROR redirects the script must carry at minimum.
     */
    public static function arms(): array {
        return [
            'group.php: submit, succession x4, withdraw, accept, decline, freeze' => ['group.php', 9],
            'manage.php: assignguide' => ['manage.php', 1],
            'departments.php: add/rename' => ['departments.php', 1],
            'review.php: approve' => ['review.php', 1],
            'moves.php: cancel' => ['moves.php', 1],
            'view.php: consent toggle' => ['view.php', 1],
        ];
    }

    /**
     * Each guarded script catches moodle_exception, rethrows coding
     * errors, and answers with a NOTIFY_ERROR redirect.
     *
     * @dataProvider arms
     * @param string $script repo-relative script name
     * @param int $minguards minimum guarded arms in the file
     */
    public function test_the_arms_answer_with_notices(string $script, int $minguards): void {
        $source = file_get_contents(__DIR__ . '/../' . $script);

        $guards = substr_count($source, 'notification::NOTIFY_ERROR');
        $this->assertGreaterThanOrEqual(
            $minguards,
            $guards,
            "$script lost a refusal guard: a service throw would reach the raw error page again"
        );
        $this->assertStringContainsString(
            'coding_exception',
            $source,
            "$script must rethrow coding errors rather than notice them away"
        );
    }
}
