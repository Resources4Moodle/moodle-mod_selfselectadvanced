# Slice 5 audit — participant attributes

**Date:** 2026-07-24 · **Slice contents:** attributes manager (plugin-local store; bulk fetch keyed by userid; partial updates; MUC distinct-value cache; `display_line` for staff rosters), U4/A9 CSV importer (case/space-insensitive headers incl. "Mobile Number"/"Sub-Department"; username match with email fallback; unknown-user rejection with create-the-account guidance (C11); name cross-check warn-but-ingest; mobile format guard; dry-run report → sesskey-confirmed commit in a transaction; import event with counts), `attributes_updated`/`attributes_imported` events, `db/caches.php` (MUC) + `db/events.php` + observer (`user_deleted` → row delete + cache purge, M3), admin tree (Plugins → Activity modules → Group self-selection (Advanced) → Participant attributes), attributes.php (upload/preview/commit + add/edit form incl. core site-user selector + `table_sql` listing with sort/page/download per C12), staff roster attribute lines on group and review pages (mobile `:viewall`-gated per U4), generator + behat entity, version 2026072404 + savepoint.

## Gate results

| Check | Result |
|---|---|
| phpcs `--max-warnings 0` (78 files) | PASS |
| PHPUnit, 5.2/PostgreSQL | **PASS 40/40, 204 assertions** |
| PHPUnit, 5.2/MySQL | **PASS 40/40, 204 assertions** |
| Behat non-JS (15 scenarios), m5pg/PostgreSQL | **PASS 196/196 steps** |
| Behat non-JS (15 scenarios), m5my/MySQL | **PASS 196/196 steps** |

## §15.1 coverage delivered this slice

- Importer: create+update counts, dry-run writes nothing, commit transactional with event counts; **U4 header accepted verbatim** ("Username, First name, Last Name, Gender, Department, Sub-Department, Mobile Number, Email"); email fallback for blank usernames ✔
- **C11 negative proofs**: unknown user → row rejected with guidance; the core user record is byte-identical before/after attribute writes ✔
- **A9**: name mismatch warned *and* ingested (username authoritative); invalid mobile warned and skipped ✔
- Manager: partial update keeps untouched fields; distinct-value cache follows writes; unknown dimension = coding_exception ✔
- **M3 observer**: core `delete_user()` removes the record and empties the cached value list ✔
- Behat: admin tree navigation → listing → inline edit round-trip; staff roster shows "Female · Civil · Structures · +91 111 22222" while the student view does not ✔ (CSV upload path exercised by PHPUnit; the filepicker needs JS)

## Incidents during the gate (watchdog log)

1. **version.php truncated to 0 bytes** by a `open(p,'w').write(open(p).read()...)` one-liner (receiver truncates before the read). Caught immediately by phpunit init's "defective plugin" refusal; restored from git, bumped with sed. Rule adopted: no same-file read-write one-liners; use the langmerge-style two-step scripts.
2. "Mobile Number" canonicalised to `mobilenumber` ≠ required key `mobile` — U4 header failed its own importer. Alias added (+`emailaddress`); caught by PHPUnit.
3. `get_records_select` keys by id, not userid — staff roster lines silently empty. Caught by Behat scenario asserting the rendered line; bulk fetch now re-keys by userid.

## Security / native / good-neighbour

- Admin page behind `admin_externalpage_setup` + system-context `:ingestattributes`; commit step sesskey-POST; CSV via core `csv_import_reader` (size/encoding handled by core filepicker + reader) ✔
- No writes to any core user field (asserted byte-identical in tests); no user creation path exists ✔
- Listing is core `table_sql` with native sort/paging/download (C12) ✔ · Site-user picker is core `core_user/form_user_selector` AMD — no custom transport ✔
- Cache declared in `db/caches.php` with lang string; observer registered declaratively ✔

**Gate: GREEN.** Slice 6 (quotas) may start.
