# Pre-push checklist

Every item below exists because a push failed on it. Run the list in
order before pushing to the public repository; the goal is that the
GitHub matrix is a formality, never a discovery.

## 1. The public gates are stricter than the local gate

The local `ci-run` passes code the public workflow rejects. Before any
push, reproduce the strict gates against an IN-TREE copy (the checkers
resolve components only inside a Moodle root):

- `moodle-plugin-ci phpcs --max-warnings 0` — inline comments must
  start with a capital or digit; multi-line function calls take ONE
  argument per line (cost a full matrix run in 1.13.0); 132-char lines.
- `moodle-plugin-ci phpdoc --max-warnings 0` — every `@param`
  present AND its type agreeing with the declared hint (`?object`
  against a documented class name reads as "incomplete parameters
  list" — cost a matrix run in 1.13.0); promoted constructor
  parameters need `@param` too; no comma-carrying generics in
  `@param` (use `thing[]`).
- Mustache lint RENDERS the example context: every key a template
  uses must appear in the example, and the rendered fragment must be
  valid HTML.
- If AMD changed: `npm ci` with the branch toolchain, then the grunt
  rebuild, or the "file is stale" hash check fails.

## 2. Language strings

- Strictly alphabetical, compared bytewise. The file contains
  MULTI-LINE string values, so line-based sorting corrupts it: sort by
  splitting on `^\$string\['` blocks. After edits, verify
  programmatically (extract keys, assert each key sorts after the
  previous) — five hand-placed insertions were all wrong in 1.13.0.
- Every provider in `db/messages.php` needs
  `$string['messageprovider:<name>']`, or the notification-preferences
  page renders a placeholder and developer-mode runs throw.
- New notification kinds also need their `tplmsg*` catalogue entry in
  `templates::CATALOG` and matching `tplmsg*` string.

## 3. Behaviour changes ripple into Behat

- Any user-visible string or layout change: grep `tests/behat/` for
  the OLD text before assuming scenarios still pass ("1+0 of 1 to 6"
  cost a gate run in 1.12.0).
- Steps used in new scenarios must exist in core or the plugin; check
  a proven precedent feature for the exact phrasing.
- File pickers and autocomplete need `@javascript` (and uploads also
  `@_file_upload`).

## 4. Schema and upgrades

- A foreign key already indexes its field: never add an `<INDEX>` on
  the same single field (broke every existing site in 1.7.0 while the
  fresh-install gate stayed green).
- `index_exists()` matches FIELDS, not names; a "defensive" drop can
  delete the key's own index.
- Any new `db/*` artefact (provider, task, capability, external
  function) requires a version bump; schema changes need the savepoint
  AND a test of the REAL upgrade path (`admin/cli/upgrade.php` on a
  deployed instance), not just fresh install.

## 5. Core APIs that have burned this project

- `core_collator::compare()` does not exist — use
  `asort_objects_by_property()` (+ `array_values`, `array_reverse`).
- `get_user_preferences()` is plural; there is no singular getter.
- `single_button` with `post` adds sesskey itself.
- QuickForm submit elements accept an attributes array (4th arg) —
  that is the supported way to render a disabled button.

## 6. Repository mechanics

- The public history is a cleaned clone: land follow-ups with
  `git format-patch | git am`, never merge — histories diverge by
  design.
- Tags move only deliberately, and only onto matrix-green commits.
- The release zip is `git archive` of the tagged commit
  (`--prefix=selfselectadvanced/`), never a working-tree copy.

## 7. Editing discipline

- Never rewrite a file with a same-file read/write one-liner
  (truncated `version.php` twice); write to `.tmp`, then rename.
- After ANY multi-file edit round: `php -l` every touched file, then
  the strict phpcs/phpdoc pass from §1, then the full local gate on
  BOTH databases, and only then push.
