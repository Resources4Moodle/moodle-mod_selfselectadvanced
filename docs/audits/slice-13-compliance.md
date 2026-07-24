# Slice 13 audit — compliance (privacy, backup/restore, leave flow, reminder)

**Date:** 2026-07-24 · **Slice contents:** §6.3 **leave-request flow** (member files, leader confirms via new L1-gated `can_confirm_leave`, both parties notified, UI on the group page); **deadline-reminder task** (24h window before per-user *effective* due date, once-only via preference marker); three providers (`leaverequest`/`leaveresult`/`deadlinereminder`) with version 2026072409 + savepoint; **privacy provider** (metadata for all user-bearing tables + the preference; module- and system-context discovery; export of memberships/overrides/moves/attributes/preferences; userlist provider; deletes that remove member/move/override rows, scrub snapshots, pseudonymise agrun logs, and **blank leaders so groups land on the flagged report — M1**); **backup/restore** (settings + quota always; groups/members/snapshots/penalties/user-group-guide overrides with userinfo; **M2 exclusions**: agrun + staged moves + their move-scope overrides never travel; user ids and coregroupid remapped; snapshot rosters remapped in code; same-site `pluginuid` collisions regenerated from the new row id per D3).

## Gate results (CI box `ci-run`) — `RESULT fail=0`

| Check | Result |
|---|---|
| PHPUnit (69 tests incl. 4 compliance tests), pg + mariadb | PASS |
| Behat incl. @javascript, m5pg + m5my | **PASS 29/29, 401 steps each** |
| phpcs / phpdoc / validate / savepoints | PASS |

## §15.1 coverage delivered

- Leave: no-request/not-leader/L1 refusals with reasons; group-override lifts the block ✔
- Reminder: inside-window groupless student messaged once; grouped student skipped; second run silent ✔
- Privacy: module+system contexts discovered; delete removes membership, blanks leader (M1 → flagged), removes the system attribute row ✔
- Roundtrip (userinfo, controller-level with `keeptempdirectoriesonbackup`): group+roster+leader+quota+penalty travel with identity mapping; **moves and agrun provably absent** after restore (M2) ✔

## Incidents during the gate (watchdog log)

1. **Repeated the slice-2 missing-SQL-comma bug** in the leave-requests query — took down 10 Behat scenarios on both DBs (every group-page render). Same-day repeat of a known pattern; slice-14 audit adds a grep for `userid \$namefields` shapes.
2. Restore crashes fixed in sequence: omitted NOT NULL `usermodified` on two tables; same-site `pluginuid` unique collision (now regenerated per D3).
3. Test-side learnings: `backup::TARGET_NEW_SECTION` doesn't exist (→ `TARGET_CURRENT_ADDING`); `MODE_GENERAL` roundtrips need `$CFG->keeptempdirectoriesonbackup`; `duplicate_module()` strips userinfo by design; `get_contextids()` returns strings (strict `assertContains` trap); empty destructuring `[, , , ]` is a PHP fatal.
4. Remote (newer) moodle-cs findings: needless `MOODLE_INTERNAL` in class-only stepslibs; a phpcbf-collapsed 216-char implements line; a `phpcs:ignore` line splitting a docblock.

**Gate: GREEN.** Only slice 14 (final audit + release) remains.
