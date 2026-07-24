# Slice 10 audit — freeze/unfreeze & core-group sync

**Date:** 2026-07-24 · **Slice contents:** freeze service completion — T5 `freeze_group` (core group "[idnumber|name] groupname" via the official groups API only, exact-membership reconcile, activity grouping created/reused, snapshot append, state lock, member notifications) with the external-deletion **repair path**; T6 `unfreeze` (owned-core-group delete, **snapshot-exact roster restore** incl. leadership, drift computed and **reported-not-merged**, state→firm, grandfathered past tightened limits per §4A.8); `check_restrictions` (course-module + section availability referencing the core group → warning list on the confirm page); `drift()`; `can_freeze` gatekeeper method (S2 firm + L1/L2/quota defence-in-depth); freeze/unfreeze confirm-page actions on group.php; guide-dashboard Freeze buttons; `groupfrozen`/`groupunfrozen` events + providers (version 2026072407 + savepoint); 22 lang strings.

## Gate results — first full run on the migrated CI workflow

| Check (CI box, `ci-run` → `### RESULT fail=0`) | Result |
|---|---|
| PHPUnit, Moodle 5.2 / PostgreSQL 18 | **PASS 61/61, 346 assertions** |
| PHPUnit, Moodle 5.2 / MariaDB 10.11 | **PASS 61/61, 346 assertions** |
| Behat **including @javascript**, m5pg.ci (PostgreSQL) | **PASS 25/25 scenarios, 354 steps** |
| Behat including @javascript, m5my.ci (MariaDB) | **PASS 25/25 scenarios, 354 steps** |
| phpcs / phpdoc / validate / savepoints | PASS |

This run also retro-validates the @javascript scenarios of slices 2, 3 and 7 on both DBs — the local Selenium memory ceiling had left them single-DB or flaky; the CI box now runs them reliably (post-kioskenforcer-fix, post-restart).

## §15.1 coverage delivered this slice

- **T5**: named core group with exactly the confirmed members; grouping created once and reused; snapshot roster stored; event + notifications ✔
- **Guards**: assigned-guide-only, S2 firm-only, L1 defence-in-depth ✔
- **A6 proven end-to-end**: staged move into a frozen group updates plugin roster + core group + snapshot in one transaction; **unfreeze restores the moved roster** while an out-of-band core-group addition is discarded and reported as drift (event payload + UI notice) ✔
- **Repair**: externally-deleted core group recreated by re-freezing with a fresh id and full membership ✔
- **Restriction warning**: a page restricted on the core group is named on the unfreeze confirm ✔
- **Grandfathering**: restore succeeds verbatim past a tightened `maxsize` ✔

## Incidents during the gate (watchdog log)

1. `freeze::freeze()` = PHP4-style constructor collision (phpcs caught) → renamed `freeze_group`; `can_freeze` had been specified in the §7 matrix but never implemented — added.
2. version.php truncated by the banned one-liner pattern **again** → restored from git; `bumpversion.sh` now the only bump path.
3. The one red Behat scenario was the @javascript override flow failing at `I follow "Overrides"`: Boost+JS collapses custom settings-nav nodes into the "More" dropdown. Fixed by navigating via the behat page resolver. Lesson recorded: JS scenarios must not depend on secondary-nav link visibility.
4. Mid-slice, development migrated to the CI box on user instruction (repos synced, kioskenforcer fixed upstream `2ac5a6a`); gate re-run there after the user's box restart.

**Gate: GREEN (`fail=0`).** Slice 11 (bulk ops + manager dashboard) may start.
