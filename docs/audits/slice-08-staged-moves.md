# Slice 8 audit — staged moves

**Date:** 2026-07-24 · **Slice contents:** moves engine (stage with IDOR + successor preconditions; **A4 joint validation** of a selected set against net post-state group deltas with proper add/remove set semantics; atomic commit-all-or-none under the activity lock; cancel), leadership handling inside moves (successor takes the source atomically; makeleader on the target; released leaderships counted in the L3 verdict), per-move bypass overrides (P13) consumed rule-by-rule and reported as "bypassed", quota-on-both-groups via virtual rosters, `freeze::sync_membership_change` + `append_snapshot` (the A6 interface — live core-group mirroring for frozen targets, reused by slice 10), 3 move events, `movecommitted` provider (version 2026072405 + savepoint), moves.php list with per-rule verdict chips + joint-commit selection + cancel, moveedit.php staging form (bypass checkboxes gated on `:override`), nav link, generator + behat entities/resolvers, 37 lang strings.

## Gate results

| Check | Result |
|---|---|
| phpcs `--max-warnings 0` | PASS |
| PHPUnit full suite, 5.2/PostgreSQL | **PASS 53/53, 291 assertions** |
| PHPUnit full suite, 5.2/MySQL | **PASS 53/53, 291 assertions** |
| Behat non-JS (20 scenarios), m5pg | **PASS 276/276** |
| Behat non-JS (20 scenarios), m5my | **PASS 276/276** |

## §15.1 coverage delivered this slice

- **The spec's canonical case**: a single move that breaks source-L1 *and* target-L2 is invalid alone and refused at commit; adding the counter-move makes the **set** jointly valid and the swap commits atomically with both sizes preserved ✔
- **No visible change while pending** and after cancel ✔
- **Leader moves**: staging without a successor refused; a two-leader swap validates with released leaderships counted (the B3-correct L3 arithmetic); successor + makeleader both applied in one transaction ✔
- **P13 bypass**: L2-blocked placement of a groupless student (the §9.4 pattern) unblocked *only* by the attached `L2` bypass, reported as bypassed, committed to 3/2 seats ✔
- **Scoping**: committed/cancelled/foreign move ids inert or refused ✔
- Behat: refused-alone → set-committed flow and cancel round-trip through the real UI on both DBs ✔

## Incidents during the gate (watchdog log)

1. **Net-delta math wrong for swaps** — my original formula ignored arrivals when computing the source's post-state (a swap failed L1/L2 that should pass). Rewritten with explicit `add\rem` / `rem\add` set semantics against fetched confirmed/seat-holder sets. Caught by the swap unit test.
2. **L3 verdict missed released leaderships** — a leader moving out with a successor still counted their old slot. Caught by the leader-swap test.
3. **Missing version bump for the new message provider** (same lesson as slice 4 — now twice; noted for the final audit checklist as a standing pre-gate check).
4. The "watched" behat first-run flake did **not** recur across 19 first-run scenarios with faildump armed; the one failure this round was a deterministic name typo in the new feature (faildump proved the page content was correct). Watched item closed; will reopen if ever seen again.

## Security / native / good-neighbour

- moves/moveedit `:manage`-guarded; bypass attachment additionally `:override`-gated; commit/cancel POST+sesskey; every group/move id activity-scoped ✔
- Commit under activity lock + transaction with in-lock revalidation; frozen-group mirroring inside the same transaction via the groups API only ✔
- Verdict chips carry text reasons (title + visually-hidden text), not colour alone ✔

**Gate: GREEN.** Slice 9 (penalty ledger + gradebook) may start.
