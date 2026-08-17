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
 * A queue of already-materialised events, gathered while a lock or a
 * transaction is held and triggered only once neither is (CONC-001,
 * external audit 2026-08-13: "arbitrary Moodle/plugin event observers
 * can execute during the plugin's critical section, increasing lock
 * duration and creating cross-plugin lock/callback coupling").
 *
 * kb::publish_from_ticket() established the shape by hand for a single
 * event - build it inside the critical section, hold the object, and
 * call ->trigger() only after the transaction has committed and the
 * lock has released - and several other services copied it. That shape
 * stops scaling past one event or one call frame: a loop needs
 * somewhere to collect more than one (a cascade of declines, a batch of
 * committed moves), and a NESTED caller needs to hand its events to
 * whichever frame releases the OUTERMOST lock, because that frame is
 * the only one that knows when firing is finally safe -
 * joinrequests::respond() calling do_accept() calling
 * moves::commit_set() and override\store::save_for_new_move(), or
 * state::do_approve() calling override\store::save(), are both cases
 * where the event's own data is built two or three frames below the
 * lock that must be gone before anything may observe it. This class is
 * the one shared place for that, instead of a bespoke `?array &$x`
 * out-parameter (or a bare `$event` local plus an easy-to-forget
 * `$event->trigger();` after the `finally`) reinvented at every site.
 *
 * Usage: create one per top-level operation, push() every event built
 * while a lock or transaction is held, and flush() exactly once, after
 * every lock that operation holds has been released and every
 * transaction it opened has committed. A nested callee that receives
 * somebody else's queue only pushes into it - flushing is always the
 * top-level caller's job, because only it knows when its own lock is
 * gone.
 *
 * IMPOSSIBLE TO USE WRONGLY IN SILENCE: a queue destroyed with events
 * still in it - flush() was never called, or an exception skipped it -
 * reports itself with debugging(), the same enforcement
 * locks::check_order() already relies on and the one
 * docs/architecture.md A7 explains is backed by --fail-on-notice in
 * both this repository's own CI workflow and the maintainer's gate. A
 * silently dropped flush() is exactly CONC-001's own defect
 * reintroduced by a caller that stopped following the pattern; this
 * makes that loud rather than invisible.
 *
 * @package    mod_selfselectadvanced
 * @copyright  2026 JSP <jsp@jsp.net.in>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class eventqueue {
    /** @var \core\event\base[] Events collected, in push order. */
    private array $events = [];

    /** @var bool Whether flush() has already run. */
    private bool $flushed = false;

    /**
     * Queue an already-built event.
     *
     * Materialise it - build it from rows already read - before calling
     * this, while still inside the lock/transaction that protects those
     * rows: the whole point of this class is that nothing reads the
     * database again later to build the event it fires.
     *
     * @param \core\event\base $event the event, built but not triggered
     * @throws \coding_exception when called after flush()
     */
    public function push(\core\event\base $event): void {
        if ($this->flushed) {
            throw new \coding_exception('eventqueue::push() called after flush()');
        }
        $this->events[] = $event;
    }

    /**
     * Abandon every queued event without firing it.
     *
     * A refusal can be discovered AFTER something has already been
     * pushed - override\store::save() writes a relief row and pushes
     * its event, and only the next line finds the merged row still
     * pending and refuses; joinrequests::do_accept() pushes a move-scope
     * override event through save_for_new_move() and only afterwards
     * validates the staged move. Either throw rolls the transaction
     * back, so what the queued event describes never actually
     * happened, and firing it later would be exactly the lie the
     * no-lies rule forbids. Call this from the SAME catch block that
     * rolls the transaction back, before rethrowing - never a silent
     * side effect of failing to flush.
     */
    public function discard(): void {
        $this->flushed = true;
        $this->events = [];
    }

    /**
     * Trigger every queued event, in the order they were pushed, and
     * mark this queue spent.
     *
     * Call this exactly once, and only once every lock and transaction
     * the owning caller holds has been released and committed. A
     * callee that only received this queue (rather than creating it)
     * must never call flush() - that is always the top-level caller's
     * responsibility, because only it knows when its own critical
     * section has actually ended.
     */
    public function flush(): void {
        $this->flushed = true;
        $events = $this->events;
        $this->events = [];
        foreach ($events as $event) {
            $event->trigger();
        }
    }

    /**
     * How many events are still queued.
     *
     * @return int
     */
    public function count(): int {
        return count($this->events);
    }

    /**
     * The unflushed backstop. A queue garbage-collected with events
     * still in it means some caller on the way here forgot to flush() -
     * reported the same way locks::check_order() reports a lock-order
     * violation, so a test exercising the path reddens instead of
     * passing on a silently dropped event.
     */
    public function __destruct() {
        if (!$this->flushed && $this->events !== []) {
            debugging(
                'eventqueue destroyed with ' . count($this->events) . ' event(s) never fired - '
                    . 'a caller forgot to call flush() (CONC-001).',
                DEBUG_DEVELOPER
            );
        }
    }
}
