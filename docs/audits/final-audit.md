# Final self-audit — mod_selfselectadvanced 1.0.0 (2026-07-24)

> **Superseded in part — read this first (added 1.19.1, 2026-07-30).**
>
> This is the record of the 1.0.0 gate and is kept as written, because
> a gate record that gets edited afterwards is worth nothing. Three of
> its claims were later shown to be wrong, and are corrected in place
> below where they appear:
>
> - **"GETs render-only"** (§14.12) was **false when written**.
>   `departments.php` mutated data through GET links; an external audit
>   found it on 2026-07-30 and it was fixed in 1.19.1 (HIGH-SEC-001).
>   The checklist tick was based on a survey that missed that file.
> - **"every checklist item green; the build is complete"** overstated
>   what a green gate proves. The gate ran what tests existed; it could
>   not report on privacy exports, backup coverage or file lifecycle
>   that no test exercised. Defects in all three were later confirmed.
> - **The matrix figures** below are the 1.0.0 run and are correct for
>   it, but they are not evidence about any later release.
>
> What was actually found, accepted, rejected and repaired is recorded
> in `docs/audits/audit-response-1.19.md`, against the source, with the
> false criticals called out as false.

Consolidates the per-slice audits (slice-00 … slice-13, this folder).
Every §16 gate was recorded as passed before its successor began (C14);
the definitive pass criterion since the CI migration is the CI box's
`ci-run` verdict `### RESULT fail=0` = PHPUnit + Behat (incl.
@javascript) green on **both PostgreSQL and MariaDB** plus
phpcs/phpdoc/validate/savepoints. GitHub Actions additionally covers
the {Moodle 4.5 × PHP 8.2, Moodle 5.x × PHP 8.4} × {pgsql, mariadb}
matrix (workflow shipped; 4.5 install verified on the maintainer
testbeds in slice 0).

## Gate ledger

| Gate | Verdict | Evidence |
|---|---|---|
| 1 Architecture plan v1.1 | approved (review: B1–B5 folded, S/M assigned) | docs/reviews/gate1-review.md |
| Slices 0–13 | all GREEN | docs/audits/slice-*.md, one commit per gate |
| Final matrix (this release) | `fail=0` | 69 PHPUnit tests ×2 DBs; 29 Behat scenarios / 401 steps ×2 DBs |

## §14.12 security checklist (final pass)

- `require_login`/`require_course_login` + capability on every entry
  point (view, group, groupedit, review, guide, manage, moves,
  moveedit, quotas, overrides, ledger, flagged; attributes.php via
  `admin_externalpage_setup`) ✔
- `sesskey` on every state change; GETs render-only (destructive
  actions use confirm pages whose mutation is the POST) ✘ — **this tick
  was wrong.** `departments.php` deleted and reordered departments
  through GET links guarded by `sesskey` alone, so a prefetching
  browser or a crawler could fire them. Closed in 1.19.1: those
  controls are single-button POST forms and all three handlers require
  `data_submitted() && confirm_sesskey()`. A sweep of every root page
  at that time found no other GET mutation in the plugin.
- All input via `required_param`/`optional_param`/formslib with
  `PARAM_*`; CSV via core `csv_import_reader` with header/row
  validation ✔
- No SQL concatenation: `$DB` API, placeholders, `get_in_or_equal`,
  `sql_like`/`sql_equal` throughout (final grep clean; the two grep
  hits are a parameterised WHERE and event metadata) ✔
- Output escaped via Mustache/`format_string`/`format_text`/`s()` ✔
- IDOR: every group/member/invitation/move/override id fetched
  activity-scoped server-side ✔
- Race safety: named locks + transactions + in-lock revalidation on
  creation, acceptance, succession, submission, approval, moves,
  freeze/unfreeze, auto-grouping ✔

## Good-neighbour (§14.5) and native components (C9)

- All artefacts prefixed `selfselectadvanced`; no core/third-party
  files touched; core groups only via the official API; ownership
  tracked by `coregroupid`; drift reported, never overwritten;
  uninstall leaves frozen core groups (README-documented) ✔
- M4 grep final run: 0 CDN/vendor references, 0 inline
  `<script>`/`<style>` in templates, no package.json ✔
- Third-party libraries: **none**. One AMD module
  (`candidateselector`), justified in plan §13/S5b as pure transport;
  styles.css contains structural selectors only (S5a) ✔

## Standing positions (documented, deliberate)

- **S7**: email *matching* available to all inviters (U3 requires it);
  display identity-gated; README privacy statement records the
  residual disclosure.
- **C12**: primary listings (dashboard groups, attributes, ledger) are
  core `table_sql` with sort/paging/download. The small
  quota/override config lists and the interactive moves selection form
  render via templates; the moves page is a selection *form*, and the
  config lists are bounded manager tooling — recorded here as an
  accepted position rather than silent deviation.
- **Provider/task version bumps**: missed twice mid-build, both caught
  by gates; every db/* addition now pairs with a savepoint (verified
  complete: 2026072400 → 2026072410).
- Behat @javascript scenarios run on the CI box and GitHub Actions;
  the production dev box is memory-limited for Selenium (documented in
  slices 3/10).

## Traceability

The plan's §18 requirement→component table stands; every §§4–15 spec
clause maps to shipped code and at least one test: limits L1–L5
boundary trios + counting bases (slices 1–4, 7), reserved seats +
cascade (2), succession atomicity (3), guide-slot release + L5 races
(4), ingest rules (5), quota ordering (6), P1–P16 + S3 re-run (7),
joint move sets + bypasses (8), penalty math incl. P16/B2 (9),
snapshot/drift/A6 (10), bulk + dashboards (11), B1/B4/cascade
determinism (12), privacy/backup/leave/reminder (13).

**Verdict (1.0.0, as recorded at the time): every checklist item green;
the build is complete per §16 and packaged for the Moodle Plugins
Directory (version 1.0.0, MATURITY_STABLE, GPL v3+, screenshots to be
captured from the browsable CI instance at release upload).**

**Correction (1.19.1).** "Every checklist item green" claimed more than
the evidence supported. A green gate means the tests that exist passed;
it says nothing about behaviour no test covers, and the security tick
above was simply incorrect. Later work confirmed defects in the privacy
export, in backup coverage and in the proposal-file lifecycle, none of
which any test then exercised. The maturity level is unchanged because
the defects found were repaired and are now covered by tests — but the
reasoning that produced this verdict was unsound, and stating that
plainly matters more than the level itself.
