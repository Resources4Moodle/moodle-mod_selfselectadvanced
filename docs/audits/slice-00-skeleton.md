# Slice 0 audit — skeleton

**Date:** 2026-07-24 · **Slice contents:** version.php, lib.php (features, instance CRUD, grade item plumbing), mod_form.php (§4A.7 validation), db/install.xml (full 10-table schema), db/access.php (10 capabilities), db/upgrade.php, lang/en (alphabetical), pix/monologo.svg + pix/icon.svg (U2), view.php + index.php + 2 view events, styles.css (S5a-bound), .github/workflows/moodle-ci.yml, README, CHANGELOG.

## Gate results

| Check | Result |
|---|---|
| phplint | PASS (10 files) |
| phpcs `--max-warnings 0` (moodle-cs) | PASS after phpcbf (42 auto-fixes applied, re-run clean) |
| phpdoc | PASS |
| validate | PASS (component/table/capability/lang cross-checks) |
| savepoints | PASS (note: empty upgrade fn — expected at first version) |
| mustache / grunt | N/A — no templates or AMD yet |
| Install, Moodle 5.2.1+ / PostgreSQL | PASS — version registered, 10/10 tables, 10/10 capabilities |
| Install, Moodle 5.2.1+ / MySQL | PASS — same |
| Install, Moodle 4.5.12+ / PostgreSQL (PHP 8.2) | PASS — same |
| Install, Moodle 4.5.12+ / MySQL (PHP 8.2) | PASS — same |
| PHPUnit / Behat | Deferred by design: no rule logic exists yet. mod_form §4A.7 validation tests are folded into slice 1's suite (first phpunit init happens there). |

## §14.12 security checklist (applicable items)

- `require_login($course, true, $cm)` on view.php; `require_course_login` on index.php ✔
- All input via `required_param(PARAM_INT)`; no other input surfaces yet ✔
- No SQL string concatenation (only `$DB` API + `get_in_or_equal`) ✔
- No output beyond `$OUTPUT`/`html_writer` with `format_string` ✔
- No state-changing endpoints exist yet (GET-only pages) — sesskey N/A ✔

## Good-neighbour (§14.5)

- Every table/capability/lang/CSS artefact prefixed `selfselectadvanced` ✔
- No core/third-party file touched; testbed installs verified other plugins (tool_mailaudit) upgrade unaffected alongside ✔
- delete_instance leaves core groups alone (none exist yet by construction) ✔

## Native components (C9) + M4 grep

- `grep -riE 'cdn|googleapis|unpkg|jsdelivr|<script|<style'` over the plugin: no hits outside this audit file ✔
- Assets: two original GPL SVGs only ✔ · No package.json ✔

## Findings

1. ~~phpcs multi-line call formatting (20 errors)~~ — fixed via phpcbf, re-run clean. No open findings.

**Gate: GREEN.** Slice 1 may start.
