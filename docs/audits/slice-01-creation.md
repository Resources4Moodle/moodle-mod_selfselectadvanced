# Slice 1 audit — creation

**Date:** 2026-07-24 · **Slice contents:** resolver + effective-value DTOs (full read path incl. override table — slice 7 adds only the write path/UI), groups service (all five counting bases), gatekeeper (T1/T7 gates, S2 state preconditions, §4A.6 position DTOs), state machine authority, locks helper (A7), api facade (create/delete in transaction+lock), group_form, landing + group_page renderables/templates, groupedit.php/group.php, settings_validator extraction (unit-testable §4A.7), generator + behat generator bridge, 18 PHPUnit tests, 4-scenario Behat feature.

## Gate results

| Check | Result |
|---|---|
| phpcs `--max-warnings 0` | PASS (33 files, after phpcbf) |
| phpdoc | PASS |
| mustache lint (in-tree) | PASS (2 templates, eslint incl.) |
| PHPUnit, Moodle 5.2.1+/PostgreSQL 17 | **PASS 18/18, 62 assertions** |
| PHPUnit, Moodle 5.2.1+/MySQL | **PASS 18/18, 62 assertions** |
| PHPUnit deprecation notices (3) | Core-wide PHPUnit-11 annotation noise (core alone emits 4040 under --display-deprecations); exit 0; not plugin defects; annotations kept for PHPUnit 9.6 compat on 4.5 |
| Remote full matrix (CI box: m5pg+m5my phpunit+behat, plugin-ci) | Recorded below when the background run completes |

## Test coverage vs §15.1 (slice scope)

- L3 boundary below/at (creation) ✔ · L4 boundary + pending-invitation-doesn't-count ✔ · window open/before/after ✔ (§4A.1/L1 gates arrive with submission, slice 4)
- Counting bases: L3 across all four states; L4 confirmed-only; seats = confirmed+invited ✔
- uid format `SSA-PHY101-\d{4,}` + plugin-wide uniqueness ✔ (A1, S4: char(64), sanitised ≤12 segment)
- Name uniqueness case-insensitive (`sql_equal(..., false)`) ✔
- T7 delete: leader-only, forming-only (S2), cascade removal, `group_deleted` event ✔
- §4A.7 settings validation: full matrix in settings_validator_test ✔

## §14.12 security checklist

- `require_login` + ownership (`groups::get` filters by activityid — IDOR) on group.php ✔
- groupedit.php: `require_capability(:creategroup)`; form POST sesskey via formslib ✔
- group.php delete: GET renders confirm page only; mutation requires `data_submitted() && confirm_sesskey()` ✔
- All SQL through `$DB` with placeholders; `sql_equal`/`get_in_or_equal` for dynamic bits ✔
- Output: templates only; `format_string`/`format_text` applied at export ✔
- Race-safety: create/delete take named lock + transaction + in-lock re-validation (A7) ✔

## Good-neighbour / native components

- No core-groups interaction yet ✔ · All new CSS classes `selfselectadvanced-*`, structural only (S5a) ✔
- Templates: Bootstrap-shipped utility classes, no inline style/script (M4 grep clean) ✔ · No AMD yet; no package.json ✔
- Raw-settings-read grep (§6.3.2): limits/dates read via resolver only; `activity->settings()` consumers are lib.php CRUD, mod_form defaults and the resolver itself — compliant ✔

## Findings

1. Mustache lint must run against the in-tree copy (path restriction) — build-process note, not a defect.
2. Deprecation noise triaged (see table). No open findings.

**Gate: GREEN locally (5.2 × pg + mysql).** Remote matrix appended on completion; slice 2 starts against local green per the queueing note in docs/testing (remote failures reopen this gate before slice 2 closes).
