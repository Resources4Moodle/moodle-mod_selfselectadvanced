# 1.1.0 audit — pre-defined departments + per-activity templates + wording

**Date:** 2026-07-24 · **Trigger:** user change requests after the
production demo (em-dash wording; free-text departments invite typos —
make them pre-defined "of the format that we have for course
categories"; "where are the mail templates that can be edited by
editing teachers?").

## Contents

1. **Wording** — `sizecell` and `flagoutoflimit` now say "{min} to
   {max}" instead of an en dash; the one Behat assertion updated.
2. **Department vocabulary** (`selfselectadvanced_dept`) — the
   course-categories format (name/parent/depth/path/sortorder), so the
   tree can go arbitrarily deep; the attribute fields consume levels 1
   and 2. Admin page `departments.php` (add/rename/move/delete with
   children/in-use guards) under the plugin's admin category.
   Enforcement is **conditional on the tree being non-empty**
   (`depts::is_configured()`): while empty, free text remains valid —
   no lockout for existing sites, and unrelated fixtures keep working.
   The editor form switches to select/selectgroups; the CSV importer
   **rejects** rows with out-of-vocabulary values (consistent with the
   strict U4 unknown-user rule). Upgrade step 2026072412 creates the
   table and seeds it from distinct already-ingested
   (department, sub-department) pairs so existing data stays valid.
3. **Notification templates** (`selfselectadvanced_template`) —
   per-activity subject/body overrides for all 22 message kinds,
   keyed by the body lang key; catalog in `local\templates::CATALOG`.
   Page `templates.php` guarded by `mod/selfselectadvanced:manage`
   (editing teachers by default), linked from the settings navigation
   and the manager dashboard. `notifier::send` consults the override
   before falling back to `get_string`; substitution mirrors
   get_string's `{$a->name}` syntax (`templates::render`). Included in
   backup (config-level, not userinfo-gated) and restored with the
   remapped activity id. Site-wide Language customisation remains the
   admin-level layer beneath.

## Non-goals / positions

- `manager::set` stays permissive (low-level writer used by tests and
  admin tooling); enforcement lives in the form and the importer, the
  two places humans enter data.
- Template overrides store no user ids (no privacy metadata needed);
  the dept table is site vocabulary, no user data.
- Gender deliberately stays free text (not in scope of the request).

## Gate

- New PHPUnit: `depts_test` (tree/paths/order/menus, validate_pair,
  delete guards, rename, sibling moves, CSV enforcement incl.
  wrong-parent) and `templates_test` (render semantics, store
  lifecycle, notifier override + reset, catalog guard) — 6 tests, all
  green on the maintainer testbed first.
- New Behat: departments admin curation scenario, bad-department CSV
  rejection scenario (fixture `attributes_baddept.csv`),
  `templates.feature` (customise + reset via the UI);
  `attributes_admin.feature` background now defines the vocabulary.
- Full matrix on the CI box: **`RESULT fail=0`** at 7273568 —
  32 Behat scenarios / 445 steps on both DBs, PHPUnit 76 tests,
  static checks clean. Two behat-infrastructure lessons: file-upload
  steps need `@_file_upload` AND a real browser (`@javascript`);
  BrowserKit cannot drive filepickers.
