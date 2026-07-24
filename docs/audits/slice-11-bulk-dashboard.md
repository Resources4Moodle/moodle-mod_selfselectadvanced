# Slice 11 audit — bulk ops, manager dashboard, flagged report

**Date:** 2026-07-24 · **Slice contents:** guide **bulk freeze** (§12: sesskey POST over selected firm groups with per-group skip reporting) behind the §12 **filters** (state, quota-compliant — evaluated live, approved before/after date, department-of-any-member) with all-matching preselection; **manager dashboard** completion — `groups_table` (core `table_sql`: sortable/pageable/downloadable; leader/guide names, penalty join, §4A.6 Size cell "confirmed+pending of min–max", per-row View/Unfreeze) + GET state filter + tool-link row (quotas/moves/overrides/ledger/flagged); **flagged.php** report (§9.4/§8.1/§4A.8/M1: groupless students with attribute lines + stage-placement links, missing-attribute students, leaderless groups, grandfathered out-of-limit groups with figures); flagged nav link; 20 lang strings.

## Gate results (CI box `ci-run`)

| Check | Result |
|---|---|
| PHPUnit, 5.2/PostgreSQL 18 + 5.2/MariaDB | **PASS 61/61, 346 assertions each** |
| Behat incl. @javascript, m5pg + m5my | **PASS 28/28 scenarios, 391 steps each** — `RESULT fail=0` |
| phpcs / phpdoc / validate / savepoints | PASS |

## Coverage rationale

This slice is UI aggregation over services fully unit-tested in slices 1–10 (freeze_group, evaluator, seat_position, attribute display); per §15.1 its user-facing flows are covered by Behat: bulk-freeze of all matching firm groups → both frozen → control disappears; dashboard table rendering with the Size cell and state filtering to an empty result; flagged report showing the groupless student (with missing-attribute marker), the missing-attributes list and the no-anomalies state. PHPUnit total unchanged by design (61).

## Incidents during the gate (watchdog log)

1. **New feature files require behat re-init** — Moodle enumerates feature files into behat.yml at init; the first `ci-run` silently ran the old 25. Detected by the unchanged scenario count; `--reinit` registered the new feature. Standing rule: any slice adding a `.feature` file gates with `ci-run --reinit`.
2. Scratchpad tooling (langmerge/bumpversion) was tmp-cleaned; recreated. Consider moving them into the repo's `.dev/` in slice 14 so they survive.
3. One test-authoring bug: asserted a grouped student's attribute line on the flagged page (only groupless entries render lines) — fixed to assert the real "Attributes missing" row.

## Security / native / good-neighbour

- Bulk freeze `:freeze` + sesskey POST; per-group errors collected, never partial-silent ✔ · Dashboard/flagged `:manage`/`:viewall` GET read-only ✔
- Filters are GET (view-only); the fragile-date input parses via `strtotime` with a false-guard ✔
- C12: dashboard listing is core `table_sql` (as are attributes and ledger). The small config lists (quotas/overrides/moves) remain template tables — recorded for the slice-14 conformance pass with the audit position that the moves list is an interactive selection *form*.

**Gate: GREEN (`fail=0`).** Slice 12 (auto-grouping) may start.
