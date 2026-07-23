# Slice 2 audit — invitations

**Date:** 2026-07-24 · **Slice contents:** candidates service (course-level pool via `get_enrolled_sql(:respond)`, U3/S6 full name-field + email matching, per-candidate eligibility + reasons), gatekeeper `can_invite`/`can_accept`/`can_withdraw` with S2 state preconditions, invitations engine (send/accept/decline/withdraw/expire under group lock + transaction, A2 row reuse, §4A.4 acceptance cascade), 5 invitation events, notifier + 2 message providers, `expire_invitations` scheduled task, external fn `search_candidates` (sole custom AJAX, S5b-justified), AMD `candidateselector` (committed rollup build), invite form (core autocomplete), group-page invite/withdraw/respond UI, landing accept/decline, version 2026072401 + savepoint.

## Gate results

| Check | Result |
|---|---|
| phpcs `--max-warnings 0` (49 files) | PASS |
| phpdoc / mustache / eslint (grunt amd) | PASS |
| PHPUnit, 5.2/PostgreSQL | **PASS 27/27, 107 assertions** |
| PHPUnit, 5.2/MySQL | **PASS 27/27, 107 assertions** |
| Behat non-JS (8 scenarios ×2 features), m5pg/PostgreSQL | **PASS 96/96 steps** |
| Behat non-JS, m5my/MySQL | **PASS 96/96 steps** |
| Behat `@javascript` selector-by-email (U3), m5pg | **PASS 12/12 steps** (real Chrome via Selenium) |
| Behat `@javascript`, m5my | **PASS 12/12 steps** |

## §15.1 coverage delivered this slice

- **L2 trio** (below/at/above) via reserved seats; withdraw releases the seat ✔
- **Reserved-seat rule**: confirmed+pending ≤ eff. max at send and re-checked at accept (over-full loser refused) ✔
- **L4** invitee-cap block (a) = D2 at n=1; pending invitations don't count toward the invitee's own cap ✔
- **Acceptance cascade**: same-transaction auto-decline, reason `membershipcap` recorded in event, cascaded leaders notified ✔
- **Expiry**: task auto-declines past-window invitations, releases seats, events + both-party notices; younger invites untouched ✔
- **Decline always allowed** (even past cutoff) ✔ · **Re-invite reuses the row** (A2) ✔
- **S2 stale-POST guards**: invite + accept refused on `pending_guide` ✔
- **U3**: search matched by last name, email, first name in unit tests; by email in a real browser (@javascript) ✔
- **External wrapper guards**: non-leader refused (capability), foreign group id refused (IDOR) ✔

## Incidents during the gate (watchdog log)

1. **Missing comma in pending-invites SQL** (`group_page`): name-field selects concatenated without separator → exception on every group-page render; caught by Behat (4 scenarios), fixed, all green after.
2. **`externallib_advanced_testcase` not autoloaded** — requires `webservice/tests/helpers.php`; added.
3. **Redundant Behat step**: core's autocomplete field-setter already picks the matching suggestion; the extra "click on item" step failed on the already-closed list. Step removed — faildump screenshot confirmed the selector itself worked end-to-end.
4. Task `mtrace` output made a test risky → `expectOutputRegex` declared.

## §14.12 security checklist

- External fn: `validate_parameters`, `validate_context`, leader-or-`:manage` guard, activity-scoped group fetch (IDOR) ✔
- All POST actions `data_submitted() && confirm_sesskey()`; GETs render-only ✔
- Invitee-only respond actions act on `$USER->id` rows exclusively ✔
- Race-safety: lock + transaction + in-lock re-validation on send/accept/decline/withdraw/expire ✔
- Messaging via `message_send` with provider defaults; no direct email ✔

## Native components / good-neighbour

- Selector = core `autocomplete` element; the AMD module is transport-only (S5b justification recorded in plan §13) ✔
- AMD build reproduced via moodle-52 grunt (eslint clean, rollup output committed) ✔ · M4 grep clean ✔
- New DB artefacts registered via savepoint 2026072401; upgrade verified on 4 environments implicitly by behat/phpunit inits ✔ (4.5 static-checked; full 4.5 runtime legs covered by CI matrix)

**Gate: GREEN.** PHPUnit 27/27 ×2 DBs; Behat 9/9 scenarios ×2 DBs incl. JS selector flow. Slice 3 (succession) may start.
