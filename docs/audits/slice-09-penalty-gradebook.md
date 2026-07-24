# Slice 9 audit — penalty ledger + gradebook

**Date:** 2026-07-24 · **Slice contents:** pure calculator (days between **effective** due and approval, bounded by effective cutoff, ceil per started day; percent-of-grade and points rates; waiver flag zeroes; date-override zeroing **recorded** as `dateoverride` by comparing raw settings — B2/P16 arithmetic through `assessment_dates`; full audit `basis` JSON), ledger service (one row per approved group upserted on approval/settings-save/nightly task; `penalty_recomputed` event on value change — A12; explicit zero rows), grade push (D5: point value − Σ per-group penalties per confirmed member over firm/frozen groups, floored at 0; **null-until-placed** via `grade_item::get_final()` reversion), `reconcile_penalties` nightly task (version 2026072406 + savepoint — the provider/task bump checklist held), approve() and update_instance() wiring, ledger.php page on `table_sql` with native sort/paging/**download** (C12; exportable for external/manual grading per §11), nav link, behat `ledger` page resolver, generator time-string parsing.

## Gate results

| Check | Result |
|---|---|
| phpcs `--max-warnings 0` | PASS |
| PHPUnit full suite, 5.2/PostgreSQL | **PASS 57/57, 315 assertions** |
| PHPUnit full suite, 5.2/MySQL | **PASS 57/57, 315 assertions** |
| Behat non-JS (21 scenarios), m5pg | **PASS 283/283** |
| Behat non-JS (21 scenarios), m5my | **PASS 283/283** |

## §15.1 coverage delivered this slice

- **Penalty math**: on-time explicit zero (A12); 3-days-late percent (6.0 of 100 @2%) and points (4.5 @1.5); **cutoff bound** (30d late capped at the 20d window); fractional day ceils ✔
- **B2/P16 negative + positive**: the leader's extension zeroes with reason `dateoverride`; a **non-leader member's identical extension changes nothing**; the explicit waiver flag zeroes with reason `waiver` ✔
- **D5 cumulative**: member of two late groups deducts both (100−6−10=84) while single-group members deduct theirs only; **floor at 0** under a brutal rate; `penalty_recomputed` fires per changed row on recompute ✔
- **Null-until-placed**: groupless student has a null grade, not zero ✔
- **Wiring**: real approve() writes the ledger row and pushes grades in the same flow ✔

## Incidents during the gate (watchdog log)

1. Grade null-reversion used a `[0 => 0]` index hack → core debugging "must be user id"; replaced with the official `grade_item::fetch()->get_final()` walk. Caught as phpunit notices.
2. **MySQL-only failure that pg missed**: a fixture computed `timedue = now − 2 days` and asserted `dayslate = 2`, which holds only when approval lands in the same second — MySQL's slower run tipped `ceil` to 3. Fixture given an hour's slack. A textbook example of why the gate runs on both DBs (differing timing profiles), logged for the §15.1 "concurrency/timing" lessons list.
3. A broken compound chain silently skipped an rsync (caught by md5 comparison before re-diagnosing "the same failure").

## Security / native / good-neighbour

- ledger.php `:viewall`, read-only GET; download via core dataformats ✔
- Gradebook only via `grade_update`/`grade_item` APIs; no direct grade table writes ✔
- Ledger arithmetic auditable via `basis`; ledger remains authoritative (grades derived) ✔

**Gate: GREEN.** Slice 10 (freeze/unfreeze + full core-group sync) may start.
