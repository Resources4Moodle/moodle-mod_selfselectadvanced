# Slice 6 audit — composition quotas

**Date:** 2026-07-24 · **Slice contents:** full evaluator (value rules min/max, distinct rules, strict priority ordering, case-insensitive matching over confirmed-roster attributes, unknown-attribute surfacing, explicit deficiency wording), S1-safe rule store (priority uniqueness by full renumbering in a transaction — no unique index needed, identical on both DBs), quotas.php + quota_form (value picker fed by ingested values via grouped select per §4.7, hideIf per rule type), quota_rules template with POST reorder/delete, the live bucket panel (aria-live, icon+text markers per §14.14 — never colour alone) on group and review pages, submit/approve gates now consuming real rules, settings-navigation links (Quota rules / dashboards), behat page resolver class, generators, 32 lang strings.

## Gate results

| Check | Result |
|---|---|
| phpcs `--max-warnings 0` | PASS (0 findings) |
| PHPUnit, 5.2/PostgreSQL | **PASS 44/44, 227 assertions** |
| PHPUnit, 5.2/MySQL | **PASS 44/44, 227 assertions** |
| Behat non-JS (17 scenarios), m5pg/PostgreSQL | **PASS 231/231 steps** |
| Behat non-JS (17 scenarios), m5my/MySQL | **PASS 231/231 steps** |

## §15.1 coverage delivered this slice

- **Value-rule boundaries**: one below / exactly at / one above for both min and max sides, with worded deficiencies ("Needs 1 more from…", "Has N too many from…") ✔
- **Distinct rules**: current-distinct counting and deficiency ✔
- **Priority ordering**: report order follows manager priority regardless of creation order ✔
- **Missing attributes**: counted toward no value rule, surfaced as unknowncount (§8.1 flag-don't-crash) ✔
- **Gates a+b (§8.2)**: submission refused `refusalquota` while deficient, passes when satisfied; approval re-checks after submission when rules tighten ✔ (freeze gate c joins in slice 10)
- **S1 store**: append/moves/delete keep priorities unique 1..n; top-move no-op; gap closing ✔
- Behat: manager creates a rule from ingested values through the UI; leader watches the bucket flip from deficiency to Satisfied when the decisive member accepts — both DBs ✔

## Incidents during the gate (watchdog log)

1. **Root filesystem hit 100% mid-gate** (behat's PG DDL failed with "could not extend file… No space left on device"). Diagnosis: `/root/.vscode-server` had accumulated **16G** of Remote-SSH leftovers (8× ~490MB copies of one extension, ~15 stale IDE remote-server tool versions, 3.2G VSIX cache, 6 server builds). Cleaned strictly version-stale artifacts, keeping the running server build + running/newest extensions → **100% → 60%, 15G free**. All live services and the production DB verified healthy afterwards (`systemctl` + live-DB probe). The interrupted behat site re-initialised cleanly. Reported to the user for awareness: this is Remote-SSH churn and will regrow slowly; a periodic cleanup or `rm -rf ~/.vscode-server/data/CachedExtensionVSIXs` cron is worth considering.
2. Behat: "Add rule" is a link styled as a button (`I follow`, not `I press`); post-accept assertions belong on the redirect target. Both feature-file fixes.

## Security / native / good-neighbour

- quotas.php `:manage`-guarded; all mutations POST+sesskey; rule ids activity-scoped (IDOR) ✔
- Panel: core pix icons (i/checked, i/warning) with text alternatives; aria-live region ✔
- Value picker = core `selectgroups` element; reorder = plain POST forms; zero new JS ✔
- Store transactions; evaluator pure-read ✔

**Gate: GREEN.** Slice 7 (override subsystem write-path + UI + S3 regression re-run) may start.
